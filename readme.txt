=== Self Captcha Standalone ===
Contributors: YOUR_WPORG_USERNAME
Tags: captcha, spam, security, woocommerce, anti-spam
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A privacy-friendly captcha that runs entirely inside your WordPress site. No third-party service, no account, no API keys.

== Description ==

Self Captcha Standalone protects your forms with a simple "I'm not a robot" checkbox. Everything happens on your own site: WordPress issues the challenge, checks the answer, keeps the log and blocks attackers. Nothing is sent to another company, and there is nothing to sign up for.

**How it protects**

* **Proof-of-work.** The visitor's browser solves a small computation in the background. People do not notice it; bots sending thousands of requests pay for every one.
* **Browser signals.** Automated browsers, scripted clicks and fake user agents are refused.
* **One-time tokens.** Each solved captcha is good for a single form submission from the same address, and expires in 5 minutes. Tokens solved elsewhere are refused.
* **Rate limit and adaptive difficulty.** The more an address asks, the harder each challenge gets.
* **Automatic blocking.** Addresses that behave like an attack are blocked temporarily (15 minutes, then 1 hour, then 24 hours). A customer who fails a few times is never blocked, nor are the people sharing their address.
* **No side doors.** Login over XML-RPC is closed, and the WordPress and WooCommerce forms share one setting, so neither is a way around the other.

**Forms**

* Login, registration, lost password and comments, on wp-login.php, WooCommerce My Account and multisite sign-up
* WooCommerce checkout, in both the block checkout and the classic one, plus the Pay for order page
* Contact Form 7, with the `[self_captcha]` tag
* Any other form, with the `[self_captcha]` shortcode and `self_captcha_verify()`

**Dashboard**

* Totals and a daily chart for the last 7, 30 or 90 days
* Verification log with result, score, reason, IP address and browser
* IP rules: block or allow an address or a CIDR range, permanently or for a while
* Real visitor IP behind Cloudflare, a load balancer or your own proxy

**Languages**

The widget and the admin screen come in English, Portuguese and Spanish. The widget follows the visitor's browser and the admin follows WordPress, or you can force a language in the settings.

**Privacy**

This plugin does not contact any external service. It stores, on your own database and for a limited time (30 days by default), the IP address, browser user agent and result of each verification. A suggested paragraph for your privacy policy is added under Settings > Privacy.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate it. Login, registration, lost password and comments are protected right away.
3. Open **Self Captcha > Settings** to choose which forms to protect, including WooCommerce and Contact Form 7 when those plugins are active.

For Contact Form 7, add the `[self_captcha]` tag to the form.

== Frequently Asked Questions ==

= Does it send any data to a third party? =

No. The challenge, the validation, the log and the blocking all run on your own site.

= I am locked out of the login. What now? =

Add `define('SELF_CAPTCHA_DISABLE', true);` to `wp-config.php`. That switches the captcha off until you remove the line.

= Does it work with page caching? =

Yes. The widget fetches its challenge after the page loads, so cached pages are fine.

= Does it need the REST API? =

Yes. The widget talks to `/wp-json/self-captcha/v1/` on your own site. If a security plugin closes the REST API to visitors, leave that route open.

= My site is behind Cloudflare or a load balancer. Will it see the right IP? =

Yes. Forwarding headers are read only when the connection comes from Cloudflare, a private address or a proxy you list in the settings, so a visitor cannot forge them. The settings screen shows the address detected for you.

= Can a determined bot get through? =

Browser signals can be forged by a dedicated bot. The real cost comes from the proof-of-work, the rate limit, the one-time tokens and the automatic blocking, which make large-scale abuse expensive.

= How do I protect a custom form? =

Print the widget with `echo do_shortcode('[self_captcha]');` inside the form and call `self_captcha_verify()` in the code that handles the submission. It returns true or false.

= Does it work with express payment buttons (Apple Pay, Google Pay)? =

Those buttons place the order without showing the checkout form, so with the WooCommerce checkout protected they are refused until the visitor ticks the box. Test them on your store, and turn the checkout option off if you rely on them.

= I use the WordPress mobile app. =

The app logs in over XML-RPC, which this plugin closes by default because attackers use it to get around the login captcha. Turn off "Block logins over XML-RPC" in the settings.

= Can I change the thresholds? =

Yes, with the `self_captcha_config` filter (timeouts, rate limit, blocking thresholds). Extra hostnames for the same site can be added with the `self_captcha_hostnames` filter.

= Where is the source of the widget? =

The readable source of `assets/widget.js` is in `assets/src/`. It is bundled with esbuild.

== Screenshots ==

1. Overview: totals, daily chart and verification log.
2. The widget above the "Place order" button of the WooCommerce checkout.
3. IP rules, manual and automatic.
4. Settings.
5. The widget on the login screen.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
