<?php

if (! defined('ABSPATH')) {
    exit;
}

/** wp-admin > Selfcap: overview (totals, chart, log), IP rules and settings. */
class Selfcap_Admin
{
    private const SLUG = 'selfcap';

    /** Rows per page in the verification log. The admin's last choice is remembered. */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

    private const PER_PAGE_DEFAULT = 10;

    private const PER_PAGE_META = 'selfcap_per_page';

    private const RANGES = [7, 30, 90];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'settings']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_selfcap_add_rule', [self::class, 'add_rule']);
        add_action('admin_post_selfcap_delete_rule', [self::class, 'delete_rule']);
        add_filter('plugin_action_links_'.plugin_basename(SELFCAP_FILE), [self::class, 'action_links']);
    }

    public static function menu(): void
    {
        add_menu_page('Selfcap', 'Selfcap', 'manage_options', self::SLUG, [self::class, 'page'], 'dashicons-shield', 81);
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_'.self::SLUG) {
            return;
        }

        $base = plugin_dir_url(SELFCAP_FILE).'assets/';

        wp_enqueue_style('selfcap-admin', $base.'admin.css', [], SELFCAP_VERSION);
        wp_enqueue_script('selfcap-admin', $base.'admin.js', [], SELFCAP_VERSION, ['in_footer' => true]);
    }

    public static function action_links(array $links): array
    {
        array_unshift($links, '<a href="'.esc_url(self::url('settings')).'">'.esc_html__('Settings', 'selfcap').'</a>');

        return $links;
    }

    public static function settings(): void
    {
        register_setting('selfcap', Selfcap_Plugin::OPTION, ['sanitize_callback' => [self::class, 'sanitize']]);
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $proxies = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($input['trusted_proxies'] ?? ''))), [Selfcap_IP::class, 'is_valid_range']);

        return [
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'forms' => array_values(array_intersect(array_keys(Selfcap_Plugin::forms()), (array) ($input['forms'] ?? []))),
            'theme' => in_array($input['theme'] ?? '', ['auto', 'light', 'dark'], true) ? $input['theme'] : 'auto',
            'language' => in_array($input['language'] ?? '', ['auto', 'en', 'pt', 'es'], true) ? $input['language'] : 'auto',
            'align' => in_array($input['align'] ?? '', ['left', 'center', 'right'], true) ? $input['align'] : 'left',
            'plugin_language' => in_array($input['plugin_language'] ?? '', ['auto', 'en', 'pt', 'es'], true) ? $input['plugin_language'] : 'auto',
            'difficulty' => max(10, min(24, (int) ($input['difficulty'] ?? 18))),
            'auto_block' => empty($input['auto_block']) ? 0 : 1,
            'ip_headers' => empty($input['ip_headers']) ? 0 : 1,
            'block_xmlrpc' => empty($input['block_xmlrpc']) ? 0 : 1,
            'trusted_proxies' => implode("\n", $proxies),
            'retention_days' => max(1, min(365, (int) ($input['retention_days'] ?? 30))),
            'checkout_position' => array_key_exists((string) ($input['checkout_position'] ?? ''), Selfcap_Plugin::CHECKOUT_HOOKS) ? $input['checkout_position'] : 'button',
            // A CSS selector, only ever used by querySelector() in the browser.
            'checkout_selector' => substr(trim(sanitize_text_field((string) ($input['checkout_selector'] ?? ''))), 0, 200),
        ];
    }

    private static function url(string $tab, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('admin.php'));
    }

    // ---- Rule actions ---------------------------------------------------

    public static function add_rule(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'selfcap'), '', ['response' => 403]);
        }

        check_admin_referer('selfcap_add_rule');

        $range = isset($_POST['ip_range']) ? trim(sanitize_text_field(wp_unslash($_POST['ip_range']))) : '';
        $type = isset($_POST['type']) && $_POST['type'] === 'allow' ? 'allow' : 'block';
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';
        $hours = isset($_POST['hours']) ? absint($_POST['hours']) : 0;

        if (! Selfcap_IP::is_valid_range($range)) {
            wp_safe_redirect(self::url('rules', ['selfcap_notice' => 'invalid']));
            exit;
        }

        Selfcap_Store::add_rule($type, $range, $reason, 'manual', $hours > 0 ? time() + $hours * HOUR_IN_SECONDS : null);

        wp_safe_redirect(self::url('rules', ['selfcap_notice' => 'added']));
        exit;
    }

    public static function delete_rule(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'selfcap'), '', ['response' => 403]);
        }

        $id = isset($_GET['rule']) ? absint($_GET['rule']) : 0;

        check_admin_referer('selfcap_delete_rule_'.$id);
        Selfcap_Store::delete_rule($id);

        wp_safe_redirect(self::url('rules', ['selfcap_notice' => 'deleted']));
        exit;
    }

    // ---- Page -----------------------------------------------------------

    public static function page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $tabs = [
            'overview' => __('Overview', 'selfcap'),
            'rules' => __('IP rules', 'selfcap'),
            'settings' => __('Settings', 'selfcap'),
        ];

        if (! isset($tabs[$tab])) {
            $tab = 'overview';
        }

        $enabled = Selfcap_Plugin::is_enabled();
        $forms = count(array_intersect(array_keys(Selfcap_Plugin::forms()), (array) Selfcap_Plugin::options()['forms']));
        ?>
        <div class="wrap scs">
            <header class="scs-header">
                <span class="scs-logo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5.5c0 4.400-2.900 8-7 9.500-4.100-1.500-7-5.100-7-9.500V6z"/><path d="M9 12l2.200 2.200L15 10.500"/></svg>
                </span>
                <div class="scs-title">
                    <h1>Selfcap <span class="scs-version">v<?php echo esc_html(SELFCAP_VERSION); ?></span></h1>
                    <p><?php esc_html_e('Your own captcha, running entirely on this site.', 'selfcap'); ?></p>
                </div>
                <span class="scs-status <?php echo $enabled ? 'is-on' : ''; ?>">
                    <?php
                    if ($enabled) {
                        /* translators: %d: number of protected forms */
                        echo esc_html(sprintf(_n('Protecting %d form', 'Protecting %d forms', $forms, 'selfcap'), $forms));
                    } else {
                        esc_html_e('Disabled', 'selfcap');
                    }
                    ?>
                </span>
            </header>

            <nav class="scs-tabs" aria-label="<?php esc_attr_e('Sections', 'selfcap'); ?>">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a class="<?php echo $key === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::url($key)); ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <hr class="wp-header-end">

            <?php if (self::proxy_unrecognised()) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e('Most recent visitors show up with a private IP address. Your site is probably behind a proxy or load balancer that is not recognised, so per-visitor limits and automatic blocking cannot work.', 'selfcap'); ?>
                        <a href="<?php echo esc_url(self::url('settings')); ?>"><?php esc_html_e('Check the visitor IP settings', 'selfcap'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (defined('SELFCAP_DISABLE') && SELFCAP_DISABLE) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('The captcha is switched off by SELFCAP_DISABLE in wp-config.php.', 'selfcap'); ?></p></div>
            <?php endif; ?>

            <?php
            if ($tab === 'rules') {
                self::rules_tab();
            } elseif ($tab === 'settings') {
                self::settings_tab();
            } else {
                self::overview_tab();
            }
            ?>
        </div>
        <?php
    }

    // ---- Overview -------------------------------------------------------

    private static function overview_tab(): void
    {
        global $wpdb;

        $stats = Selfcap_Store::table('stats');
        $log = Selfcap_Store::table('log');

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $range = isset($_GET['days']) ? absint($_GET['days']) : 30;
        $range = in_array($range, self::RANGES, true) ? $range : 30;
        $result = isset($_GET['result']) ? sanitize_key($_GET['result']) : '';
        $result = in_array($result, ['passed', 'failed', 'blocked'], true) ? $result : '';
        $ip = isset($_GET['ip']) ? trim(sanitize_text_field(wp_unslash($_GET['ip']))) : '';
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 0;
        // phpcs:enable

        $per_page = self::per_page($per_page);
        $now = time();
        $since = wp_date('Y-m-d', $now - ($range - 1) * DAY_IN_SECONDS);

        // Custom tables, read live for this screen. Empty filters match everything.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $days = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE day >= %s ORDER BY day', $stats, $since), OBJECT_K);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE (%s = '' OR result = %s) AND (%s = '' OR ip = %s)",
            $log, $result, $result, $ip, $ip
        ));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE (%s = '' OR result = %s) AND (%s = '' OR ip = %s) ORDER BY id DESC LIMIT %d OFFSET %d",
            $log, $result, $result, $ip, $ip, $per_page, ($paged - 1) * $per_page
        ));
        // phpcs:enable

        $totals = array_fill_keys(Selfcap_Store::STAT_COLUMNS, 0);
        $series = [];
        $peak = 0;

        for ($offset = $range - 1; $offset >= 0; $offset--) {
            $time = $now - $offset * DAY_IN_SECONDS;
            $date = wp_date('Y-m-d', $time);
            $day = $days[$date] ?? null;
            $point = ['time' => $time, 'date' => $date, 'offset' => $offset];

            foreach (Selfcap_Store::STAT_COLUMNS as $column) {
                $point[$column] = $day ? (int) $day->$column : 0;
                $totals[$column] += $point[$column];
            }

            $peak = max($peak, $point['passed'] + $point['failed'] + $point['blocked']);
            $series[] = $point;
        }

        $answered = $totals['passed'] + $totals['failed'];
        $labels = [
            'passed' => __('Approved', 'selfcap'),
            'failed' => __('Refused', 'selfcap'),
            'blocked' => __('Blocked', 'selfcap'),
        ];
        ?>
        <div class="scs-row">
            <h2><?php esc_html_e('Activity', 'selfcap'); ?></h2>
            <div class="scs-seg" role="group" aria-label="<?php esc_attr_e('Period', 'selfcap'); ?>">
                <?php foreach (self::RANGES as $option) : ?>
                    <a class="<?php echo $option === $range ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::url('overview', ['days' => $option])); ?>" <?php echo $option === $range ? 'aria-current="true"' : ''; ?>>
                        <?php
                        /* translators: %d: number of days */
                        echo esc_html(sprintf(__('%d days', 'selfcap'), $option));
                        ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="scs-stats">
            <?php
            self::stat(__('Approved', 'selfcap'), $totals['passed'], $answered > 0
                /* translators: %s: percentage */
                ? sprintf(__('%s of the answers', 'selfcap'), self::percent($totals['passed'], $answered))
                : __('No answers yet', 'selfcap'), 'passed');
            self::stat(__('Refused', 'selfcap'), $totals['failed'], $answered > 0
                /* translators: %s: percentage */
                ? sprintf(__('%s of the answers', 'selfcap'), self::percent($totals['failed'], $answered))
                : __('Bots and failed checks', 'selfcap'), 'failed');
            self::stat(__('Blocked', 'selfcap'), $totals['blocked'], __('Stopped by an IP rule', 'selfcap'), 'blocked');
            self::stat(__('Challenges issued', 'selfcap'), $totals['challenges'], __('Times the widget was shown', 'selfcap'));
            self::stat(__('Forms validated', 'selfcap'), $totals['validated'], __('Submissions that got through', 'selfcap'));
            ?>
        </div>

        <section class="scs-card">
            <div class="scs-row">
                <h2><?php esc_html_e('Verifications per day', 'selfcap'); ?></h2>
                <ul class="scs-legend">
                    <?php foreach ($labels as $key => $label) : ?>
                        <li><span class="scs-key is-<?php echo esc_attr($key); ?>"></span><?php echo esc_html($label); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php self::chart($series, $peak, $range, $labels); ?>

            <details class="scs-details">
                <summary><?php esc_html_e('View as table', 'selfcap'); ?></summary>
                <div class="scs-table-wrap">
                    <table class="scs-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Day', 'selfcap'); ?></th>
                                <?php foreach ($labels as $label) : ?>
                                    <th class="is-num"><?php echo esc_html($label); ?></th>
                                <?php endforeach; ?>
                                <th class="is-num"><?php esc_html_e('Challenges issued', 'selfcap'); ?></th>
                                <th class="is-num"><?php esc_html_e('Forms validated', 'selfcap'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($series) as $point) : ?>
                                <tr>
                                    <td class="is-nowrap"><?php echo esc_html(wp_date(get_option('date_format'), $point['time'])); ?></td>
                                    <?php foreach (['passed', 'failed', 'blocked', 'challenges', 'validated'] as $column) : ?>
                                        <td class="is-num"><?php echo esc_html(number_format_i18n($point[$column])); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>

        <section class="scs-card">
            <div class="scs-row">
                <h2><?php esc_html_e('Verification log', 'selfcap'); ?></h2>
                <form method="get" class="scs-filters">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                    <input type="hidden" name="days" value="<?php echo esc_attr($range); ?>">
                    <label class="screen-reader-text" for="scs-result"><?php esc_html_e('Result', 'selfcap'); ?></label>
                    <select id="scs-result" name="result">
                        <option value=""><?php esc_html_e('All results', 'selfcap'); ?></option>
                        <?php foreach ($labels as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($result, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="screen-reader-text" for="scs-ip"><?php esc_html_e('IP address', 'selfcap'); ?></label>
                    <input id="scs-ip" type="search" name="ip" value="<?php echo esc_attr($ip); ?>" placeholder="<?php esc_attr_e('IP address', 'selfcap'); ?>">
                    <button type="submit" class="button"><?php esc_html_e('Filter', 'selfcap'); ?></button>
                    <?php if ($result !== '' || $ip !== '') : ?>
                        <a href="<?php echo esc_url(self::url('overview', ['days' => $range])); ?>"><?php esc_html_e('Clear', 'selfcap'); ?></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="scs-table-wrap">
                <table class="scs-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('When', 'selfcap'); ?></th>
                            <th><?php esc_html_e('Result', 'selfcap'); ?></th>
                            <th><?php esc_html_e('IP address', 'selfcap'); ?></th>
                            <th class="is-num"><?php esc_html_e('Score', 'selfcap'); ?></th>
                            <th><?php esc_html_e('Reason', 'selfcap'); ?></th>
                            <th class="is-num scs-hide-s"><?php esc_html_e('Solved in', 'selfcap'); ?></th>
                            <th class="scs-hide-s"><?php esc_html_e('Browser', 'selfcap'); ?></th>
                            <th><span class="screen-reader-text"><?php esc_html_e('Actions', 'selfcap'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []) : ?>
                            <tr><td colspan="8" class="scs-none"><?php esc_html_e('No verifications to show yet.', 'selfcap'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row) : ?>
                            <?php $key = isset($labels[$row->result]) ? $row->result : 'blocked'; ?>
                            <tr>
                                <td class="is-nowrap" title="<?php echo esc_attr(wp_date('Y-m-d H:i:s', (int) $row->created_at)); ?>">
                                    <?php
                                    /* translators: %s: time span, like "5 minutes" */
                                    echo esc_html(sprintf(__('%s ago', 'selfcap'), human_time_diff((int) $row->created_at, $now)));
                                    ?>
                                    <span class="scs-sub"><?php echo esc_html(wp_date('M j, H:i', (int) $row->created_at)); ?></span>
                                </td>
                                <td><span class="scs-badge"><span class="scs-key is-<?php echo esc_attr($key); ?>"></span><?php echo esc_html($labels[$key]); ?></span></td>
                                <td><a href="<?php echo esc_url(self::url('overview', ['ip' => $row->ip, 'days' => $range])); ?>"><code><?php echo esc_html($row->ip); ?></code></a></td>
                                <td class="is-num"><?php echo esc_html($row->score); ?></td>
                                <td>
                                    <?php foreach (array_filter(array_map('trim', explode(',', (string) $row->reason))) as $reason) : ?>
                                        <span class="scs-chip"><?php echo esc_html($reason); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="is-num scs-hide-s"><?php echo $row->solve_ms === null ? '' : esc_html(number_format_i18n((int) $row->solve_ms).' ms'); ?></td>
                                <td class="scs-hide-s" title="<?php echo esc_attr($row->user_agent); ?>"><?php echo esc_html(self::browser((string) $row->user_agent)); ?></td>
                                <td class="scs-actions is-num"><a href="<?php echo esc_url(self::url('rules', ['prefill' => $row->ip])); ?>"><?php esc_html_e('Block', 'selfcap'); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php self::pagination($total, $paged, $per_page, self::url('overview', array_filter(['days' => $range, 'result' => $result, 'ip' => $ip]))); ?>
        </section>
        <?php
    }

    private static function stat(string $label, int $value, string $note, string $key = ''): void
    {
        ?>
        <div class="scs-stat">
            <div class="scs-stat__label">
                <?php if ($key !== '') : ?>
                    <span class="scs-key is-<?php echo esc_attr($key); ?>"></span>
                <?php endif; ?>
                <?php echo esc_html($label); ?>
            </div>
            <div class="scs-stat__value"><?php echo esc_html(number_format_i18n($value)); ?></div>
            <div class="scs-stat__note"><?php echo esc_html($note); ?></div>
        </div>
        <?php
    }

    /** Stacked columns, one per day. Plain HTML and CSS, so it stretches with the page. */
    private static function chart(array $series, int $peak, int $range, array $labels): void
    {
        $top = self::axis_top($peak);

        // Date labels: three densities, picked by the width of the chart (container queries).
        $steps = ['is-many' => (int) ceil($range / 10), 'is-some' => (int) ceil($range / 6), 'is-few' => (int) ceil($range / 3)];
        $first = true;
        ?>
        <div class="scs-chart" data-labels="<?php echo esc_attr((string) wp_json_encode($labels)); ?>">
            <div class="scs-chart__y" aria-hidden="true">
                <span><?php echo esc_html(number_format_i18n($top)); ?></span>
                <span><?php echo esc_html(number_format_i18n($top / 2)); ?></span>
                <span>0</span>
            </div>
            <div class="scs-chart__plot">
                <div class="scs-chart__cols" role="list" aria-label="<?php esc_attr_e('Verifications per day', 'selfcap'); ?>">
                    <?php foreach ($series as $point) : ?>
                        <?php
                        $date = wp_date(get_option('date_format'), $point['time']);
                        $summary = sprintf('%s: %s %d, %s %d, %s %d', $date, $labels['passed'], $point['passed'], $labels['failed'], $point['failed'], $labels['blocked'], $point['blocked']);
                        ?>
                        <div class="scs-col" role="listitem" tabindex="<?php echo $first ? '0' : '-1'; ?>" aria-label="<?php echo esc_attr($summary); ?>"
                            data-date="<?php echo esc_attr($date); ?>" data-passed="<?php echo esc_attr($point['passed']); ?>" data-failed="<?php echo esc_attr($point['failed']); ?>" data-blocked="<?php echo esc_attr($point['blocked']); ?>">
                            <span class="scs-stack">
                                <?php foreach (['blocked', 'failed', 'passed'] as $key) : ?>
                                    <?php if ($point[$key] > 0) : ?>
                                        <i class="scs-seg-bar is-<?php echo esc_attr($key); ?>" style="height:<?php echo esc_attr(round($point[$key] / $top * 100, 3)); ?>%"></i>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <?php $first = false; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($peak === 0) : ?>
                    <div class="scs-empty"><?php esc_html_e('No verifications in this period yet.', 'selfcap'); ?></div>
                <?php endif; ?>
            </div>
            <div class="scs-chart__x" aria-hidden="true">
                <?php foreach ($series as $point) : ?>
                    <?php
                    $classes = [];

                    foreach ($steps as $class => $step) {
                        if ($point['offset'] % $step === 0) {
                            $classes[] = $class;
                        }
                    }
                    ?>
                    <span class="<?php echo esc_attr(implode(' ', $classes)); ?>"><?php echo $classes === [] ? '' : esc_html(wp_date('M j', $point['time'])); ?></span>
                <?php endforeach; ?>
            </div>
            <div class="scs-tip" hidden></div>
        </div>
        <?php
    }

    /** Footer of a list: "1–10 of 60", the page size switch and the page links. */
    private static function pagination(int $total, int $paged, int $per_page, string $base): void
    {
        $links = paginate_links([
            'base' => add_query_arg('paged', '%#%', add_query_arg('per_page', $per_page, $base)),
            'format' => '',
            'current' => $paged,
            'total' => max(1, (int) ceil($total / $per_page)),
            'prev_text' => '‹',
            'next_text' => '›',
        ]);
        ?>
        <div class="scs-pages">
            <div class="scs-pages__info">
                <span>
                    <?php
                    if ($total === 0) {
                        /* translators: %s: number of log entries */
                        echo esc_html(sprintf(_n('%s entry', '%s entries', $total, 'selfcap'), number_format_i18n($total)));
                    } else {
                        echo esc_html(sprintf(
                            /* translators: 1: first entry shown, 2: last entry shown, 3: total entries */
                            __('%1$s–%2$s of %3$s', 'selfcap'),
                            number_format_i18n(min($total, ($paged - 1) * $per_page + 1)),
                            number_format_i18n(min($total, $paged * $per_page)),
                            number_format_i18n($total)
                        ));
                    }
                    ?>
                </span>
                <span class="scs-per-page">
                    <span class="scs-per-page__label"><?php esc_html_e('Per page', 'selfcap'); ?></span>
                    <span class="scs-seg is-small" role="group" aria-label="<?php esc_attr_e('Per page', 'selfcap'); ?>">
                        <?php foreach (self::PER_PAGE_OPTIONS as $option) : ?>
                            <a class="<?php echo $option === $per_page ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('per_page', $option, $base)); ?>" <?php echo $option === $per_page ? 'aria-current="true"' : ''; ?>><?php echo esc_html(number_format_i18n($option)); ?></a>
                        <?php endforeach; ?>
                    </span>
                </span>
            </div>
            <?php if ($links) : ?>
                <span><?php echo wp_kses_post($links); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /** True when most recent verifications come from private addresses: the proxy in front is not recognised. */
    private static function proxy_unrecognised(): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ips = (array) $wpdb->get_col($wpdb->prepare('SELECT ip FROM %i ORDER BY id DESC LIMIT %d', Selfcap_Store::table('log'), 50));

        if (count($ips) < 10) {
            return false;
        }

        return count(array_filter($ips, [Selfcap_IP::class, 'is_private'])) * 2 >= count($ips);
    }

    /** The page size asked for (and remembered), else the admin's last choice, else 10. */
    private static function per_page(int $asked): int
    {
        $user = get_current_user_id();

        if (in_array($asked, self::PER_PAGE_OPTIONS, true)) {
            update_user_meta($user, self::PER_PAGE_META, $asked);

            return $asked;
        }

        $saved = (int) get_user_meta($user, self::PER_PAGE_META, true);

        return in_array($saved, self::PER_PAGE_OPTIONS, true) ? $saved : self::PER_PAGE_DEFAULT;
    }

    /** A round number at or above the peak whose half is round too: 4, 10, 20, 50, 100... */
    private static function axis_top(int $peak): int
    {
        if ($peak <= 4) {
            return 4;
        }

        $magnitude = 10 ** (int) floor(log10($peak));

        foreach ([1, 2, 4, 5, 10] as $factor) {
            if ($factor * $magnitude >= $peak && ($factor * $magnitude) % 2 === 0) {
                return (int) ($factor * $magnitude);
            }
        }

        return (int) (10 * $magnitude);
    }

    private static function percent(int $part, int $whole): string
    {
        return number_format_i18n($part / $whole * 100, $part === $whole || $part === 0 ? 0 : 1).'%';
    }

    /** "Chrome on macOS" out of a user agent. The full string stays in the cell's title. */
    private static function browser(string $agent): string
    {
        if ($agent === '') {
            return '—';
        }

        $browsers = ['Edg' => 'Edge', 'OPR' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox' => 'Firefox', 'FxiOS' => 'Firefox', 'HeadlessChrome' => 'Headless Chrome', 'CriOS' => 'Chrome', 'Chrome' => 'Chrome', 'Safari' => 'Safari'];
        $systems = ['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'];
        $browser = '';
        $system = '';

        foreach ($browsers as $needle => $name) {
            if (preg_match('/'.preg_quote($needle, '/').'\/(\d+)/', $agent, $match)) {
                $browser = $name.' '.$match[1];

                break;
            }
        }

        foreach ($systems as $needle => $name) {
            if (strpos($agent, $needle) !== false) {
                $system = $name;

                break;
            }
        }

        if ($browser === '') {
            return strlen($agent) > 40 ? substr($agent, 0, 40).'…' : $agent;
        }

        return $system === '' ? $browser : $browser.' · '.$system;
    }

    // ---- IP rules -------------------------------------------------------

    private static function rules_tab(): void
    {
        global $wpdb;

        $table = Selfcap_Store::table('rules');
        $now = time();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.Security.NonceVerification.Recommended
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = self::per_page(isset($_GET['per_page']) ? absint($_GET['per_page']) : 0);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        // Manual rules first: an attack can create thousands of automatic ones.
        $rules = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i ORDER BY (source = 'manual') DESC, id DESC LIMIT %d OFFSET %d",
            $table, $per_page, ($paged - 1) * $per_page
        ));
        $notice = isset($_GET['selfcap_notice']) ? sanitize_key($_GET['selfcap_notice']) : '';
        $prefill = isset($_GET['prefill']) ? trim(sanitize_text_field(wp_unslash($_GET['prefill']))) : '';
        // phpcs:enable

        $prefill = Selfcap_IP::is_valid_range($prefill) ? $prefill : '';
        $notices = [
            'added' => ['success', __('Rule added.', 'selfcap')],
            'deleted' => ['success', __('Rule removed.', 'selfcap')],
            'invalid' => ['error', __('That is not a valid IP address or CIDR range.', 'selfcap')],
        ];

        if (isset($notices[$notice])) {
            printf('<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr($notices[$notice][0]), esc_html($notices[$notice][1]));
        }
        ?>
        <section class="scs-card">
            <h2><?php esc_html_e('Add a rule', 'selfcap'); ?></h2>
            <p class="scs-hint">
                <?php esc_html_e('A blocked address cannot solve the captcha, so it cannot send the protected forms. An allow rule always wins and also keeps the address out of automatic blocking.', 'selfcap'); ?>
                <?php
                /* translators: %s: IP address */
                echo wp_kses(sprintf(__('Your address right now: %s', 'selfcap'), '<code>'.esc_html(Selfcap_Plugin::client_ip()).'</code>'), ['code' => []]);
                ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="scs-form-grid" style="margin-top:16px">
                <input type="hidden" name="action" value="selfcap_add_rule">
                <?php wp_nonce_field('selfcap_add_rule'); ?>
                <div class="scs-field">
                    <label for="scs-type"><?php esc_html_e('Action', 'selfcap'); ?></label>
                    <select id="scs-type" name="type">
                        <option value="block"><?php esc_html_e('Block', 'selfcap'); ?></option>
                        <option value="allow"><?php esc_html_e('Allow', 'selfcap'); ?></option>
                    </select>
                </div>
                <div class="scs-field">
                    <label for="scs-range"><?php esc_html_e('IP address or range', 'selfcap'); ?></label>
                    <input id="scs-range" type="text" name="ip_range" required value="<?php echo esc_attr($prefill); ?>" placeholder="203.0.113.7 / 203.0.113.0/24" class="code">
                </div>
                <div class="scs-field">
                    <label for="scs-reason"><?php esc_html_e('Reason (optional)', 'selfcap'); ?></label>
                    <input id="scs-reason" type="text" name="reason" maxlength="250">
                </div>
                <div class="scs-field">
                    <label for="scs-hours"><?php esc_html_e('Duration', 'selfcap'); ?></label>
                    <select id="scs-hours" name="hours">
                        <option value="0"><?php esc_html_e('Until removed', 'selfcap'); ?></option>
                        <option value="1"><?php esc_html_e('1 hour', 'selfcap'); ?></option>
                        <option value="24"><?php esc_html_e('24 hours', 'selfcap'); ?></option>
                        <option value="168"><?php esc_html_e('7 days', 'selfcap'); ?></option>
                        <option value="720"><?php esc_html_e('30 days', 'selfcap'); ?></option>
                    </select>
                </div>
                <div class="scs-field">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Add rule', 'selfcap'); ?></button>
                </div>
            </form>
        </section>

        <section class="scs-card">
            <div class="scs-row">
                <h2><?php esc_html_e('Rules', 'selfcap'); ?></h2>
            </div>
            <div class="scs-table-wrap">
                <table class="scs-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Action', 'selfcap'); ?></th>
                            <th><?php esc_html_e('IP address or range', 'selfcap'); ?></th>
                            <th><?php esc_html_e('Source', 'selfcap'); ?></th>
                            <th><?php esc_html_e('Reason', 'selfcap'); ?></th>
                            <th class="scs-hide-s"><?php esc_html_e('Created', 'selfcap'); ?></th>
                            <th><?php esc_html_e('Expires', 'selfcap'); ?></th>
                            <th><span class="screen-reader-text"><?php esc_html_e('Actions', 'selfcap'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rules === []) : ?>
                            <tr><td colspan="7" class="scs-none"><?php esc_html_e('No rules yet. Attackers blocked automatically will show up here.', 'selfcap'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($rules as $rule) : ?>
                            <?php
                            $expired = $rule->expires_at !== null && (int) $rule->expires_at <= $now;
                            $remove = wp_nonce_url(add_query_arg(['action' => 'selfcap_delete_rule', 'rule' => (int) $rule->id], admin_url('admin-post.php')), 'selfcap_delete_rule_'.(int) $rule->id);
                            ?>
                            <tr class="<?php echo $expired ? 'is-dim' : ''; ?>">
                                <td>
                                    <span class="scs-badge">
                                        <span class="scs-key is-<?php echo $rule->type === 'allow' ? 'passed' : 'blocked'; ?>"></span>
                                        <?php echo esc_html($rule->type === 'allow' ? __('Allow', 'selfcap') : __('Block', 'selfcap')); ?>
                                    </span>
                                </td>
                                <td><code><?php echo esc_html($rule->ip_range); ?></code></td>
                                <td><?php echo esc_html($rule->source === 'auto' ? __('Automatic', 'selfcap') : __('Manual', 'selfcap')); ?></td>
                                <td><?php echo esc_html((string) $rule->reason); ?></td>
                                <td class="is-nowrap scs-hide-s"><?php echo esc_html(wp_date('M j, H:i', (int) $rule->created_at)); ?></td>
                                <td class="is-nowrap">
                                    <?php
                                    if ($rule->expires_at === null) {
                                        esc_html_e('Never', 'selfcap');
                                    } elseif ($expired) {
                                        esc_html_e('Expired', 'selfcap');
                                    } else {
                                        /* translators: %s: time span, like "5 minutes" */
                                        echo esc_html(sprintf(__('In %s', 'selfcap'), human_time_diff($now, (int) $rule->expires_at)));
                                    }
                                    ?>
                                </td>
                                <td class="scs-actions is-num">
                                    <a class="scs-danger" href="<?php echo esc_url($remove); ?>" data-confirm="<?php esc_attr_e('Remove this rule?', 'selfcap'); ?>"><?php esc_html_e('Remove', 'selfcap'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php self::pagination($total, $paged, $per_page, self::url('rules')); ?>
        </section>
        <?php
    }

    // ---- Settings -------------------------------------------------------

    private static function settings_tab(): void
    {
        $options = Selfcap_Plugin::options();
        $name = Selfcap_Plugin::OPTION;
        $languages = ['en' => 'English', 'pt' => 'Português', 'es' => 'Español'];

        settings_errors();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('selfcap'); ?>

            <div class="scs-settings">
                <section class="scs-card">
                    <h2><?php esc_html_e('Protection', 'selfcap'); ?></h2>
                    <p class="scs-hint"><?php esc_html_e('Turn the captcha on and choose where it shows up.', 'selfcap'); ?></p>

                    <?php
                    self::toggle('enabled', $options, __('Captcha enabled', 'selfcap'), __('Locked out of the login? Add define(\'SELFCAP_DISABLE\', true); to wp-config.php.', 'selfcap'));
                    ?>

                    <div class="scs-option is-stacked">
                        <div class="scs-option__text">
                            <strong><?php esc_html_e('Protected forms', 'selfcap'); ?></strong>
                            <span><?php esc_html_e('For any other form use the [selfcap] shortcode and selfcap_verify() in its handler.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control">
                            <div class="scs-checks">
                                <?php foreach (Selfcap_Plugin::forms() as $key => $label) : ?>
                                    <label class="scs-check">
                                        <input type="checkbox" name="<?php echo esc_attr($name); ?>[forms][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, (array) $options['forms'], true)); ?>>
                                        <span><?php echo esc_html($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (class_exists('WooCommerce')) : ?>
                        <div class="scs-option is-stacked">
                            <div class="scs-option__text">
                                <strong><label for="scs-checkout-position"><?php esc_html_e('Position on the WooCommerce checkout', 'selfcap'); ?></label></strong>
                                <span><?php esc_html_e('"Above the Place order button" finds the visible button even when the theme moves it. The custom element option takes a CSS selector, for layouts the other options do not fit.', 'selfcap'); ?></span>
                            </div>
                            <div class="scs-option__control scs-field">
                                <select id="scs-checkout-position" name="<?php echo esc_attr($name); ?>[checkout_position]">
                                    <?php foreach (Selfcap_Plugin::checkout_positions() as $value => [$label]) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($options['checkout_position'], $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="scs-label" for="scs-checkout-selector" style="margin-top:10px"><?php esc_html_e('CSS selector (custom element only)', 'selfcap'); ?></label>
                                <input id="scs-checkout-selector" type="text" class="code" name="<?php echo esc_attr($name); ?>[checkout_selector]" value="<?php echo esc_attr($options['checkout_selector']); ?>" placeholder=".checkout-right #place_order">
                            </div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="scs-card">
                    <h2><?php esc_html_e('Appearance and language', 'selfcap'); ?></h2>
                    <p class="scs-hint"><?php esc_html_e('How the widget looks to visitors and which language this plugin speaks.', 'selfcap'); ?></p>

                    <div class="scs-option">
                        <div class="scs-option__text">
                            <strong><label for="scs-theme"><?php esc_html_e('Widget theme', 'selfcap'); ?></label></strong>
                            <span><?php esc_html_e('Automatic follows the visitor\'s system.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control">
                            <select id="scs-theme" name="<?php echo esc_attr($name); ?>[theme]">
                                <?php foreach (['auto' => __('Automatic', 'selfcap'), 'light' => __('Light', 'selfcap'), 'dark' => __('Dark', 'selfcap')] as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($options['theme'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="scs-option">
                        <div class="scs-option__text">
                            <strong><label for="scs-language"><?php esc_html_e('Widget language', 'selfcap'); ?></label></strong>
                            <span><?php esc_html_e('Automatic follows the visitor\'s browser.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control">
                            <select id="scs-language" name="<?php echo esc_attr($name); ?>[language]">
                                <option value="auto" <?php selected($options['language'], 'auto'); ?>><?php esc_html_e('Automatic', 'selfcap'); ?></option>
                                <?php foreach ($languages as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($options['language'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="scs-option">
                        <div class="scs-option__text">
                            <strong><label for="scs-align"><?php esc_html_e('Widget alignment', 'selfcap'); ?></label></strong>
                            <span><?php esc_html_e('The widget sits in a full-width row on every form; choose where it lines up.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control">
                            <select id="scs-align" name="<?php echo esc_attr($name); ?>[align]">
                                <?php foreach (['left' => __('Left', 'selfcap'), 'center' => __('Center', 'selfcap'), 'right' => __('Right', 'selfcap')] as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($options['align'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="scs-option">
                        <div class="scs-option__text">
                            <strong><label for="scs-plugin-language"><?php esc_html_e('Plugin language', 'selfcap'); ?></label></strong>
                            <span><?php esc_html_e('This screen and the messages shown when a form is refused. Automatic follows the WordPress language.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control">
                            <select id="scs-plugin-language" name="<?php echo esc_attr($name); ?>[plugin_language]">
                                <option value="auto" <?php selected($options['plugin_language'], 'auto'); ?>><?php esc_html_e('Automatic', 'selfcap'); ?></option>
                                <?php foreach ($languages as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($options['plugin_language'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="scs-card">
                    <h2><?php esc_html_e('Security', 'selfcap'); ?></h2>
                    <p class="scs-hint"><?php esc_html_e('How hard the captcha is for bots and what happens to attackers.', 'selfcap'); ?></p>

                    <div class="scs-option">
                        <div class="scs-option__text">
                            <strong><label for="scs-difficulty"><?php esc_html_e('Difficulty', 'selfcap'); ?></label></strong>
                            <span><?php esc_html_e('Work the visitor\'s browser does in the background, from 10 to 24. Each step doubles it. 18 takes well under a second on a phone.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control">
                            <input id="scs-difficulty" type="number" min="10" max="24" name="<?php echo esc_attr($name); ?>[difficulty]" value="<?php echo esc_attr($options['difficulty']); ?>">
                        </div>
                    </div>

                    <?php
                    self::toggle('block_xmlrpc', $options, __('Block logins over XML-RPC', 'selfcap'), __('XML-RPC accepts a username and password without any form, so attackers use it to get around the login captcha. Turn this off only if you use the WordPress mobile app or a service that logs in over XML-RPC.', 'selfcap'));
                    self::toggle('auto_block', $options, __('Automatic blocking', 'selfcap'), __('Temporarily blocks addresses that behave like an attack, only on attack-like volume inside 10 minutes. Blocks last 15 minutes, then 1 hour, then 24 hours for repeat offenders.', 'selfcap'));
                    ?>

                    <div class="scs-option">
                        <div class="scs-option__text">
                            <strong><label for="scs-retention"><?php esc_html_e('Keep the log for (days)', 'selfcap'); ?></label></strong>
                            <span><?php esc_html_e('Older verification log entries are deleted every day.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control">
                            <input id="scs-retention" type="number" min="1" max="365" name="<?php echo esc_attr($name); ?>[retention_days]" value="<?php echo esc_attr($options['retention_days']); ?>">
                        </div>
                    </div>
                </section>

                <section class="scs-card">
                    <h2><?php esc_html_e('Visitor IP address', 'selfcap'); ?></h2>
                    <p class="scs-hint"><?php esc_html_e('Needed when the site is behind Cloudflare, a load balancer or another proxy.', 'selfcap'); ?></p>

                    <?php
                    self::toggle('ip_headers', $options, __('Read the real IP behind a proxy', 'selfcap'), __('Forwarding headers are only believed when the connection comes from Cloudflare, a private address or a proxy listed below.', 'selfcap'));
                    ?>

                    <div class="scs-option is-stacked">
                        <div class="scs-option__text">
                            <strong><?php esc_html_e('Address detected for you now', 'selfcap'); ?></strong>
                            <span><?php esc_html_e('If this is not your own public IP, add your proxy to the list below.', 'selfcap'); ?></span>
                            <code class="scs-code"><?php echo esc_html(Selfcap_Plugin::client_ip()); ?></code>
                        </div>
                    </div>

                    <div class="scs-option is-stacked">
                        <div class="scs-option__text">
                            <strong><label for="scs-proxies"><?php esc_html_e('Other trusted proxies', 'selfcap'); ?></label></strong>
                            <span><?php esc_html_e('IP address or CIDR range, one per line.', 'selfcap'); ?></span>
                        </div>
                        <div class="scs-option__control scs-field">
                            <textarea id="scs-proxies" name="<?php echo esc_attr($name); ?>[trusted_proxies]" rows="3" class="code"><?php echo esc_textarea($options['trusted_proxies']); ?></textarea>
                        </div>
                    </div>
                </section>
            </div>

            <div class="scs-save">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save changes', 'selfcap'); ?></button>
            </div>
        </form>
        <?php
    }

    private static function toggle(string $key, array $options, string $label, string $help): void
    {
        $id = 'scs-'.str_replace('_', '-', $key);
        ?>
        <div class="scs-option">
            <div class="scs-option__text">
                <strong><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label></strong>
                <span><?php echo esc_html($help); ?></span>
            </div>
            <div class="scs-option__control">
                <span class="scs-switch">
                    <input id="<?php echo esc_attr($id); ?>" type="checkbox" name="<?php echo esc_attr(Selfcap_Plugin::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked(! empty($options[$key])); ?>>
                    <i></i>
                </span>
            </div>
        </div>
        <?php
    }
}
