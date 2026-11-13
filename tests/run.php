<?php
/**
 * Integration tests. They empty the captcha tables, so run them on a test site only:
 *
 *     wp eval-file wp-content/plugins/self-captcha-standalone/tests/run.php
 */

if (! defined('ABSPATH') || ! class_exists('Selfcap_Engine')) {
    exit("Run with: wp eval-file <this file>, with the plugin active.\n");
}

$failures = 0;
$checks = 0;

$check = function (string $name, $actual, $expected) use (&$failures, &$checks): void {
    $checks++;

    if ($actual !== $expected) {
        $failures++;
        echo "FAIL  {$name}\n      expected ".var_export($expected, true)."\n      got      ".var_export($actual, true)."\n";
    }
};

$wipe = function (): void {
    global $wpdb;

    foreach (['counters', 'log', 'stats', 'rules'] as $table) {
        $wpdb->query('TRUNCATE TABLE '.Selfcap_Store::table($table));
    }

    // Drops the per-request rule cache.
    Selfcap_Store::delete_rule(0);
};

$config = Selfcap_Plugin::config();
$config['difficulty'] = 10;
$config['auto_block']['enabled'] = true;

$engine = new Selfcap_Engine($config, 'test-key', ['shop.test']);

$agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

$context = function (string $ip = '203.0.113.10', ?string $hostname = 'shop.test') use ($agent): array {
    return ['ip' => $ip, 'user_agent' => $agent, 'accept_language' => true, 'hostname' => $hostname];
};

$human = function (array $override = []) use ($agent): array {
    return array_replace_recursive([
        'wd' => false, 'ua' => $agent, 'lang' => 2, 'sw' => 1920, 'sh' => 1080, 'ow' => 1400, 'oh' => 900, 'chr' => true,
        'pow' => 120, 'ev' => ['m' => 40, 'k' => 0, 't' => 0, 's' => 2],
        'click' => ['trusted' => true, 'dt' => 1800, 'moves' => 25, 'type' => 'mouse'],
    ], $override);
};

$solve = function (string $salt, int $difficulty): string {
    for ($nonce = 0; ; $nonce++) {
        if (Selfcap_Engine::check_proof_of_work($salt, (string) $nonce, $difficulty)) {
            return (string) $nonce;
        }
    }
};

/** Challenge + solve + verify. Returns the verify result. */
$attempt = function (array $ctx, array $signals) use ($engine, $solve): array {
    [$status, $body] = $engine->challenge($ctx);

    if ($status !== 200) {
        return [$status, $body];
    }

    return $engine->verify($ctx, $body['challenge'], $solve($body['salt'], $body['difficulty']), $signals);
};

// ---- Full flow ----------------------------------------------------------
$wipe();
[$status, $issued] = $engine->challenge($context());
$check('challenge is issued', [$status, $issued['success'], $issued['difficulty']], [200, true, 10]);

$nonce = $solve($issued['salt'], $issued['difficulty']);
[$status, $verified] = $engine->verify($context(), $issued['challenge'], $nonce, $human());
$check('human is approved', [$status, $verified['success']], [200, true]);
$check('token validates once', $engine->validate_token($verified['token'], '203.0.113.10'), true);
$check('token is refused the second time', $engine->validate_token($verified['token'], '203.0.113.10'), 'timeout-or-duplicate');

[$status, $again] = $engine->verify($context(), $issued['challenge'], $nonce, $human());
$check('a solved challenge cannot be reused', [$status, $again['error']], [400, 'challenge-used']);

$stats = $GLOBALS['wpdb']->get_row('SELECT * FROM '.Selfcap_Store::table('stats'), ARRAY_A);
$check('daily totals', [(int) $stats['challenges'], (int) $stats['passed'], (int) $stats['validated']], [1, 1, 1]);
$check('log line written', (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '.Selfcap_Store::table('log')." WHERE result = 'passed' AND ip = '203.0.113.10'"), 1);

// ---- Forgery ------------------------------------------------------------
$wipe();
[, $issued] = $engine->challenge($context());
[$status, $body] = $engine->verify($context(), $issued['challenge'], 'abc', $human());
$check('wrong solution is refused', [$status, $body['error']], [400, 'invalid-solution']);

[$status, $body] = $engine->verify($context(), $issued['challenge'].'x', '1', $human());
$check('tampered challenge is refused', [$status, $body['error']], [400, 'invalid-challenge']);

[$status, $body] = $engine->verify($context('203.0.113.99'), $issued['challenge'], $solve($issued['salt'], 10), $human());
$check('challenge is bound to the IP', [$status, $body['error']], [400, 'ip-mismatch']);

$check('garbage token', $engine->validate_token('not.a-token', '203.0.113.10'), 'invalid-input-response');
$check('empty token', $engine->validate_token('', '203.0.113.10'), 'missing-input-response');
$check('a challenge is not a token', $engine->validate_token($issued['challenge'], '203.0.113.10'), 'invalid-input-response');

$other = new Selfcap_Engine($config, 'another-site-key', ['shop.test']);
[, $verified] = $attempt($context('203.0.113.11'), $human());
$check('token signed by another site is refused', $other->validate_token($verified['token'], '203.0.113.11'), 'invalid-input-response');

// ---- Token bound to the solver's address --------------------------------
$wipe();
[, $verified] = $attempt($context('203.0.113.12'), $human());
$check('token from another address is refused', $engine->validate_token($verified['token'], '198.51.100.99'), 'invalid-input-response');
$check('...and was not spent by that attempt', $engine->validate_token($verified['token'], '203.0.113.12'), true);
[, $verified] = $attempt($context('2001:db8:9:9::1'), $human());
$check('IPv6 privacy address in the same /64 is accepted', $engine->validate_token($verified['token'], '2001:db8:9:9:abcd::77'), true);
[, $verified] = $attempt($context('2001:db8:9:9::1'), $human());
$check('IPv6 from another /64 is refused', $engine->validate_token($verified['token'], '2001:db8:9:a::1'), 'invalid-input-response');
$loose = $config;
$loose['bind_token_ip'] = false;
$unbound = new Selfcap_Engine($loose, 'test-key', ['shop.test']);
[, $verified] = $attempt($context('203.0.113.13'), $human());
$check('binding can be switched off', $unbound->validate_token($verified['token'], '198.51.100.99'), true);

// ---- Hostname -----------------------------------------------------------
$wipe();
$check('www variant is the same site', $engine->challenge($context('203.0.113.10', 'www.shop.test'))[0], 200);
$check('foreign site is refused', $engine->challenge($context('203.0.113.10', 'evil.test'))[1]['error'], 'domain-not-allowed');
$check('no origin is refused', $engine->challenge($context('203.0.113.10', null))[1]['error'], 'domain-not-allowed');

// ---- Signals ------------------------------------------------------------
$wipe();
$check('webdriver is refused', $attempt($context(), $human(['wd' => true]))[1]['error'], 'verification-failed');
$check('instant click is refused', $attempt($context(), $human(['click' => ['dt' => 40]]))[1]['error'], 'verification-failed');
$check('scripted click is refused', $attempt($context(), $human(['click' => ['trusted' => false]]))[1]['error'], 'verification-failed');
$check('keyboard user is approved', $attempt($context(), $human(['click' => ['type' => 'key', 'moves' => 0], 'ev' => ['m' => 0, 'k' => 6]]))[1]['success'], true);
$check('touch user is approved', $attempt($context(), $human(['click' => ['type' => 'touch', 'moves' => 0], 'ev' => ['m' => 0, 't' => 3]]))[1]['success'], true);

$curl = $context();
$curl['user_agent'] = 'curl/8.4.0';
$check('bot user agent is refused', $attempt($curl, $human(['ua' => 'curl/8.4.0']))[1]['error'], 'verification-failed');

// ---- Manual rules -------------------------------------------------------
$wipe();
Selfcap_Store::add_rule('block', '198.51.100.0/24', 'test');
$check('blocked range gets no challenge', $engine->challenge($context('198.51.100.7'))[1]['error'], 'blocked');
$check('neighbour range is free', $engine->challenge($context('198.51.101.7'))[0], 200);
Selfcap_Store::add_rule('allow', '198.51.100.7', 'office');
$check('allow wins over block', $engine->challenge($context('198.51.100.7'))[0], 200);
Selfcap_Store::add_rule('block', '198.51.101.7', 'old', 'manual', time() - 10);
$check('expired rule is ignored', $engine->challenge($context('198.51.101.7'))[0], 200);

// ---- Rate limit and adaptive difficulty ---------------------------------
$wipe();
$difficulties = [];

for ($i = 1; $i <= 31; $i++) {
    [$status, $body] = $engine->challenge($context('203.0.113.20'));
    $difficulties[$i] = $body['difficulty'] ?? null;
}

$check('difficulty grows with volume', [$difficulties[1], $difficulties[10], $difficulties[30]], [10, 11, 13]);
$check('31st challenge in a minute is rate limited', [$status, $body['error']], [429, 'rate-limited']);
$check('another IP is not affected', $engine->challenge($context('203.0.113.21'))[0], 200);

// IPv6: rotating addresses inside one /64 does not reset the limit.
$wipe();

for ($i = 1; $i <= 31; $i++) {
    [$status, $body] = $engine->challenge($context('2001:db8:5:5::'.dechex($i)));
    $difficulties[$i] = $body['difficulty'] ?? null;
}

$check('IPv6 /64 shares one rate limit', [$status, $body['error'] ?? null], [429, 'rate-limited']);
$check('IPv6 /64 shares the adaptive difficulty', $difficulties[30], 13);

// ---- Automatic blocking -------------------------------------------------
$rules = function (string $where = '1 = 1'): array {
    return (array) $GLOBALS['wpdb']->get_results('SELECT * FROM '.Selfcap_Store::table('rules')." WHERE {$where} ORDER BY id");
};

// A wrong solution to a genuine challenge (what a forging bot sends).
$wrong = function (string $ip) use ($engine, $context, $human): void {
    [$status, $issued] = $engine->challenge($context($ip));

    if ($status === 200) {
        $engine->verify($context($ip), $issued['challenge'], 'not-a-number', $human());
    }
};

$wipe();

for ($i = 0; $i < 9; $i++) {
    $wrong('203.0.113.30');
}

$check('9 wrong solutions do not block', count($rules()), 0);
$wrong('203.0.113.30');
$auto = $rules();
$check('10 wrong solutions block the IP', [count($auto), $auto[0]->source ?? null, $auto[0]->ip_range ?? null, $auto[0]->type ?? null], [1, 'auto', '203.0.113.30', 'block']);
$check('first block lasts 15 minutes', (int) round((($auto[0]->expires_at ?? 0) - time()) / 60), 15);
$check('the blocked IP is refused', $engine->challenge($context('203.0.113.30'))[1]['error'], 'blocked');

// Second offence within the week: one hour.
$GLOBALS['wpdb']->query('UPDATE '.Selfcap_Store::table('rules').' SET expires_at = '.(time() - 5));
$GLOBALS['wpdb']->query('TRUNCATE TABLE '.Selfcap_Store::table('counters'));
Selfcap_Store::delete_rule(0);

for ($i = 0; $i < 10; $i++) {
    $wrong('203.0.113.30');
}

$auto = $rules('expires_at > '.time());
$check('second block lasts 1 hour', [count($auto), (int) round((($auto[0]->expires_at ?? 0) - time()) / 60)], [1, 60]);

// Garbage costs nothing to send, so it never counts: nobody can get a shared address blocked for free.
$wipe();

for ($i = 0; $i < 30; $i++) {
    $engine->verify($context('203.0.113.31'), 'garbage.challenge', '1', $human());
}

$check('garbage challenges never block', count($rules()), 0);

// A customer failing a few times is never blocked.
$wipe();

for ($i = 0; $i < 7; $i++) {
    $attempt($context('203.0.113.40'), $human(['click' => ['dt' => 40]]));
}

$check('7 refused verifications do not block', count($rules()), 0);

// Shared IP: approved verifications hold the block back.
$wipe();

for ($i = 0; $i < 3; $i++) {
    $attempt($context('203.0.113.41'), $human());
}

for ($i = 0; $i < 9; $i++) {
    $attempt($context('203.0.113.41'), $human(['click' => ['dt' => 40]]));
}

$check('shared IP with approvals is not blocked', count($rules()), 0);

// Refusals only, at attack volume.
$wipe();

for ($i = 0; $i < 8; $i++) {
    $attempt($context('203.0.113.42'), $human(['click' => ['dt' => 40]]));
}

$check('8 refusals with no approval block the IP', count($rules()), 1);

// Browser automation.
$wipe();

for ($i = 0; $i < 4; $i++) {
    $attempt($context('203.0.113.43'), $human(['wd' => true]));
}

$check('4 automation hits do not block', count($rules()), 0);
$attempt($context('203.0.113.43'), $human(['wd' => true]));
$check('5 automation hits block the IP', count($rules()), 1);

// A bot cannot shield its address by solving some captchas first.
$wipe();

for ($i = 0; $i < 15; $i++) {
    $attempt($context('203.0.113.45'), $human());
}

for ($i = 0; $i < 10; $i++) {
    $wrong('203.0.113.45');
}

$check('approvals do not hold back wrong solutions', count($rules()), 1);

// Failed logins (wrong password) count, captcha solved or not.
$wipe();

for ($i = 0; $i < 19; $i++) {
    $engine->record('login_fail', '203.0.113.46');
}

$check('19 failed logins do not block', count($rules()), 0);
$engine->record('login_fail', '203.0.113.46');
$check('20 failed logins block the IP', count($rules()), 1);

// Private addresses and allow rules are never blocked.
$wipe();

for ($i = 0; $i < 12; $i++) {
    $wrong('10.0.0.5');
}

$check('private address is never blocked', count($rules()), 0);

Selfcap_Store::add_rule('allow', '203.0.113.50', 'office');

for ($i = 0; $i < 12; $i++) {
    $wrong('203.0.113.50');
}

$check('allowed address is never blocked', count($rules("type = 'block'")), 0);

// IPv6 is judged by its /64.
$wipe();

for ($i = 0; $i < 10; $i++) {
    $wrong('2001:db8:1:2::'.dechex($i + 1));
}

$auto = $rules();
$check('IPv6 is blocked by /64', [count($auto), $auto[0]->ip_range ?? null], [1, '2001:db8:1:2::/64']);
$check('same /64, other address, is refused', $engine->challenge($context('2001:db8:1:2:ffff::9'))[1]['error'], 'blocked');
$check('another /64 is free', $engine->challenge($context('2001:db8:1:3::1'))[0], 200);

// IPv6: cheap evidence (wrong solutions) from many /64s never blocks the neighbours' /48.
$wipe();

for ($net = 1; $net <= 20; $net++) {
    for ($i = 0; $i < 10; $i++) {
        $wrong('2001:db8:66:'.dechex($net).'::'.dechex($i + 1));
    }
}

$check('wrong solutions never escalate to the /48', count($rules("ip_range = '2001:db8:66::/48'")), 0);

// IPv6: automation from 16 /64s of one /48 blocks the /48.
$wipe();

for ($net = 1; $net <= 16; $net++) {
    for ($i = 0; $i < 5; $i++) {
        $attempt($context('2001:db8:77:'.dechex($net).'::'.dechex($i + 1)), $human(['wd' => true]));
    }

    if ($net === 15) {
        $check('15 blocked /64s leave the /48 alone', count($rules("ip_range = '2001:db8:77::/48'")), 0);
    }
}

$check('16 blocked /64s block their /48', count($rules("ip_range = '2001:db8:77::/48'")), 1);
$check('a fresh /64 in that /48 is refused', $engine->challenge($context('2001:db8:77:ff::1'))[1]['error'] ?? null, 'blocked');
$check('another /48 is free', $engine->challenge($context('2001:db8:78::1'))[0], 200);

// Lookups stay right with thousands of automatic rules in the table.
$wipe();
$values = [];

for ($i = 0; $i < 3000; $i++) {
    $values[] = $GLOBALS['wpdb']->prepare('(%s, %s, %s, %d, %d)', 'block', '2001:db8:'.dechex(intdiv($i, 256)).':'.dechex($i % 256).'::/64', 'auto', time(), time() + 600);
}

$GLOBALS['wpdb']->query('INSERT INTO '.Selfcap_Store::table('rules').' (type, ip_range, source, created_at, expires_at) VALUES '.implode(',', $values)); // phpcs:ignore
Selfcap_Store::add_rule('block', '198.51.100.0/24', 'manual range');
$started = microtime(true);
$check('auto rule found among 3,000', Selfcap_Store::check_ip('2001:db8:b:b7::42'), 'block');
$check('manual CIDR still matched', Selfcap_Store::check_ip('198.51.100.200'), 'block');
$check('unrelated address is free', Selfcap_Store::check_ip('203.0.113.99'), null);
$check('lookups are fast', microtime(true) - $started < 0.5, true);

// A blocked address cannot use a token solved somewhere else.
$wipe();
[, $verified] = $attempt($context('203.0.113.80'), $human());
Selfcap_Store::add_rule('block', '203.0.113.80', 'blocked after solving');
$check('token presented from a blocked address is refused', $engine->validate_token($verified['token'], '203.0.113.80'), 'invalid-input-response');

// Behind an unrecognised proxy everyone shares one private address: no per-IP limit there.
$wipe();

for ($i = 1; $i <= 40; $i++) {
    [$status, $body] = $engine->challenge($context('10.0.0.9'));
}

$check('private address is not rate limited', [$status, $body['difficulty'] ?? null], [200, 10]);

// Switched off.
$wipe();
$off = $config;
$off['auto_block']['enabled'] = false;
$quiet = new Selfcap_Engine($off, 'test-key', ['shop.test']);

for ($i = 0; $i < 12; $i++) {
    [, $issued] = $quiet->challenge($context('203.0.113.60'));
    $quiet->verify($context('203.0.113.60'), $issued['challenge'], 'not-a-number', $human());
}

$check('automatic blocking can be switched off', count($rules()), 0);

// ---- Real visitor IP ----------------------------------------------------
$check('direct visitor', Selfcap_IP::client(['REMOTE_ADDR' => '203.0.113.9']), '203.0.113.9');
$check('direct visitor cannot forge headers', Selfcap_IP::client(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']), '203.0.113.9');
$check('Cloudflare', Selfcap_IP::client(['REMOTE_ADDR' => '104.22.21.8', 'HTTP_CF_CONNECTING_IP' => '198.51.100.23', 'HTTP_X_FORWARDED_FOR' => '198.51.100.23']), '198.51.100.23');
$check('Cloudflare over IPv6', Selfcap_IP::client(['REMOTE_ADDR' => '2606:4700:10::6816:1508', 'HTTP_CF_CONNECTING_IP' => '2001:db8::1']), '2001:db8::1');
$check('load balancer', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_FOR' => '198.51.100.23']), '198.51.100.23');
$check('load balancer ignores forged left side', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.23']), '198.51.100.23');
$check('Cloudflare in front of a load balancer', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_FOR' => '198.51.100.23, 172.70.1.1']), '198.51.100.23');
$check('proxy without headers', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4']), '10.0.3.4');
$check('headers switched off', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_FOR' => '198.51.100.23'], false), '10.0.3.4');
$check('custom proxy', Selfcap_IP::client(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.23'], true, ['192.0.2.0/24']), '198.51.100.23');
$check('unlisted proxy is not believed', Selfcap_IP::client(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.23']), '192.0.2.10');
$check('IPv4-mapped IPv6', Selfcap_IP::client(['REMOTE_ADDR' => '::ffff:203.0.113.9']), '203.0.113.9');
$check('garbage header', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_FOR' => 'unknown']), '10.0.3.4');
$check('Cloudflare without CF-Connecting-IP ignores X-Forwarded-For', Selfcap_IP::client(['REMOTE_ADDR' => '104.22.21.8', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']), '104.22.21.8');
$check('proxy that adds the port (IPv4)', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_FOR' => '198.51.100.23:51234']), '198.51.100.23');
$check('proxy that adds the port (IPv6)', Selfcap_IP::client(['REMOTE_ADDR' => '10.0.3.4', 'HTTP_X_FORWARDED_FOR' => '[2001:db8::7]:443']), '2001:db8::7');
$check('wide range', [Selfcap_IP::wide_range('2001:db8:1:2:3::4'), Selfcap_IP::wide_range('203.0.113.1')], ['2001:db8:1::/48', null]);
$check('range validation', [Selfcap_IP::is_valid_range('10.0.0.0/8'), Selfcap_IP::is_valid_range('2001:db8::/32'), Selfcap_IP::is_valid_range('10.0.0.0/33'), Selfcap_IP::is_valid_range('nope')], [true, true, false, false]);

// ---- Form validation helper ---------------------------------------------
$wipe();
$_SERVER['REMOTE_ADDR'] = '203.0.113.70';
$_SERVER['HTTP_USER_AGENT'] = $agent;
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US';
$_SERVER['HTTP_ORIGIN'] = home_url();

$live = Selfcap_Plugin::engine();
[$status, $issued] = $live->challenge(Selfcap_Plugin::context());
$check('challenge through the plugin', $status, 200);
[, $verified] = $live->verify(Selfcap_Plugin::context(), $issued['challenge'], $solve($issued['salt'], $issued['difficulty']), $human());

$_POST[SELFCAP_FIELD] = $verified['token'];
$check('self_captcha_verify() accepts the posted token', self_captcha_verify(), true);
$check('a second hook in the same request does not burn it', self_captcha_verify(), true);
$check('validated only once', (int) $GLOBALS['wpdb']->get_var('SELECT validated FROM '.Selfcap_Store::table('stats')), 1);

$_POST[SELFCAP_FIELD] = '';
$check('missing token is refused', self_captcha_verify(), false);
$check('forged token is refused', self_captcha_verify('abc.def'), false);

// ---- Housekeeping -------------------------------------------------------
$wipe();
$GLOBALS['wpdb']->insert(Selfcap_Store::table('log'), ['created_at' => time() - 40 * DAY_IN_SECONDS, 'ip' => '203.0.113.1', 'result' => 'passed']);
$GLOBALS['wpdb']->insert(Selfcap_Store::table('log'), ['created_at' => time(), 'ip' => '203.0.113.2', 'result' => 'passed']);
Selfcap_Store::consume('old', time() - 100);
Selfcap_Store::add_rule('block', '203.0.113.3', null, 'auto', time() - 8 * DAY_IN_SECONDS);
Selfcap_Store::add_rule('block', '203.0.113.4', null, 'auto', time() - 60);
Selfcap_Store::add_rule('block', '203.0.113.5', null);
sleep(7);
Selfcap_Store::prune(30, 7);
$check('prune keeps what is still needed', [
    (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '.Selfcap_Store::table('log')),
    (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '.Selfcap_Store::table('counters')),
    (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM '.Selfcap_Store::table('rules')),
], [1, 0, 2]);

$wipe();

echo $failures === 0 ? "OK - {$checks} checks passed\n" : "{$failures} of {$checks} checks FAILED\n";
