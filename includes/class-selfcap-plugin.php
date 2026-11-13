<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Options, widget markup and token validation. */
class Selfcap_Plugin
{
    public const OPTION = 'selfcap_options';

    private const KEY_OPTION = 'selfcap_signing_key';

    private const CRON_HOOK = 'selfcap_prune';

    public static function register(): void
    {
        add_action('plugins_loaded', [self::class, 'maybe_upgrade']);
        add_action('init', [self::class, 'load_translations'], 1);
        add_filter('load_textdomain_mofile', [self::class, 'translation_file'], 10, 2);
        add_action('admin_init', [self::class, 'privacy_policy']);
        add_action(self::CRON_HOOK, [self::class, 'prune']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets'], 5);
        add_action('login_enqueue_scripts', [self::class, 'register_assets'], 5);
    }

    public static function activate(): void
    {
        Selfcap_Store::install();
        self::signing_key();

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** Creates or updates the tables when the plugin files were replaced without reactivating. */
    public static function maybe_upgrade(): void
    {
        if (get_option('selfcap_db_version') !== SELFCAP_DB_VERSION) {
            self::activate();
        }
    }

    public static function prune(): void
    {
        $config = self::config();

        Selfcap_Store::prune((int) self::options()['retention_days'], (int) $config['auto_block']['memory_days']);
    }

    // ---- Translations ---------------------------------------------------

    /**
     * The plugin ships in English, Portuguese and Spanish. By default it follows
     * the WordPress language; the "Plugin language" setting forces one of the three.
     *
     * Returns the bundled translation file to use, or null for English.
     */
    private static function translation(): ?string
    {
        $language = (string) self::options()['plugin_language'];

        if ($language === 'auto') {
            $language = strtolower(substr((string) determine_locale(), 0, 2));
        }

        $files = ['pt' => 'pt_BR', 'es' => 'es_ES'];

        return isset($files[$language])
            ? plugin_dir_path(SELFCAP_FILE).'languages/selfcap-'.$files[$language].'.mo'
            : null;
    }

    public static function load_translations(): void
    {
        unload_textdomain('selfcap');

        $file = self::translation();

        if ($file !== null && is_readable($file)) {
            load_textdomain('selfcap', $file); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
        }
    }

    /** Any other attempt to load this plugin's strings gets the chosen language too. */
    public static function translation_file($mofile, $domain)
    {
        if ($domain !== 'selfcap') {
            return $mofile;
        }

        $file = self::translation();

        return $file === null ? plugin_dir_path(SELFCAP_FILE).'languages/none.mo' : $file;
    }

    /** Suggested text for the site's privacy policy (Settings > Privacy). */
    public static function privacy_policy(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $text = __('To protect forms against bots, this site runs its own captcha. When you see the captcha, your browser solves a small computation and sends technical signals (browser type, screen size, how the checkbox was clicked). The site stores your IP address, browser user agent and the result of each verification for a limited time, to detect attacks and block abusive addresses. This data stays on this site and is not shared with any third party.', 'selfcap');

        wp_add_privacy_policy_content('Selfcap', wp_kses_post(wpautop($text)));
    }

    // ---- Settings -------------------------------------------------------

    /** Forms that can be protected: key => label. */
    public static function forms(): array
    {
        $woo = class_exists('WooCommerce');

        $forms = [
            'login' => $woo ? __('Login (WordPress and WooCommerce)', 'selfcap') : __('Login', 'selfcap'),
            'register' => $woo ? __('Registration (WordPress and WooCommerce)', 'selfcap') : __('Registration', 'selfcap'),
            'lostpassword' => $woo ? __('Lost password (WordPress and WooCommerce)', 'selfcap') : __('Lost password', 'selfcap'),
            'comments' => __('Comments', 'selfcap'),
        ];

        if ($woo) {
            $forms['woo_checkout'] = __('WooCommerce checkout', 'selfcap');
        }

        if (defined('WPCF7_VERSION')) {
            $forms['cf7'] = __('Contact Form 7 (add the [selfcap] tag to the form)', 'selfcap');
        }

        return $forms;
    }

    public static function options(): array
    {
        $options = wp_parse_args((array) get_option(self::OPTION, []), [
            'enabled' => 1,
            'forms' => ['login', 'register', 'lostpassword', 'comments'],
            'theme' => 'auto',
            'language' => 'auto',
            'plugin_language' => 'auto',
            'difficulty' => 18,
            'auto_block' => 1,
            'ip_headers' => 1,
            'block_xmlrpc' => 1,
            'trusted_proxies' => '',
            'retention_days' => 30,
        ]);

        // Older settings had separate WooCommerce login and registration options.
        $forms = (array) $options['forms'];
        $renamed = ['woo_login' => 'login', 'woo_register' => 'register'];

        foreach ($renamed as $old => $new) {
            if (in_array($old, $forms, true)) {
                $forms[] = $new;
            }
        }

        $options['forms'] = array_values(array_unique(array_diff($forms, array_keys($renamed))));

        return $options;
    }

    /** Tuning. Change any value with the `selfcap_config` filter. */
    public static function config(): array
    {
        $options = self::options();

        return (array) apply_filters('selfcap_config', [
            // Seconds a challenge stays valid (time the visitor has to click).
            'challenge_ttl' => 180,
            // Seconds the token stays valid until the form is submitted.
            'token_ttl' => 300,
            // The form must be sent from the address (the /64 on IPv6) that solved the captcha.
            'bind_token_ip' => true,
            // Proof-of-work, in leading zero bits of SHA-256.
            'difficulty' => (int) $options['difficulty'],
            'min_difficulty' => 10,
            'max_difficulty' => 24,
            // Every `adaptive_step` challenges from one IP in 10 minutes add one bit.
            'adaptive_step' => 10,
            'adaptive_max_bonus' => 5,
            // A verification with this score or higher is refused.
            'score_block' => 100,
            // Clicks faster than this (ms after the checkbox showed up) are refused.
            'min_click_ms' => 250,
            // Requests per minute, per IP.
            'rate' => ['challenge' => 30, 'verify' => 30],
            // Counts are per IP (per /64 on IPv6) inside `window` seconds.
            'auto_block' => [
                'enabled' => ! empty($options['auto_block']),
                'window' => 600,
                'fails' => 8,
                'fail_ratio' => 4,
                // Wrong or replayed solutions to genuine challenges (garbage is not counted).
                'bad_solutions' => 10,
                'bot_signals' => 5,
                // Failed logins (wrong password) from one address, captcha solved or not.
                'login_fails' => 20,
                'rate_limited' => 20,
                // With tokens bound to the solver's address, a solver farm has to send
                // the forms itself; this only catches extreme volume.
                'passes' => 100,
                // IPv6: this many blocked /64s inside one /48 within a day block the /48.
                'wide_after' => 16,
                // Minutes blocked on the 1st, 2nd and later offences within `memory_days`.
                'durations' => [15, 60, 1440],
                'memory_days' => 7,
            ],
        ]);
    }

    /** define('SELFCAP_DISABLE', true) in wp-config.php switches everything off (locked out of the login, for instance). */
    public static function is_enabled(): bool
    {
        return ! (defined('SELFCAP_DISABLE') && SELFCAP_DISABLE) && ! empty(self::options()['enabled']);
    }

    public static function protects(string $form): bool
    {
        return self::is_enabled() && in_array($form, (array) self::options()['forms'], true);
    }

    // ---- Engine ---------------------------------------------------------

    public static function engine(): Selfcap_Engine
    {
        static $engine = null;

        if ($engine === null) {
            $hostnames = [
                wp_parse_url(home_url(), PHP_URL_HOST),
                wp_parse_url(site_url(), PHP_URL_HOST),
            ];

            /** Extra hostnames the widget may run on (domain mapping, multilingual domains). */
            $hostnames = (array) apply_filters('selfcap_hostnames', array_filter($hostnames));

            $engine = new Selfcap_Engine(self::config(), self::signing_key(), $hostnames);
        }

        return $engine;
    }

    /** The request as the engine sees it. */
    public static function context(): array
    {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';

        if ($origin === '' || $origin === 'null') {
            $origin = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        }

        $host = $origin === '' ? null : wp_parse_url($origin, PHP_URL_HOST);

        return [
            'ip' => self::client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'accept_language' => ! empty($_SERVER['HTTP_ACCEPT_LANGUAGE']),
            'hostname' => is_string($host) && $host !== '' ? strtolower($host) : null,
        ];
        // phpcs:enable
    }

    public static function client_ip(): string
    {
        $options = self::options();
        $proxies = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $options['trusted_proxies'])), [Selfcap_IP::class, 'is_valid_range']);

        return Selfcap_IP::client($_SERVER, ! empty($options['ip_headers']), $proxies);
    }

    private static function signing_key(): string
    {
        $key = get_option(self::KEY_OPTION);

        if (! is_string($key) || strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            update_option(self::KEY_OPTION, $key, false);
        }

        return $key;
    }

    // ---- Widget ---------------------------------------------------------

    public static function register_assets(): void
    {
        $base = plugin_dir_url(SELFCAP_FILE).'assets/';

        wp_register_script('selfcap-widget', $base.'widget.js', [], SELFCAP_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
        wp_register_script('selfcap-forms', $base.'forms.js', ['selfcap-widget'], SELFCAP_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    }

    public static function enqueue(): void
    {
        if (! wp_script_is('selfcap-forms', 'registered')) {
            self::register_assets();
        }

        wp_enqueue_script('selfcap-forms');
    }

    /** The widget markup. `$attributes` accepts theme and language, to force one for a single form. */
    public static function widget($attributes = []): string
    {
        if (! self::is_enabled()) {
            return '';
        }

        self::enqueue();

        $options = self::options();
        $attributes = is_array($attributes) ? $attributes : [];
        $theme = in_array($attributes['theme'] ?? '', ['auto', 'light', 'dark'], true) ? $attributes['theme'] : $options['theme'];
        $language = in_array($attributes['language'] ?? '', ['auto', 'en', 'pt', 'es'], true) ? $attributes['language'] : $options['language'];

        // The site key is a constant: this captcha serves only this WordPress site.
        return sprintf(
            '<div class="self-captcha" data-sitekey="local" data-endpoint="%s" data-theme="%s" data-language="%s" style="margin:0 0 16px"></div>',
            esc_url(rest_url('selfcap/v1')),
            esc_attr($theme),
            esc_attr($language)
        );
    }

    /**
     * True when the token is valid, WP_Error otherwise. The answer is kept for
     * the rest of the request, so hooks that run twice for the same submission
     * do not burn the one-time token.
     *
     * @return true|WP_Error
     */
    public static function verify(?string $token = null)
    {
        static $results = [];

        if (! self::is_enabled()) {
            return true;
        }

        if ($token === null) {
            $token = isset($_POST[SELFCAP_FIELD]) ? sanitize_text_field(wp_unslash($_POST[SELFCAP_FIELD])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        if ($token === '') {
            return new WP_Error('selfcap_missing', __('Please confirm you are not a robot.', 'selfcap'));
        }

        if (! isset($results[$token])) {
            $results[$token] = self::engine()->validate_token($token, self::client_ip()) === true
                ? true
                : new WP_Error('selfcap_failed', __('Captcha verification failed. Please try again.', 'selfcap'));
        }

        return $results[$token];
    }
}
