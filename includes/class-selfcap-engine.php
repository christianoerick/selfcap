<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The captcha itself: issues the proof-of-work challenge, checks the solution
 * plus the browser signals, hands out a one-time token and validates it.
 *
 * Challenge and token are stateless signed payloads (HMAC); only "already
 * used" and the counters live in the database.
 *
 * Every public step takes a `$context` describing the request, so it can be
 * tested without HTTP:
 *   ip, user_agent, accept_language (bool), hostname (Origin/Referer host or null)
 */
class Selfcap_Engine
{
    private const TYPE_CHALLENGE = 'c';

    private const TYPE_TOKEN = 'k';

    private const BOT_AGENTS = '/headless|phantomjs|puppeteer|playwright|selenium|webdriver|python-requests|python-urllib|curl\/|wget\/|go-http-client|okhttp|scrapy|node-fetch|axios\/|libwww|httpclient/i';

    /** @var array */
    private $config;

    /** @var string */
    private $key;

    /** @var string[] Hostnames the widget may run on. */
    private $hostnames;

    public function __construct(array $config, string $key, array $hostnames)
    {
        $this->config = $config;
        $this->key = hash_hmac('sha256', 'captcha-signing-v1', $key, true);
        $this->hostnames = array_map([self::class, 'bare_host'], $hostnames);
    }

    // ---- Step 1: the widget asks for a challenge ------------------------

    /** @return array{0: int, 1: array} HTTP status and JSON body. */
    public function challenge(array $context): array
    {
        $ip = $context['ip'];

        if (! $this->allows_hostname($context['hostname'])) {
            return $this->error('domain-not-allowed', 403);
        }

        if (Selfcap_Store::check_ip($ip) === 'block') {
            $this->log_blocked($context);

            return $this->error('blocked', 403);
        }

        if ($this->too_many('challenge', $ip)) {
            $this->record('limited', $ip);

            return $this->error('rate-limited', 429);
        }

        // Adaptive difficulty: the more challenges an IP asks for, the more each one costs.
        // A private address is the proxy in front, shared by every visitor: no per-IP bonus.
        $recent = Selfcap_IP::is_private($ip) ? 0 : Selfcap_Store::hit('adaptive:'.Selfcap_IP::range_for($ip), 600);
        $bonus = min((int) $this->config['adaptive_max_bonus'], intdiv($recent, (int) $this->config['adaptive_step']));
        $difficulty = max(
            (int) $this->config['min_difficulty'],
            min((int) $this->config['max_difficulty'], (int) $this->config['difficulty'] + $bonus)
        );

        $now = time();
        $payload = [
            't' => self::TYPE_CHALLENGE,
            'n' => bin2hex(random_bytes(16)),
            'd' => $difficulty,
            'i' => $now,
            'e' => $now + (int) $this->config['challenge_ttl'],
            'ip' => $this->ip_hash($ip),
        ];

        Selfcap_Store::increment('challenges');

        return [200, [
            'success' => true,
            'challenge' => $this->sign($payload),
            'salt' => $payload['n'],
            'difficulty' => $difficulty,
            'expires_in' => (int) $this->config['challenge_ttl'],
        ]];
    }

    // ---- Step 2: solution + browser signals + click ---------------------

    /** @return array{0: int, 1: array} HTTP status and JSON body. */
    public function verify(array $context, $challenge, $nonce, $signals): array
    {
        $ip = $context['ip'];
        $payload = $this->parse($challenge, self::TYPE_CHALLENGE);

        // An unsigned or garbage challenge costs nothing to send, so it is not
        // counted: otherwise anyone could get a shared address blocked for free.
        if ($payload === null) {
            return $this->error('invalid-challenge', 400);
        }

        if ((int) $payload['e'] < time()) {
            return $this->error('challenge-expired', 400);
        }

        if (! $this->allows_hostname($context['hostname'])) {
            return $this->error('domain-not-allowed', 403);
        }

        if (Selfcap_Store::check_ip($ip) === 'block') {
            $this->log_blocked($context);

            return $this->error('blocked', 403);
        }

        if ($this->too_many('verify', $ip)) {
            $this->record('limited', $ip);

            return $this->error('rate-limited', 429);
        }

        if (! hash_equals((string) $payload['ip'], $this->ip_hash($ip))) {
            return $this->error('ip-mismatch', 400);
        }

        $signals = is_array($signals) ? $signals : [];
        $solve_ms = isset($signals['pow']) ? max(0, min((int) $signals['pow'], 3600000)) : null;

        if (! self::check_proof_of_work((string) $payload['n'], is_scalar($nonce) ? (string) $nonce : '', (int) $payload['d'])) {
            Selfcap_Store::log($context, 'failed', 'invalid-solution', 0, $solve_ms);
            $this->record('bad', $ip);

            return $this->error('invalid-solution', 400);
        }

        // A solved challenge is good for a single attempt, pass or fail.
        if (! Selfcap_Store::consume('c:'.$payload['n'], (int) $payload['e'])) {
            $this->record('bad', $ip);

            return $this->error('challenge-used', 400);
        }

        $result = $this->score($signals, $context);

        if ($result['score'] >= (int) $this->config['score_block']) {
            Selfcap_Store::log($context, 'failed', implode(', ', $result['reasons']), $result['score'], $solve_ms);
            $this->record('fail', $ip);

            if (array_intersect($result['reasons'], ['webdriver', 'bot-user-agent']) !== []) {
                $this->record('bot', $ip);
            }

            return $this->error('verification-failed', 403);
        }

        Selfcap_Store::log($context, 'passed', $result['reasons'] === [] ? null : implode(', ', $result['reasons']), $result['score'], $solve_ms);
        $this->record('pass', $ip);

        $now = time();

        return [200, [
            'success' => true,
            'token' => $this->sign([
                't' => self::TYPE_TOKEN,
                'j' => bin2hex(random_bytes(16)),
                'i' => $now,
                'e' => $now + (int) $this->config['token_ttl'],
                'h' => $context['hostname'],
                'sc' => $result['score'],
                'ip' => $this->ip_hash($ip),
            ]),
            'expires_in' => (int) $this->config['token_ttl'],
        ]];
    }

    // ---- Step 3: the form handler validates the token -------------------

    /**
     * True when the token is genuine, not expired, never used and, unless
     * `bind_token_ip` is off, presented from the address that solved it.
     * An error code otherwise.
     *
     * Binding the token to the solver's address is what stops tokens solved
     * by people elsewhere (a solver farm) from being replayed by a bot.
     */
    public function validate_token($token, string $ip)
    {
        if (! is_string($token) || $token === '') {
            return 'missing-input-response';
        }

        $payload = $this->parse($token, self::TYPE_TOKEN);

        if ($payload === null) {
            return 'invalid-input-response';
        }

        // A blocked address cannot use a token solved somewhere else.
        if (Selfcap_Store::check_ip($ip) === 'block') {
            return 'invalid-input-response';
        }

        // Checked before the token is spent, so a token stolen in transit
        // cannot be burned from another address.
        // Not counted as an attack: a visitor behind a relay (iCloud Private
        // Relay, IPv4/IPv6 switch) can land here by accident.
        if (! empty($this->config['bind_token_ip']) && ! hash_equals((string) ($payload['ip'] ?? ''), $this->ip_hash($ip))) {
            return 'invalid-input-response';
        }

        if ((int) $payload['e'] < time() || ! Selfcap_Store::consume('k:'.$payload['j'], (int) $payload['e'])) {
            return 'timeout-or-duplicate';
        }

        Selfcap_Store::increment('validated');

        return true;
    }

    // ---- Proof of work --------------------------------------------------

    /** SHA-256(salt . nonce) must start with `difficulty` zero bits. */
    public static function check_proof_of_work(string $salt, string $nonce, int $difficulty): bool
    {
        if (! preg_match('/^\d{1,16}$/', $nonce)) {
            return false;
        }

        $hash = hash('sha256', $salt.$nonce, true);
        $bits = 0;

        foreach (str_split($hash) as $char) {
            $byte = ord($char);

            if ($byte === 0) {
                $bits += 8;

                continue;
            }

            $bits += 8 - strlen(decbin($byte));

            break;
        }

        return $bits >= $difficulty;
    }

    // ---- Signal score ---------------------------------------------------

    /**
     * Turns the browser signals plus the request headers into a suspicion
     * score. Every signal can be forged by a dedicated bot; the score only
     * filters generic automation. The proof-of-work, the rate limit and the
     * one-time token are what make abuse expensive.
     *
     * @return array{score: int, reasons: string[]}
     */
    public function score(array $signals, array $context): array
    {
        $score = 0;
        $reasons = [];

        $add = function (int $points, string $reason) use (&$score, &$reasons): void {
            $score += $points;
            $reasons[] = $reason;
        };

        $header_agent = (string) $context['user_agent'];
        $client_agent = is_string($signals['ua'] ?? null) ? $signals['ua'] : '';

        if (($signals['wd'] ?? false) === true) {
            $add(100, 'webdriver');
        }

        if ($header_agent === '' || preg_match(self::BOT_AGENTS, $header_agent) || preg_match(self::BOT_AGENTS, $client_agent)) {
            $add(100, 'bot-user-agent');
        }

        if ($client_agent !== $header_agent) {
            $add(50, 'user-agent-mismatch');
        }

        if (empty($context['accept_language'])) {
            $add(30, 'no-accept-language');
        }

        if (self::int($signals, 'lang') === 0) {
            $add(30, 'no-languages');
        }

        if (self::int($signals, 'sw') === 0 || self::int($signals, 'sh') === 0) {
            $add(30, 'no-screen');
        }

        if (self::int($signals, 'ow') === 0 && self::int($signals, 'oh') === 0) {
            $add(20, 'no-window');
        }

        if (strpos($header_agent, 'Chrome/') !== false && ($signals['chr'] ?? false) !== true) {
            $add(30, 'fake-chrome');
        }

        $click = is_array($signals['click'] ?? null) ? $signals['click'] : null;

        if ($click === null || ($click['trusted'] ?? false) !== true) {
            $add(100, 'untrusted-click');
        } else {
            if (self::int($click, 'dt') < (int) $this->config['min_click_ms']) {
                $add(100, 'click-too-fast');
            }

            if (($click['type'] ?? '') === 'mouse' && self::int($click, 'moves') < 2) {
                $add(40, 'no-pointer-movement');
            }
        }

        $events = is_array($signals['ev'] ?? null) ? $signals['ev'] : [];
        $total = 0;

        foreach ($events as $value) {
            $total += is_scalar($value) ? (int) $value : 0;
        }

        if ($total === 0) {
            $add(20, 'no-events');
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    // ---- Automatic blocking ---------------------------------------------

    /**
     * Counts one event (fail, pass, bad, bot, limited) for the IP and blocks it
     * only when the volume looks like an attack.
     *
     * A customer who fails once or twice never gets here: every trigger needs a
     * volume no person produces by hand, the "fail" trigger is cancelled by
     * approved verifications from the same IP (shared office or mobile carrier
     * addresses), and every block is temporary, growing only for repeat offenders.
     */
    public function record(string $event, string $ip): void
    {
        $config = $this->config['auto_block'];

        // A private address means the proxy in front is not being recognised:
        // blocking it would block every visitor at once.
        if (empty($config['enabled']) || Selfcap_IP::is_private($ip)) {
            return;
        }

        $range = Selfcap_IP::range_for($ip);
        $count = Selfcap_Store::hit("attack:{$event}:{$range}", (int) $config['window']);
        $reason = $this->verdict($event, $count, $range, $config);

        if ($reason === null) {
            return;
        }

        // An allow rule protects the IP; an active block needs no second rule.
        if (Selfcap_Store::check_ip($ip) !== null || Selfcap_Store::hit('attack:lock:'.$range, 30) !== 1) {
            return;
        }

        $durations = array_values((array) $config['durations']);
        $previous = Selfcap_Store::recent_auto_blocks($range, (int) $config['memory_days']);
        $minutes = (int) $durations[min($previous, count($durations) - 1)];

        Selfcap_Store::add_rule('block', $range, $reason, 'auto', time() + $minutes * 60);

        // An attacker with a whole IPv6 allocation hops from one /64 to the next:
        // after many of them, block the /48 they share. Only for evidence that
        // costs real work (automation, refused or solved verifications, failed
        // logins), so cheap requests from one customer never hit their neighbours.
        $wide = Selfcap_IP::wide_range($ip);

        if ($wide !== null && in_array($event, ['bot', 'fail', 'pass', 'login_fail'], true) && Selfcap_Store::hit('attack:wide:'.$wide, DAY_IN_SECONDS) >= (int) $config['wide_after'] && ! Selfcap_Store::has_auto_block($wide)) {
            Selfcap_Store::add_rule('block', $wide, sprintf('%d blocked /64 ranges inside this /48', (int) $config['wide_after']), 'auto', time() + $minutes * 60);
        }
    }

    private function verdict(string $event, int $count, string $range, array $config): ?string
    {
        $minutes = (int) round($config['window'] / 60);

        if ($event === 'pass') {
            return $count >= $config['passes'] ? "{$count} verifications solved in {$minutes} min (solver suspected)" : null;
        }

        $thresholds = [
            'bad' => [$config['bad_solutions'], '%d wrong or replayed solutions'],
            'bot' => [$config['bot_signals'], 'Browser automation detected %d times'],
            'login_fail' => [$config['login_fails'], '%d failed logins'],
            'limited' => [$config['rate_limited'], 'Request flood: %d requests over the rate limit'],
            'fail' => [$config['fails'], '%d refused verifications'],
        ];

        if (! isset($thresholds[$event]) || $count < $thresholds[$event][0]) {
            return null;
        }

        $reason = sprintf($thresholds[$event][1], $count)." in {$minutes} min";

        // Wrong solutions, automation and failed logins block outright: a bot
        // could otherwise earn approvals to shield its own address.
        if (! in_array($event, ['fail', 'limited'], true)) {
            return $reason;
        }

        // Real people share addresses (carrier NAT, offices, schools) and can be
        // refused or rate limited in numbers: approved verifications from the
        // same address in the window hold these two back.
        $passes = Selfcap_Store::count("attack:pass:{$range}");

        return $passes * $config['fail_ratio'] > $count ? null : $reason." against {$passes} approved";
    }

    // ---- Internals ------------------------------------------------------

    private function allows_hostname($hostname): bool
    {
        return is_string($hostname) && $hostname !== '' && in_array(self::bare_host($hostname), $this->hostnames, true);
    }

    /** example.com and www.example.com are the same site. */
    private static function bare_host(string $host): string
    {
        $host = strtolower(trim($host));

        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    private function too_many(string $action, string $ip): bool
    {
        // A private address is the proxy in front, shared by every visitor: a
        // per-IP limit there would let one attacker shut the forms for everyone.
        if (Selfcap_IP::is_private($ip)) {
            return false;
        }

        // Per /64 on IPv6: one machine owns the whole prefix and could
        // otherwise rotate addresses to get a fresh limit on every request.
        return Selfcap_Store::hit("rate:{$action}:".Selfcap_IP::range_for($ip), 60) > (int) $this->config['rate'][$action];
    }

    /** A blocked IP hammering the API writes one log line per minute, not one per request. */
    private function log_blocked(array $context): void
    {
        if (Selfcap_Store::hit('blocked-log:'.$context['ip'], 60) === 1) {
            Selfcap_Store::log($context, 'blocked', 'block-rule');
        } else {
            Selfcap_Store::increment('blocked');
        }
    }

    /** Challenges and tokens are bound to the address, or to its /64 on IPv6 (privacy addresses rotate). */
    private function ip_hash(string $ip): string
    {
        return substr(hash_hmac('sha256', Selfcap_IP::range_for($ip), $this->key), 0, 16);
    }

    private function sign(array $payload): string
    {
        $body = self::base64url_encode((string) wp_json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $body.'.'.$this->signature($body);
    }

    private function signature(string $body): string
    {
        return self::base64url_encode(hash_hmac('sha256', $body, $this->key, true));
    }

    /** Returns the payload when the signature and type match, null otherwise. Expiry is checked by the caller. */
    private function parse($signed, string $type): ?array
    {
        if (! is_string($signed) || strlen($signed) > 2048 || substr_count($signed, '.') !== 1) {
            return null;
        }

        [$body, $signature] = explode('.', $signed);

        if (! hash_equals($this->signature($body), $signature)) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);

        if (! is_array($payload) || ($payload['t'] ?? null) !== $type) {
            return null;
        }

        return $payload;
    }

    private static function base64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function int(array $values, string $key): int
    {
        return isset($values[$key]) && is_scalar($values[$key]) ? (int) $values[$key] : 0;
    }

    private function error(string $code, int $status): array
    {
        return [$status, ['success' => false, 'error' => $code]];
    }
}
