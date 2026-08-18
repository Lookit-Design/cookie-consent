=== Lookit Cookie Consent ===
Contributors: lookitdesign
Tags: cookie, consent, iubenda, gdpr, ccpa
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A customizable cookie consent popup that records consent to the iubenda Consent Database.

== Description ==

A lightweight, customizable cookie consent popup that records consent directly to iubenda's Consent Database REST API, so you do not need iubenda's own front-end banner. Configure everything from Settings, Cookie Consent in your WordPress admin.

Features:
* Customizable body text (HTML supported)
* Customizable button labels
* Three display styles: Panel, Small card, and Corner pill
* Brand color pickers (accent, background, text)
* Optional logo image
* Configurable cookie duration
* Records consent to the iubenda Consent Database
* Keyboard accessible (Escape = reject)
* Mobile responsive
* Admin preview button

== External Services ==

This plugin connects to the iubenda Consent Database API (https://www.iubenda.com) to record each visitor's cookie consent choice. When a visitor accepts or rejects cookies, the plugin sends their consent choice, their granular preference selections, a subject identifier, and their IP address to iubenda using the public API key you configure in the plugin settings. No data is sent until a visitor interacts with the consent popup, and nothing is sent if no iubenda key is configured.

* Service: iubenda Consent Database (https://www.iubenda.com)
* Data sent: consent choice, granular preferences, subject ID, and IP address
* When: when a visitor accepts or rejects cookies in the popup
* Terms of Service: https://www.iubenda.com/en/terms-and-conditions
* Privacy Policy: https://www.iubenda.com/privacy-policy/

== Installation ==

1. Upload the `lookit-cookie-consent` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress Admin, Plugins
3. Go to Settings, Cookie Consent to configure
4. Add your iubenda public API key in the settings to enable consent recording

== Changelog ==

= 3.2.2 =
* Set the Secure flag on the consent cookie over HTTPS, and require manage_options before rendering the settings screen.

= 3.2.1 =
* Plugin Check compliance pass: unified the text domain to the plugin slug, added a Text Domain header, escaped all front-end output, added wp_unslash()/sanitization to all input, switched to wp_safe_redirect(), guarded debug logging behind WP_DEBUG_LOG, added an External Services disclosure, aligned the readme name with the plugin header, and set Tested up to 7.0.

= 3.2.0 =
* New "Display Style" setting with three layouts: Panel (full popup, default), Small card (compact, stacked buttons), and Corner pill (minimal one-line strip that expands to the full card via the Customize link).
* Card and pill styles reuse the existing consent + iubenda recording logic, only the layout changes. Sale/sharing/targeted-advertising toggles stay hidden behind "Customize" in all styles.
* Existing installs are unaffected: display style defaults to Panel.

= 1.0.0 =
* Initial release
