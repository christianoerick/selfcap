<?php
/**
 * Plugin Name: Selfcap – Self-Hosted Captcha
 * Description: A privacy-friendly captcha that runs entirely inside your WordPress site: a checkbox widget backed by proof-of-work, with no third-party service and no external server.
 * Version: 1.0.1
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Plugin URI: https://github.com/christianoerick/selfcap
 * Author: Christiano Erick
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: selfcap
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SELFCAP_VERSION', '1.0.1');
define('SELFCAP_DB_VERSION', '1');
define('SELFCAP_FILE', __FILE__);
define('SELFCAP_FIELD', 'captcha-response');

require_once __DIR__.'/includes/class-selfcap-ip.php';
require_once __DIR__.'/includes/class-selfcap-store.php';
require_once __DIR__.'/includes/class-selfcap-engine.php';
require_once __DIR__.'/includes/class-selfcap-plugin.php';
require_once __DIR__.'/includes/class-selfcap-rest.php';
require_once __DIR__.'/includes/class-selfcap-forms.php';
require_once __DIR__.'/includes/class-selfcap-admin.php';

register_activation_hook(__FILE__, ['Selfcap_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Selfcap_Plugin', 'deactivate']);

Selfcap_Plugin::register();
Selfcap_Rest::register();
Selfcap_Forms::register();
Selfcap_Admin::register();

if (! function_exists('selfcap_verify')) {
    /**
     * Validates the captcha of the current request. For custom forms:
     *
     *     echo do_shortcode('[selfcap]');           // in the form
     *     if (! selfcap_verify()) { wp_die('...'); } // in the handler
     */
    function selfcap_verify(?string $token = null): bool
    {
        return Selfcap_Plugin::verify($token) === true;
    }
}
