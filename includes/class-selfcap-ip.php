<?php

if (! defined('ABSPATH')) {
    exit;
}

/** IP helpers: range matching and the real visitor address behind Cloudflare, a load balancer or nothing. */
class Selfcap_IP
{
    private const PRIVATE_RANGES = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '169.254.0.0/16',
        '::1', 'fc00::/7', 'fe80::/10',
    ];

    // https://www.cloudflare.com/ips/
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * The visitor IP. Forwarding headers are believed only when the connection
     * itself comes from Cloudflare, a private address (load balancer, local
     * proxy) or a proxy listed in the settings; a direct visitor cannot forge them.
     *
     * @param array $server       Usually $_SERVER.
     * @param bool  $use_headers  False to always use the connection address.
     * @param array $extra        Extra trusted proxy IPs or CIDRs.
     */
    public static function client(array $server, bool $use_headers = true, array $extra = []): string
    {
        $remote = self::normalize((string) ($server['REMOTE_ADDR'] ?? ''));

        if ($remote === '') {
            return '0.0.0.0';
        }

        if (! $use_headers || ! self::is_trusted($remote, $extra)) {
            return $remote;
        }

        if (self::in_ranges($remote, self::CLOUDFLARE_RANGES)) {
            // Cloudflare always sets this header and overwrites any copy sent by
            // the client. Without it (a Worker, for instance) X-Forwarded-For is
            // whatever the caller wrote, so it is not read at all.
            $cloudflare = self::normalize(trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? '')));

            return $cloudflare !== '' ? $cloudflare : $remote;
        }

        $forwarded = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        $last = $remote;

        // Right to left: the first address that is not one of our proxies is the visitor.
        foreach ($forwarded as $candidate) {
            $candidate = self::normalize($candidate);

            if ($candidate === '') {
                break;
            }

            $last = $candidate;

            if (! self::is_trusted($candidate, $extra)) {
                return $candidate;
            }
        }

        return $last;
    }

    public static function is_trusted(string $ip, array $extra = []): bool
    {
        return self::is_private($ip) || self::in_ranges($ip, self::CLOUDFLARE_RANGES) || self::in_ranges($ip, $extra);
    }

    public static function is_private(string $ip): bool
    {
        return self::in_ranges($ip, self::PRIVATE_RANGES);
    }

    /** IPv4 is judged alone; IPv6 by its /64, since one machine owns the whole prefix. */
    public static function range_for(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) === 4) {
            return $ip;
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /** The /48 an IPv6 address belongs to (a typical customer allocation); null for IPv4. */
    public static function wide_range(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) === 4) {
            return null;
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10)).'/48';
    }

    /** True when `$range` is a valid IP or CIDR (IPv4 or IPv6). */
    public static function is_valid_range(string $range): bool
    {
        $parts = explode('/', $range, 2);
        $packed = @inet_pton($parts[0]);

        if ($packed === false) {
            return false;
        }

        if (! isset($parts[1])) {
            return true;
        }

        return ctype_digit($parts[1]) && (int) $parts[1] <= strlen($packed) * 8;
    }

    public static function matches(string $ip, string $range): bool
    {
        $parts = explode('/', $range, 2);
        $ip_packed = @inet_pton($ip);
        $range_packed = @inet_pton($parts[0]);

        if ($ip_packed === false || $range_packed === false || strlen($ip_packed) !== strlen($range_packed)) {
            return false;
        }

        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($ip_packed) * 8;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ip_packed, 0, $bytes) !== substr($range_packed, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ip_packed[$bytes]) & $mask) === (ord($range_packed[$bytes]) & $mask);
    }

    private static function in_ranges(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, (string) $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical text form, with IPv4-mapped IPv6 turned back into IPv4. Some
     * proxies (Azure among them) add the port: "203.0.113.9:51234", "[2001:db8::1]:443".
     * Empty when invalid.
     */
    private static function normalize(string $ip): string
    {
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d+)?$/', $ip, $match)) {
            $ip = $match[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $match)) {
            $ip = $match[1];
        }

        $packed = @inet_pton($ip);

        if ($packed === false) {
            return '';
        }

        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }

        return (string) inet_ntop($packed);
    }
}
