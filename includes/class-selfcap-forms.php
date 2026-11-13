<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Hooks the widget and its validation into WordPress, WooCommerce and Contact Form 7 forms. */
class Selfcap_Forms
{
    /** Namespace of the token in the WooCommerce block checkout request. */
    private const STORE_API_NAMESPACE = 'selfcap';

    public static function register(): void
    {
        add_shortcode('selfcap', [Selfcap_Plugin::class, 'widget']);
        add_action('login_enqueue_scripts', [self::class, 'login_assets']);

        // WordPress core
        add_action('login_form', [self::class, 'print_for_login']);
        add_filter('authenticate', [self::class, 'check_login'], 30, 3);

        add_action('register_form', [self::class, 'print_for_register']);
        add_filter('registration_errors', [self::class, 'check_register'], 10, 1);

        add_action('lostpassword_form', [self::class, 'print_for_lostpassword']);
        add_action('lostpassword_post', [self::class, 'check_lostpassword'], 10, 1);

        add_filter('comment_form_submit_field', [self::class, 'comment_field'], 10, 1);
        add_filter('preprocess_comment', [self::class, 'check_comment'], 10, 1);

        // XML-RPC logs in without any form, so it would be a way around the login captcha.
        add_filter('xmlrpc_enabled', [self::class, 'xmlrpc_enabled']);

        // WooCommerce My Account forms: the same "Login", "Registration" and
        // "Lost password" options, so neither form is a way around the other.
        add_action('woocommerce_login_form', [self::class, 'print_for_login']);
        add_filter('woocommerce_process_login_errors', [self::class, 'check_woo_login'], 10, 1);
        add_action('woocommerce_register_form', [self::class, 'print_for_register']);
        add_action('woocommerce_lostpassword_form', [self::class, 'print_for_lostpassword']);
        add_filter('woocommerce_process_registration_errors', [self::class, 'check_woo_register'], 10, 1);
        // The checkout widget goes on the hook of the position chosen in the settings.
        foreach (array_unique(Selfcap_Plugin::CHECKOUT_HOOKS) as $hook) {
            add_action($hook, [self::class, 'print_for_woo_checkout']);
        }
        add_action('woocommerce_after_checkout_validation', [self::class, 'check_woo_checkout'], 10, 2);

        // WooCommerce "Pay for order" page: paying an existing order again, a favourite of card testers.
        add_action('woocommerce_pay_order_before_submit', [self::class, 'print_for_woo_pay']);
        add_action('woocommerce_before_pay_action', [self::class, 'check_woo_pay']);

        // Multisite sign-up (wp-signup.php) instead of wp-login.php?action=register.
        add_action('signup_extra_fields', [self::class, 'print_for_signup']);
        // Step 2 of "user and site" sign-up validates the user again: it needs its own widget.
        add_action('signup_blogform', [self::class, 'print_for_signup']);

        // Wrong passwords count towards automatic blocking, whatever the captcha said.
        add_action('wp_login_failed', [self::class, 'login_failed']);
        add_filter('wpmu_validate_user_signup', [self::class, 'check_signup']);

        // WooCommerce, block checkout
        add_action('woocommerce_blocks_loaded', [self::class, 'block_checkout_data']);
        add_filter('render_block_woocommerce/checkout-actions-block', [self::class, 'block_checkout_widget'], 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [self::class, 'check_block_checkout'], 10, 2);

        // Contact Form 7
        add_action('wpcf7_init', [self::class, 'cf7_tag']);
        add_filter('wpcf7_spam', [self::class, 'check_cf7'], 9, 2);
    }

    public static function login_assets(): void
    {
        if (Selfcap_Plugin::protects('login') || Selfcap_Plugin::protects('register') || Selfcap_Plugin::protects('lostpassword')) {
            Selfcap_Plugin::enqueue();
            // The widget is 300px wide and the login box is 272px inside: let it grow.
            wp_add_inline_style('login', '#login{width:350px}');
        }
    }

    private static function print_if(string $form): void
    {
        if (Selfcap_Plugin::protects($form)) {
            echo Selfcap_Plugin::widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public static function print_for_login(): void
    {
        self::print_if('login');
    }

    public static function print_for_register(): void
    {
        self::print_if('register');
    }

    public static function print_for_lostpassword(): void
    {
        self::print_if('lostpassword');
    }

    /** Classic checkout: printed only on the hook of the chosen position; forms.js does the rest. */
    public static function print_for_woo_checkout(): void
    {
        if (! Selfcap_Plugin::protects('woo_checkout')) {
            return;
        }

        $options = Selfcap_Plugin::options();
        $hooks = Selfcap_Plugin::CHECKOUT_HOOKS;
        $position = isset($hooks[$options['checkout_position']]) ? $options['checkout_position'] : 'button';

        if (current_action() !== $hooks[$position]) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo Selfcap_Plugin::widget(['position' => $position, 'selector' => $options['checkout_selector']]);
    }

    /** Pay for order: always right above its pay button. */
    public static function print_for_woo_pay(): void
    {
        if (Selfcap_Plugin::protects('woo_checkout')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo Selfcap_Plugin::widget(['position' => 'button']);
        }
    }

    /** Requests that never show the form (XML-RPC, REST, application passwords) are not checked. */
    private static function is_form_post(): bool
    {
        if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }

        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * wp-login.php. The entry point decides, never a field the client sends, so no
     * request field can switch the check off. Logins started by other plugins'
     * own forms (no widget there) pass through.
     */
    public static function check_login($user, $username, $password)
    {
        if (! self::is_form_post() || $username === '' || $username === null || ! Selfcap_Plugin::protects('login')) {
            return $user;
        }

        // login_init fires only on wp-login.php, also when a "custom login URL"
        // plugin serves it from another address; a client cannot trigger it.
        if (! did_action('login_init') && ($GLOBALS['pagenow'] ?? '') !== 'wp-login.php') {
            return $user;
        }

        $result = Selfcap_Plugin::verify();

        return is_wp_error($result) ? $result : $user;
    }

    /** WooCommerce's own login check, run before it signs the user in, whatever nonce field it got. */
    public static function check_woo_login($errors)
    {
        if (! Selfcap_Plugin::protects('login')) {
            return $errors;
        }

        $result = Selfcap_Plugin::verify();

        if (is_wp_error($result) && is_wp_error($errors)) {
            $errors->add($result->get_error_code(), $result->get_error_message());
        }

        return $errors;
    }

    public static function login_failed(): void
    {
        if (Selfcap_Plugin::is_enabled()) {
            Selfcap_Plugin::engine()->record('login_fail', Selfcap_Plugin::client_ip());
        }
    }

    public static function print_for_signup($errors): void
    {
        if (Selfcap_Plugin::protects('register') && ! is_user_logged_in()) {
            if (is_wp_error($errors) && $errors->get_error_message('selfcap')) {
                echo '<p class="error">'.esc_html($errors->get_error_message('selfcap')).'</p>';
            }

            echo Selfcap_Plugin::widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /** Runs for visitors signing up on wp-signup.php; network admins adding users are left alone. */
    public static function check_signup($result)
    {
        if (! Selfcap_Plugin::protects('register') || is_admin() || is_user_logged_in() || ! self::is_form_post() || ! isset($result['errors']) || ! is_wp_error($result['errors'])) {
            return $result;
        }

        $verified = Selfcap_Plugin::verify();

        if (is_wp_error($verified)) {
            $result['errors']->add('selfcap', $verified->get_error_message());
        }

        return $result;
    }

    public static function check_woo_pay($order): void
    {
        if (! Selfcap_Plugin::protects('woo_checkout')) {
            return;
        }

        $result = Selfcap_Plugin::verify();

        // An error notice stops WooCommerce before it calls the payment gateway.
        if (is_wp_error($result) && function_exists('wc_add_notice')) {
            wc_add_notice($result->get_error_message(), 'error');
        }
    }

    public static function xmlrpc_enabled($enabled)
    {
        return Selfcap_Plugin::protects('login') && ! empty(Selfcap_Plugin::options()['block_xmlrpc']) ? false : $enabled;
    }

    public static function check_register($errors)
    {
        if (Selfcap_Plugin::protects('register')) {
            $result = Selfcap_Plugin::verify();

            if (is_wp_error($result)) {
                $errors->add($result->get_error_code(), $result->get_error_message());
            }
        }

        return $errors;
    }

    /** wp-login.php and WooCommerce both end in retrieve_password(), which fires this. */
    public static function check_lostpassword($errors): void
    {
        // An administrator sending a reset link from the Users screen is not a visitor.
        if (! Selfcap_Plugin::protects('lostpassword') || (is_user_logged_in() && current_user_can('edit_users'))) {
            return;
        }

        $result = Selfcap_Plugin::verify();

        if (is_wp_error($result) && is_wp_error($errors)) {
            $errors->add($result->get_error_code(), $result->get_error_message());
        }
    }

    public static function comment_field(string $submit_field): string
    {
        if (! Selfcap_Plugin::protects('comments') || current_user_can('moderate_comments')) {
            return $submit_field;
        }

        return Selfcap_Plugin::widget().$submit_field;
    }

    public static function check_comment(array $commentdata): array
    {
        $type = $commentdata['comment_type'] ?? '';

        if (! Selfcap_Plugin::protects('comments') || current_user_can('moderate_comments') || in_array($type, ['pingback', 'trackback'], true) || ! self::is_form_post()) {
            return $commentdata;
        }

        $result = Selfcap_Plugin::verify();

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => 403, 'back_link' => true]);
        }

        return $commentdata;
    }

    public static function check_woo_register($errors)
    {
        // Only the My Account form runs this filter; accounts created while placing
        // an order are covered by the checkout option.
        if (Selfcap_Plugin::protects('register')) {
            $result = Selfcap_Plugin::verify();

            if (is_wp_error($result)) {
                $errors->add($result->get_error_code(), $result->get_error_message());
            }
        }

        return $errors;
    }

    public static function check_woo_checkout($data, $errors): void
    {
        if (Selfcap_Plugin::protects('woo_checkout')) {
            $result = Selfcap_Plugin::verify();

            if (is_wp_error($result)) {
                $errors->add($result->get_error_code(), $result->get_error_message());
            }
        }
    }

    // ---- WooCommerce block checkout -------------------------------------

    /** Lets the block checkout send the token along with the order request. */
    public static function block_checkout_data(): void
    {
        if (! function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'checkout',
            'namespace' => self::STORE_API_NAMESPACE,
            'schema_callback' => static function (): array {
                return [
                    'token' => [
                        'description' => 'Selfcap response token',
                        'type' => 'string',
                        'context' => [],
                        'optional' => true,
                    ],
                ];
            },
        ]);
    }

    /** Prints the widget right above the "Place order" button of the block checkout. */
    public static function block_checkout_widget($content)
    {
        if (! Selfcap_Plugin::protects('woo_checkout')) {
            return $content;
        }

        // Its own class: the checkout block rebuilds this markup in the browser,
        // so forms.js renders the widget once the block is on the page.
        return str_replace('class="self-captcha"', 'class="self-captcha-block"', Selfcap_Plugin::widget()).$content;
    }

    public static function check_block_checkout($order, $request): void
    {
        // The checkout also sends PUT requests while the visitor edits the form
        // after a failed payment; only placing the order (POST) spends the token.
        if (! Selfcap_Plugin::protects('woo_checkout') || ! is_object($request) || ! method_exists($request, 'get_method') || $request->get_method() !== 'POST') {
            return;
        }

        $extensions = isset($request['extensions']) ? (array) $request['extensions'] : [];
        $data = isset($extensions[self::STORE_API_NAMESPACE]) ? (array) $extensions[self::STORE_API_NAMESPACE] : [];
        $token = isset($data['token']) && is_string($data['token']) ? sanitize_text_field($data['token']) : '';

        $result = Selfcap_Plugin::verify($token);

        if (is_wp_error($result)) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'selfcap', // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                esc_html($result->get_error_message()),
                400
            );
        }
    }

    // ---- Contact Form 7 -------------------------------------------------

    public static function cf7_tag(): void
    {
        if (function_exists('wpcf7_add_form_tag')) {
            wpcf7_add_form_tag('selfcap', [Selfcap_Plugin::class, 'widget']);
        }
    }

    public static function check_cf7($spam, $submission = null)
    {
        if ($spam || ! Selfcap_Plugin::protects('cf7')) {
            return $spam;
        }

        $form = function_exists('wpcf7_get_current_contact_form') ? wpcf7_get_current_contact_form() : null;

        // Only forms that actually include the [selfcap] tag are checked.
        if (! $form || empty($form->scan_form_tags(['type' => 'selfcap']))) {
            return $spam;
        }

        return is_wp_error(Selfcap_Plugin::verify());
    }
}
