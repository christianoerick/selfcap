<?php

if (! defined('ABSPATH')) {
    exit;
}

// The captcha keeps its data in its own tables, written and read on every challenge:
// there is no WordPress API for them and caching a rate-limit counter would defeat it.
// Table names go through the %i identifier placeholder.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Everything the captcha keeps in the database: counters (rate limit, attack
 * detection, one-time marks), the verification log, daily totals and IP rules.
 *
 * Counters live in their own table instead of transients because they need an
 * atomic "add one" and an atomic "first time only", which the options table
 * cannot give without a persistent object cache.
 */
class Selfcap_Store
{
    public const STAT_COLUMNS = ['challenges', 'passed', 'failed', 'blocked', 'validated'];

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix.'selfcap_'.$name;
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $counters = self::table('counters');
        $log = self::table('log');
        $stats = self::table('stats');
        $rules = self::table('rules');

        dbDelta("CREATE TABLE {$counters} (
            name varchar(191) NOT NULL,
            hits int unsigned NOT NULL DEFAULT 0,
            expires_at int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (name),
            KEY expires_at (expires_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$log} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            created_at int unsigned NOT NULL DEFAULT 0,
            ip varchar(45) NOT NULL DEFAULT '',
            user_agent varchar(500) NOT NULL DEFAULT '',
            hostname varchar(191) DEFAULT NULL,
            score smallint unsigned NOT NULL DEFAULT 0,
            result varchar(10) NOT NULL DEFAULT '',
            reason varchar(250) DEFAULT NULL,
            solve_ms int unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY ip (ip)
        ) {$charset};");

        dbDelta("CREATE TABLE {$stats} (
            day date NOT NULL,
            challenges int unsigned NOT NULL DEFAULT 0,
            passed int unsigned NOT NULL DEFAULT 0,
            failed int unsigned NOT NULL DEFAULT 0,
            blocked int unsigned NOT NULL DEFAULT 0,
            validated int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (day)
        ) {$charset};");

        dbDelta("CREATE TABLE {$rules} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            type varchar(5) NOT NULL DEFAULT 'block',
            ip_range varchar(50) NOT NULL DEFAULT '',
            reason varchar(250) DEFAULT NULL,
            source varchar(6) NOT NULL DEFAULT 'manual',
            created_at int unsigned NOT NULL DEFAULT 0,
            expires_at int unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY ip_range (ip_range)
        ) {$charset};");

        update_option('selfcap_db_version', SELFCAP_DB_VERSION, false);
    }

    public static function uninstall(): void
    {
        global $wpdb;

        foreach (['counters', 'log', 'stats', 'rules'] as $name) {
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::table($name)));
        }
    }

    // ---- Counters -------------------------------------------------------

    /** Adds one to a counter that restarts `$ttl` seconds after its first hit. Returns the new count. */
    public static function hit(string $name, int $ttl): int
    {
        global $wpdb;

        $table = self::table('counters');
        $now = time();

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (name, hits, expires_at) VALUES (%s, 1, %d)
             ON DUPLICATE KEY UPDATE hits = IF(expires_at <= %d, 1, hits + 1), expires_at = IF(expires_at <= %d, %d, expires_at)',
            $table, $name, $now + $ttl, $now, $now, $now + $ttl
        ));

        return (int) $wpdb->get_var($wpdb->prepare('SELECT hits FROM %i WHERE name = %s', $table, $name));
    }

    public static function count(string $name): int
    {
        global $wpdb;

        $table = self::table('counters');

        return (int) $wpdb->get_var($wpdb->prepare('SELECT hits FROM %i WHERE name = %s AND expires_at > %d', $table, $name, time()));
    }

    /** Marks a one-time id (challenge salt, token id) as used. False when it was used before. */
    public static function consume(string $id, int $expires_at): bool
    {
        global $wpdb;

        $table = self::table('counters');

        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO %i (name, hits, expires_at) VALUES (%s, 1, %d)',
            $table, 'used:'.$id, max(time() + 1, $expires_at) + 5
        ));

        return $inserted === 1;
    }

    // ---- Log and totals -------------------------------------------------

    /** Adds one to a daily total: challenges, passed, failed, blocked or validated. */
    public static function increment(string $column): void
    {
        global $wpdb;

        if (! in_array($column, self::STAT_COLUMNS, true)) {
            return;
        }

        $table = self::table('stats');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (day, %i) VALUES (%s, 1) ON DUPLICATE KEY UPDATE %i = %i + 1',
            $table, $column, wp_date('Y-m-d'), $column, $column
        ));
    }

    /** Writes one line of the verification log and bumps the matching total. */
    public static function log(array $context, string $result, ?string $reason = null, int $score = 0, ?int $solve_ms = null): void
    {
        global $wpdb;

        $wpdb->insert(self::table('log'), [
            'created_at' => time(),
            'ip' => (string) $context['ip'],
            'user_agent' => substr((string) $context['user_agent'], 0, 500),
            'hostname' => $context['hostname'],
            'score' => min($score, 65535),
            'result' => $result,
            'reason' => $reason === null ? null : substr($reason, 0, 250),
            'solve_ms' => $solve_ms,
        ]);

        self::increment($result);
    }

    // ---- IP rules -------------------------------------------------------

    /**
     * Returns "allow", "block" or null when no rule matches. Allow wins over block.
     *
     * Manual rules (few, any CIDR) are all matched. Automatic rules can number in
     * the thousands during an attack, so only the ones for this exact address,
     * its /64 and its /48 are read, by index.
     */
    public static function check_ip(string $ip): ?string
    {
        $blocked = false;
        $candidates = [$ip, Selfcap_IP::range_for($ip), Selfcap_IP::wide_range($ip) ?? $ip];

        foreach (array_merge(self::manual_rules(), self::auto_rules_for($candidates)) as $rule) {
            if (! Selfcap_IP::matches($ip, $rule->ip_range)) {
                continue;
            }

            if ($rule->type === 'allow') {
                return 'allow';
            }

            $blocked = true;
        }

        return $blocked ? 'block' : null;
    }

    public static function add_rule(string $type, string $range, ?string $reason, string $source = 'manual', ?int $expires_at = null): void
    {
        global $wpdb;

        $wpdb->insert(self::table('rules'), [
            'type' => $type === 'allow' ? 'allow' : 'block',
            'ip_range' => $range,
            'reason' => $reason === null || $reason === '' ? null : substr($reason, 0, 250),
            'source' => $source === 'auto' ? 'auto' : 'manual',
            'created_at' => time(),
            'expires_at' => $expires_at,
        ]);

        self::$rules = null;
    }

    public static function delete_rule(int $id): void
    {
        global $wpdb;

        $wpdb->delete(self::table('rules'), ['id' => $id]);
        self::$rules = null;
    }

    /** Automatic blocks this range got in the last `$days` days, expired ones included. */
    public static function recent_auto_blocks(string $range, int $days): int
    {
        global $wpdb;

        $table = self::table('rules');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE source = 'auto' AND ip_range = %s AND created_at >= %d",
            $table, $range, time() - $days * DAY_IN_SECONDS
        ));
    }

    /** True when an automatic rule for exactly this range is active. */
    public static function has_auto_block(string $range): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM %i WHERE source = 'auto' AND type = 'block' AND ip_range = %s AND (expires_at IS NULL OR expires_at > %d) LIMIT 1",
            self::table('rules'), $range, time()
        ));
    }

    /** @var array|null Active manual rules, cached for the request. */
    private static $rules = null;

    private static function manual_rules(): array
    {
        global $wpdb;

        if (self::$rules === null) {
            self::$rules = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT type, ip_range FROM %i WHERE source = 'manual' AND (expires_at IS NULL OR expires_at > %d)",
                self::table('rules'), time()
            ));
        }

        return self::$rules;
    }

    /** @param string[] $ranges Exactly three values (repeat one when there are fewer). */
    private static function auto_rules_for(array $ranges): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT type, ip_range FROM %i WHERE source = 'auto' AND ip_range IN (%s, %s, %s) AND (expires_at IS NULL OR expires_at > %d)",
            self::table('rules'), $ranges[0], $ranges[1], $ranges[2], time()
        ));
    }

    // ---- Housekeeping ---------------------------------------------------

    public static function prune(int $retention_days, int $memory_days): void
    {
        global $wpdb;

        $now = time();
        $counters = self::table('counters');
        $log = self::table('log');
        $stats = self::table('stats');
        $rules = self::table('rules');

        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE expires_at <= %d', $counters, $now));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %d', $log, $now - $retention_days * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE day < %s', $stats, wp_date('Y-m-d', $now - 400 * DAY_IN_SECONDS)));
        // Expired automatic blocks are kept for a while: they are the memory of repeat offenders.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE expires_at IS NOT NULL AND expires_at <= %d', $rules, $now - $memory_days * DAY_IN_SECONDS));

        self::$rules = null;
    }
}
