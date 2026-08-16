<?php
/**
 * Plugin Name: Lookit Cookie Consent
 * Plugin URI:  https://lookitai.com
 * Description: Custom cookie consent popup for any site. Records consent directly to iubenda's Consent Database REST API — no iubenda frontend banner needed. Fully configurable from WordPress admin.
 * Version:     3.2.1
 * Author:      Lookit AI
 * License:     GPL-2.0+
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: lookit-cookie-consent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOOKIT_CC_VERSION', '3.2.1' );
define( 'LOOKIT_CC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOOKIT_CC_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'admin_menu',
	function () {
		add_options_page( '🍪 Lookit Cookie Consent', 'Cookie Consent', 'manage_options', 'lookit-cookie-consent', 'lookit_cc_settings_page' );
	}
);
add_action(
	'admin_init',
	function () {
		register_setting( 'lookit_cc_settings', 'lookit_cc_options', array( 'sanitize_callback' => 'lookit_cc_sanitize' ) );
	}
);

function lookit_cc_sanitize( $input ) {
	$clean                     = array();
	$clean['enabled']          = ! empty( $input['enabled'] ) ? 1 : 0;
	$clean['display_style']    = in_array( $input['display_style'] ?? '', array( 'panel', 'card', 'pill' ), true ) ? $input['display_style'] : 'panel';
	$clean['body_text']        = wp_kses_post( $input['body_text'] ?? '' );
	$clean['accept_label']     = sanitize_text_field( $input['accept_label'] ?? 'Accept all' );
	$clean['reject_label']     = sanitize_text_field( $input['reject_label'] ?? 'Reject all' );
	$clean['learn_more_label'] = sanitize_text_field( $input['learn_more_label'] ?? 'Customize' );
	$clean['position']         = in_array( $input['position'] ?? '', array( 'bottom-left', 'bottom-right', 'bottom-center', 'top-center' ), true ) ? $input['position'] : 'bottom-left';
	$clean['accent_color']     = sanitize_hex_color( $input['accent_color'] ?? '#1a3c5e' );
	$clean['bg_color']         = sanitize_hex_color( $input['bg_color'] ?? '#ffffff' );
	$clean['text_color']       = sanitize_hex_color( $input['text_color'] ?? '#333333' );
	$clean['logo_url']         = esc_url_raw( $input['logo_url'] ?? '' );
	$clean['cookie_duration']  = absint( $input['cookie_duration'] ?? 365 );
	// v2.5.0: iubenda Consent Database public API key
	$clean['iubenda_public_key'] = sanitize_text_field( $input['iubenda_public_key'] ?? '' );
	// v2.5.0: cookie policy ID (for legal_notices reference)
	$clean['iubenda_policy_id'] = sanitize_text_field( $input['iubenda_policy_id'] ?? '' );
	return $clean;
}

function lookit_cc_get_options() {
	$defaults = array(
		'enabled'            => 1,
		'display_style'      => 'panel',
		'body_text'          => '',
		'accept_label'       => 'Accept all',
		'reject_label'       => 'Reject all',
		'learn_more_label'   => 'Learn More',
		'position'           => 'bottom-left',
		'accent_color'       => '#1a3c5e',
		'bg_color'           => '#ffffff',
		'text_color'         => '#333333',
		'logo_url'           => '',
		'cookie_duration'    => 365,
		'iubenda_public_key' => '',
		'iubenda_policy_id'  => '',
	);
	return wp_parse_args( get_option( 'lookit_cc_options', array() ), $defaults );
}

/* ── AJAX endpoint: proxy POST to iubenda Consent Database ─────── */
/*
 * We proxy through WordPress AJAX so we never expose the public key
 * in JS (even though it's technically public, this keeps things clean).
 * The actual POST goes server-side via wp_remote_post().
 */
add_action( 'wp_ajax_nopriv_lookit_cc_record', 'lookit_cc_ajax_record' );
add_action( 'wp_ajax_lookit_cc_record', 'lookit_cc_ajax_record' );

function lookit_cc_ajax_record() {
	// Verify nonce
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lookit_cc_record' ) ) {
		wp_send_json_error( 'Invalid nonce', 403 );
	}

	$opts       = lookit_cc_get_options();
	$public_key = $opts['iubenda_public_key'];
	$policy_id  = $opts['iubenda_policy_id'];

	if ( empty( $public_key ) ) {
		// No key set — silently succeed (consent still stored in our cookie)
		wp_send_json_success( array( 'skipped' => true ) );
	}

	$accepted   = ( isset( $_POST['accepted'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['accepted'] ) ) );
	$subject_id = isset( $_POST['subject_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subject_id'] ) ) : '';
	$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	/* Parse granular preferences from Preferences tab toggles */
	$raw_prefs  = isset( $_POST['preferences'] ) ? sanitize_text_field( wp_unslash( $_POST['preferences'] ) ) : '';
	$prefs_json = json_decode( $raw_prefs, true );

	/* Build preferences object — merge granular + overall consent */
	$preferences      = array( 'cookie_consent' => $accepted );
	$allowed_purposes = array(
		'functionality',
		'experience',
		'measurement',
		'marketing',
		'sale_of_personal_information',
		'sharing_of_personal_information',
		'targeted_advertising',
	);
	if ( is_array( $prefs_json ) ) {
		foreach ( $allowed_purposes as $purpose ) {
			if ( isset( $prefs_json[ $purpose ] ) ) {
				$preferences[ $purpose ] = (bool) $prefs_json[ $purpose ];
			}
		}
	}

	/* Build proof message */
	if ( is_array( $prefs_json ) && count( $prefs_json ) > 0 ) {
		$proof_msg = 'User saved granular preferences via Preferences tab';
	} elseif ( $accepted ) {
		$proof_msg = 'User clicked Accept all';
	} else {
		$proof_msg = 'User clicked Reject all';
	}

	// Build payload for POST /public/consent
	$payload = array(
		'subject'     => array( 'id' => $subject_id ),
		'preferences' => $preferences,
		'ip_address'  => $ip,
		'proofs'      => array(
			array(
				'content' => $proof_msg,
				'form'    => 'Lookit Cookie Consent popup v3.2.0',
			),
		),
	);

	// Add legal notices if policy ID is set
	if ( ! empty( $policy_id ) ) {
		$payload['legal_notices'] = array(
			array( 'identifier' => 'cookie_policy' ),
			array( 'identifier' => 'privacy_policy' ),
		);
	}

	$response = wp_remote_post(
		'https://consent.iubenda.com/public/consent',
		array(
			'headers' => array(
				'ApiKey'       => $public_key,
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		// Log but don't break the user experience
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Lookit CC: iubenda API error - ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- guarded by WP_DEBUG_LOG, diagnostics only.
		}
		wp_send_json_success( array( 'warning' => 'API call failed but consent cookie was set' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( $code >= 200 && $code < 300 ) {
		wp_send_json_success( json_decode( $body, true ) );
	} else {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Lookit CC: iubenda API returned ' . $code . ' - ' . $body ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- guarded by WP_DEBUG_LOG, diagnostics only.
		}
		// Still succeed from user's perspective — our cookie is set
		wp_send_json_success( array( 'warning' => 'API returned ' . $code ) );
	}
}

/* ── Settings page ───────────────────────────────────────────────── */
function lookit_cc_settings_page() {
	$opts = lookit_cc_get_options();

	/* First-run detection: show setup wizard if key fields are empty */
	$is_first_run = empty( $opts['iubenda_public_key'] ) && empty( $opts['body_text'] );

	if ( isset( $_GET['lookit_reset'] ) && check_admin_referer( 'lookit_reset' ) ) {
		setcookie( 'lookit_cc_consent', '', time() - 3600, '/', '', is_ssl(), true );
		wp_safe_redirect( admin_url( 'options-general.php?page=lookit-cookie-consent&lookit_reset_done=1' ) );
		exit;
	}
	if ( isset( $_GET['lookit_reset_done'] ) ) {
		echo '<div class="notice notice-success"><p>Consent cookie cleared. Reload the front end to see the popup.</p></div>';
	}
	?>
	<div class="wrap">
		<h1>🍪 Lookit Cookie Consent <span style="font-size:13px;color:#888;font-weight:400;">v3.2.0</span></h1>

		<?php if ( $is_first_run ) : ?>
		<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:16px 20px;margin-bottom:24px;border-radius:4px;">
			<h2 style="margin:0 0 10px;font-size:16px;">&#x1F44B; Welcome! Get set up in 4 steps.</h2>
			<ol style="margin:0;padding-left:20px;line-height:2.2;">
				<li>In <strong>iubenda</strong>: go to your site &rarr; <strong>Consent Database &rarr; Configure</strong> &rarr; copy your <strong>Public API Key</strong>.</li>
				<li>Paste the <strong>Public API Key</strong> and <strong>Cookie Policy ID</strong> in the fields below.</li>
				<li>Write your <strong>Popup Body Text</strong> (use the sample text button to get started quickly).</li>
				<li>In the iubenda WordPress plugin, turn <strong>OFF Privacy Controls and Cookie Solution</strong>.</li>
			</ol>
			<p style="margin:10px 0 0;font-size:12px;color:#666;">This guide disappears once you save your settings.</p>
		</div>
		<?php else : ?>
		<div style="background:#e8f5e9;border-left:4px solid #4caf50;padding:10px 16px;margin-bottom:20px;border-radius:4px;font-size:13px;">
			&#x2705; <strong>Active:</strong> Consent is recording to iubenda's Consent Database via REST API. No iubenda frontend JS needed.
		</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'lookit_cc_settings' ); ?>
			<table class="form-table" role="presentation">

				<tr>
					<th>Enable Popup</th>
					<td><label><input type="checkbox" name="lookit_cc_options[enabled]" value="1" <?php checked( $opts['enabled'], 1 ); ?>> Show popup to visitors</label></td>
				</tr>

				<tr style="background:#f0f7ff;">
					<th><label for="lookit_cc_pub_key">iubenda Public API Key</label></th>
					<td>
						<input id="lookit_cc_pub_key" type="text" name="lookit_cc_options[iubenda_public_key]"
							value="<?php echo esc_attr( $opts['iubenda_public_key'] ); ?>"
							class="large-text" placeholder="e.g. abc123xyz...">
						<p class="description">
							<strong>Where to find it:</strong> iubenda Dashboard &rarr; your site &rarr; Consent Database &rarr; <strong>Configure</strong> &rarr; <strong>Public API Key</strong>.<br>
							This is your <em>public</em> key (write-only) — safe to use here. It records consent to iubenda's database without any frontend JS.
						</p>
					</td>
				</tr>

				<tr style="background:#f0f7ff;">
					<th><label for="lookit_cc_policy_id">iubenda Cookie Policy ID</label></th>
					<td>
						<input id="lookit_cc_policy_id" type="text" name="lookit_cc_options[iubenda_policy_id]"
							value="<?php echo esc_attr( $opts['iubenda_policy_id'] ); ?>"
							class="regular-text" placeholder="e.g. 85306165">
						<p class="description">
							Your cookie policy ID — visible in the URL of your iubenda cookie policy page (e.g. iubenda.com/privacy-policy/<strong>85306165</strong>/cookie-policy).<br>
							Used to reference the correct legal notice when recording consent.
						</p>
					</td>
				</tr>

				<tr>
					<th><label for="lookit_cc_body">Popup Body Text</label></th>
					<td>
						<textarea id="lookit_cc_body" name="lookit_cc_options[body_text]" rows="10" class="large-text" placeholder="We and selected third parties collect personal information as specified in the &lt;a href=&quot;YOUR_PRIVACY_POLICY_URL&quot; target=&quot;_blank&quot;&gt;privacy policy&lt;/a&gt; and use cookies..."><?php echo esc_textarea( $opts['body_text'] ); ?></textarea>
						<p class="description">HTML allowed. Include links to your privacy policy and cookie policy. Use <code>&amp;ldquo;</code> and <code>&amp;rdquo;</code> for curly quotes.</p>
					</td>
				</tr>

				<tr>
					<th>Button Labels</th>
					<td>
						<label>Accept: <input type="text" name="lookit_cc_options[accept_label]" value="<?php echo esc_attr( $opts['accept_label'] ); ?>" class="regular-text"></label><br><br>
						<label>Reject: <input type="text" name="lookit_cc_options[reject_label]" value="<?php echo esc_attr( $opts['reject_label'] ); ?>" class="regular-text"></label><br><br>
						<label>Customize: <input type="text" name="lookit_cc_options[learn_more_label]" value="<?php echo esc_attr( $opts['learn_more_label'] ); ?>" class="regular-text"></label>
					</td>
				</tr>

				<tr>
					<th><label for="lookit_cc_position">Position</label></th>
					<td>
						<select id="lookit_cc_position" name="lookit_cc_options[position]">
							<?php
							foreach ( array(
								'bottom-left'   => 'Bottom Left',
								'bottom-right'  => 'Bottom Right',
								'bottom-center' => 'Bottom Center',
								'top-center'    => 'Top Center',
							) as $val => $label ) {
								echo '<option value="' . esc_attr( $val ) . '"' . selected( $opts['position'], $val, false ) . '>' . esc_html( $label ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>

				<tr>
					<th><label for="lookit_cc_display_style">Display Style</label></th>
					<td>
						<select id="lookit_cc_display_style" name="lookit_cc_options[display_style]">
							<?php
							foreach ( array(
								'panel' => 'Panel — full popup (default)',
								'card'  => 'Small card — compact, buttons stacked',
								'pill'  => 'Corner pill — tiny strip, expands on Customize',
							) as $val => $label ) {
								echo '<option value="' . esc_attr( $val ) . '"' . selected( $opts['display_style'], $val, false ) . '>' . esc_html( $label ) . '</option>';
							}
							?>
						</select>
						<p class="description">
							<strong>Panel</strong> shows the full notice with tabs. <strong>Small card</strong> is a tighter version of the same popup. <strong>Corner pill</strong> starts as a minimal one-line strip; clicking the Customize link expands it to the full card so the sale/sharing toggles stay tucked away until asked for.
						</p>
					</td>
				</tr>
					<td>
						<label>Accent: <input type="color" name="lookit_cc_options[accent_color]" value="<?php echo esc_attr( $opts['accent_color'] ); ?>"></label>&nbsp;&nbsp;
						<label>Background: <input type="color" name="lookit_cc_options[bg_color]" value="<?php echo esc_attr( $opts['bg_color'] ); ?>"></label>&nbsp;&nbsp;
						<label>Text: <input type="color" name="lookit_cc_options[text_color]" value="<?php echo esc_attr( $opts['text_color'] ); ?>"></label>
					</td>
				</tr>

				<tr>
					<th><label for="lookit_cc_logo">Logo URL</label></th>
					<td><input id="lookit_cc_logo" type="url" name="lookit_cc_options[logo_url]" value="<?php echo esc_attr( $opts['logo_url'] ); ?>" class="large-text" placeholder="https://..."></td>
				</tr>

				<tr>
					<th><label for="lookit_cc_duration">Cookie Duration (days)</label></th>
					<td><input id="lookit_cc_duration" type="number" name="lookit_cc_options[cookie_duration]" value="<?php echo esc_attr( $opts['cookie_duration'] ); ?>" min="1" max="730" class="small-text"></td>
				</tr>

			</table>
			<?php submit_button( 'Save Settings' ); ?>
		</form>

		<hr>
		<h2>Setup Guide</h2>

		<h3 style="margin-bottom:6px;">Step 1 — iubenda</h3>
		<ol>
			<li>In your iubenda dashboard go to your site &rarr; <strong>Consent Database &rarr; Configure</strong>. Enable it if not already on.</li>
			<li>Copy your <strong>Public API Key</strong> and paste it in the field above.</li>
			<li>Copy your <strong>Cookie Policy ID</strong> (visible in your iubenda cookie policy URL: <code>iubenda.com/privacy-policy/<strong>XXXXXXX</strong>/cookie-policy</code>) and paste it above.</li>
			<li>In the iubenda WordPress plugin, <strong>turn OFF Privacy Controls and Cookie Solution</strong> — our plugin handles everything.</li>
		</ol>

		<h3 style="margin-bottom:6px;">Step 2 — Popup Body Text example</h3>
		<p style="color:#555;font-size:13px;">Replace <code>YOUR_SITE_NAME</code> and <code>YOUR_POLICY_ID</code> with your own values:</p>
		<div style="background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:14px 16px;font-size:13px;line-height:1.7;margin-bottom:16px;">
			<code style="font-family:monospace;white-space:pre-wrap;display:block;">We (YOUR_SITE_NAME) and selected third parties collect personal information as specified in the &lt;a href="https://www.iubenda.com/privacy-policy/YOUR_POLICY_ID/legal?an=no&amp;amp;s_ck=false&amp;amp;newmarkup=yes" target="_blank"&gt;privacy policy&lt;/a&gt; and use cookies or similar technologies for technical purposes and, with your consent, for &lt;strong&gt;experience, measurement and personalized ads&lt;/strong&gt; as specified in the &lt;a href="https://www.iubenda.com/privacy-policy/YOUR_POLICY_ID/cookie-policy?an=no&amp;amp;s_ck=false&amp;amp;newmarkup=yes" target="_blank"&gt;cookie policy&lt;/a&gt;. You can freely give, deny, or withdraw your consent using the options in this panel. Denying consent may make related features unavailable but will not prevent access from content on this website.

&lt;p style="margin-top:10px"&gt;Use the &amp;ldquo;Accept all&amp;rdquo; button to consent. Use the &amp;ldquo;Reject all&amp;rdquo; button to continue without accepting.&lt;/p&gt;</code>
		</div>

		<h3 style="margin-bottom:6px;">Step 3 — WP Rocket (if enabled)</h3>
		<p style="color:#555;font-size:13px;">If you use WP Rocket, add the following exclusions to prevent caching from breaking the popup:</p>
		<table style="border-collapse:collapse;width:100%;font-size:13px;margin-bottom:8px;">
			<thead>
				<tr style="background:#f0f0f0;">
					<th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">WP Rocket Setting</th>
					<th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Location</th>
					<th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Value to add</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td style="padding:8px 12px;border:1px solid #ddd;">Excluded CSS Files</td>
					<td style="padding:8px 12px;border:1px solid #ddd;">File Optimization &rarr; CSS Files</td>
					<td style="padding:8px 12px;border:1px solid #ddd;"><code>/wp-content/plugins/lookit-cookie-consent/(.*).css</code></td>
				</tr>
				<tr style="background:#fafafa;">
					<td style="padding:8px 12px;border:1px solid #ddd;">CSS Safelist</td>
					<td style="padding:8px 12px;border:1px solid #ddd;">File Optimization &rarr; CSS Safelist</td>
					<td style="padding:8px 12px;border:1px solid #ddd;"><code>#lookit-cc-popup</code><br><code>#lookit-cc-styles</code><br><code>.lookit-tab</code><br><code>.lookit-panel</code><br><code>.lookit-toggle</code><br><code>.lookit-purpose</code></td>
				</tr>
				<tr>
					<td style="padding:8px 12px;border:1px solid #ddd;">Excluded JS Files (Minify)</td>
					<td style="padding:8px 12px;border:1px solid #ddd;">File Optimization &rarr; JavaScript Files</td>
					<td style="padding:8px 12px;border:1px solid #ddd;"><code>lookit-cc-script</code></td>
				</tr>
				<tr style="background:#fafafa;">
					<td style="padding:8px 12px;border:1px solid #ddd;">Excluded JS Files (Defer)</td>
					<td style="padding:8px 12px;border:1px solid #ddd;">File Optimization &rarr; JavaScript Files</td>
					<td style="padding:8px 12px;border:1px solid #ddd;"><code>lookit-cc-script</code></td>
				</tr>
			</tbody>
		</table>
		<p style="color:#555;font-size:12px;">After adding these, click <strong>Save Changes</strong> in WP Rocket then <strong>Clear and Preload</strong> cache.<br><strong>Note v3.1+:</strong> Also add <code>.lookit-tab</code>, <code>.lookit-panel</code>, <code>.lookit-toggle</code>, <code>.lookit-purpose</code> to the CSS safelist.</p>

		<h3 style="margin-bottom:6px;">Step 4 — Test</h3>
		<ol>
			<li>Visit your site in an incognito window.</li>
			<li>Click <strong>Accept all</strong> or <strong>Reject all</strong>.</li>
			<li>Check your iubenda <strong>Consent Database dashboard</strong> — a new record should appear within seconds.</li>
		</ol>

		<hr>
		<h2>Preview &amp; Tools</h2>
		<p>
			<button class="button button-secondary" onclick="lookitCCPreview(); return false;">Preview Popup</button>&nbsp;
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'lookit_reset', '1' ), 'lookit_reset' ) ); ?>" class="button">Reset consent cookie</a>
		</p>
	</div>
	<script>
	function lookitCCPreview() {
		document.cookie = 'lookit_cc_consent=; Max-Age=0; path=/';
		window.lookitCCShow && window.lookitCCShow();
		if (!window.lookitCCShow) alert('Save settings first, then reload this page to preview.');
	}
	</script>
	<?php
}

/* ── Frontend output ─────────────────────────────────────────────── */

/* Output critical CSS in <head> so WP Rocket Remove Unused CSS cannot strip it */
add_action( 'wp_head', 'lookit_cc_head_styles', 99 );
function lookit_cc_head_styles() {
	$opts = lookit_cc_get_options();
	if ( empty( $opts['enabled'] ) ) {
		return;
	}
	$accent = $opts['accent_color'];
	$bg     = $opts['bg_color'];
	?>
	<style id="lookit-cc-critical">
	/* Lookit Cookie Consent v3.1.2 — critical styles in head to survive Remove Unused CSS */
	#lookit-cc-popup { display:block; }
	.lookit-tabs { display:flex; }
	.lookit-tab { display:inline-block; cursor:pointer; }
	.lookit-tab.active { border-bottom:3px solid <?php echo esc_attr( $accent ); ?>; color:<?php echo esc_attr( $accent ); ?>; }
	.lookit-panel { display:none; }
	.lookit-panel.active { display:block; }
	.lookit-purpose { display:flex; }
	.lookit-toggle { display:inline-block; position:relative; width:44px; height:24px; }
	.lookit-toggle input { opacity:0; width:0; height:0; }
	.lookit-toggle-slider { position:absolute; cursor:pointer; inset:0; background:#ccc; border-radius:24px; transition:.3s; }
	.lookit-toggle-slider:before { content:""; position:absolute; width:18px; height:18px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; }
	.lookit-toggle input:checked + .lookit-toggle-slider { background:<?php echo esc_attr( $accent ); ?>; }
	.lookit-toggle input:checked + .lookit-toggle-slider:before { transform:translateX(20px); }
	.lookit-toggle input:disabled + .lookit-toggle-slider { opacity:.6; cursor:not-allowed; }
	.lookit-cc-actions { display:flex; flex-wrap:wrap; gap:8px; }
	.lookit-cc-btn { cursor:pointer; display:inline-block; }
	.lookit-cc-save { background:<?php echo esc_attr( $accent ); ?>; color:#fff; }
	.lookit-cc-learn-more { cursor:pointer; }
	.lookit-notice-body a { color:<?php echo esc_attr( $accent ); ?>; text-decoration:underline; }
	.lookit-cc-pill-strip { display:none; }
	#lookit-cc-popup.lookit-cc-style-pill .lookit-cc-pill-strip { display:flex; }
	#lookit-cc-popup.lookit-cc-style-pill.lookit-cc-expanded .lookit-cc-pill-strip { display:none; }
	</style>
	<?php
}

add_action( 'wp_footer', 'lookit_cc_output' );

function lookit_cc_output() {
	$opts = lookit_cc_get_options();
	if ( empty( $opts['enabled'] ) ) {
		return;
	}

	$pos        = $opts['position'];
	$style      = $opts['display_style'];
	$accent     = $opts['accent_color'];
	$bg         = $opts['bg_color'];
	$text_color = $opts['text_color'];
	$duration   = (int) $opts['cookie_duration'];
	$body_html  = nl2br( $opts['body_text'] );
	$logo       = esc_url( $opts['logo_url'] );
	$ajax_url   = admin_url( 'admin-ajax.php' );
	$nonce      = wp_create_nonce( 'lookit_cc_record' );
	$policy_id  = $opts['iubenda_policy_id'];
	?>
	<style id="lookit-cc-styles">
	html, body { overflow: auto !important; height: auto !important; }

	#iubenda-cs-banner, .iubenda-cs-container { display: none !important; }

	/* v3.1.2: force-declare all classes so Remove Unused CSS keeps them */
	.lookit-tabs, .lookit-tab, .lookit-tab.active, .lookit-panel, .lookit-panel.active,
	.lookit-purposes, .lookit-purpose, .lookit-purpose-info, .lookit-purpose-name,
	.lookit-purpose-desc, .lookit-toggle, .lookit-toggle-slider, .lookit-notice-body,
	.lookit-cc-actions, .lookit-cc-btn, .lookit-cc-accept, .lookit-cc-reject,
	.lookit-cc-save, .lookit-cc-learn-more,
	.lookit-cc-pill-strip, .lookit-cc-pill-text, .lookit-cc-pill-accept, .lookit-cc-pill-expand { display: revert; }

	/* ── Popup wrapper ── */
	#lookit-cc-popup, #lookit-cc-popup * {
		box-sizing: border-box !important;
		font-family: inherit !important;
		-webkit-appearance: none !important;
		appearance: none !important;
	}
	#lookit-cc-popup {
		position: fixed !important;
		<?php
		switch ( $pos ) {
			case 'bottom-right':
						echo 'bottom:24px!important;right:24px!important;left:auto!important;top:auto!important;transform:none!important;';
				break;
			case 'bottom-center':
						echo 'bottom:24px!important;left:50%!important;right:auto!important;top:auto!important;transform:translateX(-50%)!important;';
				break;
			case 'top-center':
						echo 'top:24px!important;left:50%!important;right:auto!important;bottom:auto!important;transform:translateX(-50%)!important;';
				break;
			default:
						echo 'bottom:24px!important;left:24px!important;right:auto!important;top:auto!important;transform:none!important;';
		}
		?>
		z-index: 2147483646 !important;
		width: min(440px, calc(100vw - 32px)) !important;
		max-width: calc(100vw - 32px) !important;
		max-height: calc(100vh - 48px) !important;
		overflow: hidden !important;
		margin: 0 !important;
		float: none !important;
		display: block !important;
		background: <?php echo esc_attr( $bg ); ?> !important;
		background-color: <?php echo esc_attr( $bg ); ?> !important;
		background-image: none !important;
		color: <?php echo esc_attr( $text_color ); ?> !important;
		border-radius: 12px !important;
		box-shadow: 0 8px 40px rgba(0,0,0,.18), 0 2px 8px rgba(0,0,0,.10) !important;
		border: none !important;
		border-top: 4px solid <?php echo esc_attr( $accent ); ?> !important;
		font-size: 14px !important;
		line-height: 1.5 !important;
		text-align: left !important;
		opacity: 0 !important;
		visibility: hidden !important;
		pointer-events: none !important;
		transition: opacity .35s ease, visibility .35s ease !important;
	}
	#lookit-cc-popup.lookit-cc-visible {
		opacity: 1 !important;
		visibility: visible !important;
		pointer-events: all !important;
	}

	/* ── Tabs ── */
	.lookit-tabs {
		display: flex !important;
		border-bottom: 1px solid #e0e0e0 !important;
		margin: 0 !important;
		padding: 0 !important;
		background: <?php echo esc_attr( $bg ); ?> !important;
	}
	.lookit-tab {
		flex: 1 !important;
		padding: 12px 16px !important;
		font-size: 14px !important;
		font-weight: 600 !important;
		font-family: inherit !important;
		background: transparent !important;
		border: none !important;
		border-bottom: 3px solid transparent !important;
		cursor: pointer !important;
		color: #888 !important;
		transition: all .2s !important;
		margin-bottom: -1px !important;
		outline: none !important;
	}
	.lookit-tab.active {
		color: <?php echo esc_attr( $accent ); ?> !important;
		border-bottom-color: <?php echo esc_attr( $accent ); ?> !important;
	}
	.lookit-tab:hover:not(.active) { color: <?php echo esc_attr( $text_color ); ?> !important; }

	/* ── Tab panels ── */
	.lookit-panel {
		display: none !important;
		padding: 20px 24px 20px !important;
		overflow-y: auto !important;
		max-height: calc(100vh - 180px) !important;
	}
	.lookit-panel.active { display: block !important; }

	/* ── Logo ── */
	#lookit-cc-popup .lookit-cc-logo {
		display: block !important;
		max-height: 36px !important;
		width: auto !important;
		height: auto !important;
		margin: 16px 24px 0 !important;
		border: none !important;
		background: none !important;
		padding: 0 !important;
	}

	/* ── Notice panel ── */
	.lookit-notice-body {
		font-size: 14px !important;
		line-height: 1.65 !important;
		color: <?php echo esc_attr( $text_color ); ?> !important;
		margin-bottom: 18px !important;
	}
	.lookit-notice-body a { color: <?php echo esc_attr( $accent ); ?> !important; text-decoration: underline !important; }

	/* ── Action buttons ── */
	.lookit-cc-actions {
		display: flex !important;
		flex-wrap: wrap !important;
		gap: 8px !important;
		margin-top: 4px !important;
	}
	.lookit-cc-btn {
		flex: 1 1 100px !important;
		padding: 11px 16px !important;
		border-radius: 7px !important;
		border: 2px solid <?php echo esc_attr( $accent ); ?> !important;
		font-family: inherit !important;
		font-size: 14px !important;
		font-weight: 600 !important;
		cursor: pointer !important;
		text-align: center !important;
		line-height: 1.2 !important;
		display: inline-block !important;
		margin: 0 !important;
		outline: none !important;
		transition: background .2s, color .2s !important;
	}
	.lookit-cc-btn:active { transform: scale(.97) !important; }
	.lookit-cc-btn:disabled { opacity: .6 !important; cursor: wait !important; }
	.lookit-cc-accept { background: <?php echo esc_attr( $accent ); ?> !important; color: #fff !important; }
	.lookit-cc-accept:hover:not(:disabled) { filter: brightness(1.12) !important; }
	.lookit-cc-reject { background: transparent !important; color: <?php echo esc_attr( $accent ); ?> !important; }
	.lookit-cc-reject:hover:not(:disabled) { background: <?php echo esc_attr( $accent ); ?> !important; color: #fff !important; }
	.lookit-cc-learn-more {
		flex: 0 0 100% !important;
		background: transparent !important;
		border-color: transparent !important;
		color: <?php echo esc_attr( $text_color ); ?> !important;
		font-weight: 400 !important;
		font-size: 0.85em !important;
		text-decoration: underline !important;
		padding: 2px 0 0 !important;
		cursor: pointer !important;
	}
	.lookit-cc-learn-more:hover { color: <?php echo esc_attr( $accent ); ?> !important; }
	.lookit-cc-save {
		flex: 0 0 100% !important;
		background: <?php echo esc_attr( $accent ); ?> !important;
		color: #fff !important;
		border-color: <?php echo esc_attr( $accent ); ?> !important;
	}
	.lookit-cc-save:hover:not(:disabled) { filter: brightness(1.12) !important; }

	/* ── Preferences panel toggles ── */
	.lookit-purposes { margin: 0 0 18px !important; padding: 0 !important; }
	.lookit-purpose {
		display: flex !important;
		align-items: flex-start !important;
		justify-content: space-between !important;
		padding: 12px 0 !important;
		border-bottom: 1px solid #f0f0f0 !important;
		gap: 12px !important;
	}
	.lookit-purpose:last-child { border-bottom: none !important; }
	.lookit-purpose-info { flex: 1 !important; }
	.lookit-purpose-name {
		font-size: 14px !important;
		font-weight: 600 !important;
		color: <?php echo esc_attr( $text_color ); ?> !important;
		margin-bottom: 2px !important;
		display: block !important;
	}
	.lookit-purpose-name.required { color: #888 !important; font-weight: 600 !important; }
	.lookit-purpose-desc {
		font-size: 12px !important;
		color: #888 !important;
		line-height: 1.4 !important;
		display: block !important;
	}

	/* Toggle switch */
	.lookit-toggle {
		position: relative !important;
		width: 44px !important;
		height: 24px !important;
		flex-shrink: 0 !important;
		margin-top: 2px !important;
	}
	.lookit-toggle input {
		opacity: 0 !important;
		width: 0 !important;
		height: 0 !important;
		position: absolute !important;
	}
	.lookit-toggle-slider {
		position: absolute !important;
		cursor: pointer !important;
		inset: 0 !important;
		background: #ccc !important;
		border-radius: 24px !important;
		transition: .3s !important;
	}
	.lookit-toggle-slider:before {
		content: "" !important;
		position: absolute !important;
		width: 18px !important;
		height: 18px !important;
		left: 3px !important;
		bottom: 3px !important;
		background: #fff !important;
		border-radius: 50% !important;
		transition: .3s !important;
	}
	.lookit-toggle input:checked + .lookit-toggle-slider { background: <?php echo esc_attr( $accent ); ?> !important; }
	.lookit-toggle input:checked + .lookit-toggle-slider:before { transform: translateX(20px) !important; }
	.lookit-toggle input:disabled + .lookit-toggle-slider { opacity: .6 !important; cursor: not-allowed !important; }

	/* ── Display style variants (v3.2.0) ───────────────────────── */

	/* Default: hide the pill strip unless we're in pill style */
	.lookit-cc-pill-strip { display: none !important; }

	/* ── B · Small card ── */
	#lookit-cc-popup.lookit-cc-style-card {
		width: min(340px, calc(100vw - 32px)) !important;
	}
	.lookit-cc-style-card .lookit-tabs { display: none !important; }
	.lookit-cc-style-card .lookit-panel { padding: 18px 20px !important; }
	.lookit-cc-style-card .lookit-notice-body { font-size: 13px !important; line-height: 1.55 !important; margin-bottom: 14px !important; }
	.lookit-cc-style-card .lookit-cc-logo { max-height: 28px !important; margin: 14px 20px 0 !important; }
	/* Stack buttons: Accept full-width on top, Reject + Customize below */
	.lookit-cc-style-card .lookit-cc-actions { flex-direction: column !important; gap: 8px !important; }
	.lookit-cc-style-card .lookit-cc-accept { flex: 0 0 100% !important; order: 1 !important; width: 100% !important; }
	.lookit-cc-style-card .lookit-cc-reject { flex: 0 0 100% !important; order: 2 !important; width: 100% !important; }
	.lookit-cc-style-card .lookit-cc-learn-more { order: 3 !important; text-align: center !important; }

	/* ── D · Corner pill ── */
	/* Collapsed: small auto-width strip. Drop the card chrome. */
	#lookit-cc-popup.lookit-cc-style-pill {
		width: auto !important;
		max-width: min(320px, calc(100vw - 32px)) !important;
		border-top: none !important;
		border-radius: 999px !important;
		box-shadow: 0 4px 20px rgba(0,0,0,.16) !important;
	}
	.lookit-cc-style-pill .lookit-tabs,
	.lookit-cc-style-pill .lookit-panel,
	.lookit-cc-style-pill .lookit-cc-logo { display: none !important; }
	.lookit-cc-style-pill .lookit-cc-pill-strip {
		display: flex !important;
		align-items: center !important;
		gap: 10px !important;
		padding: 8px 10px 8px 16px !important;
		white-space: nowrap !important;
	}
	.lookit-cc-style-pill .lookit-cc-pill-text {
		font-size: 13px !important;
		color: <?php echo esc_attr( $text_color ); ?> !important;
	}
	.lookit-cc-style-pill .lookit-cc-pill-accept {
		flex: 0 0 auto !important;
		padding: 7px 16px !important;
		border-radius: 999px !important;
		font-size: 13px !important;
		background: <?php echo esc_attr( $accent ); ?> !important;
		color: #fff !important;
		border: none !important;
	}
	.lookit-cc-style-pill .lookit-cc-pill-expand {
		flex: 0 0 auto !important;
		background: transparent !important;
		border: none !important;
		cursor: pointer !important;
		font-family: inherit !important;
		font-size: 12px !important;
		text-decoration: underline !important;
		color: #888 !important;
		padding: 4px 6px !important;
		outline: none !important;
	}
	.lookit-cc-style-pill .lookit-cc-pill-expand:hover { color: <?php echo esc_attr( $accent ); ?> !important; }

	/* Expanded pill = behaves like the small card */
	#lookit-cc-popup.lookit-cc-style-pill.lookit-cc-expanded {
		width: min(340px, calc(100vw - 32px)) !important;
		border-radius: 12px !important;
		border-top: 4px solid <?php echo esc_attr( $accent ); ?> !important;
	}
	.lookit-cc-style-pill.lookit-cc-expanded .lookit-cc-pill-strip { display: none !important; }
	.lookit-cc-style-pill.lookit-cc-expanded .lookit-panel.active { display: block !important; padding: 18px 20px !important; }
	.lookit-cc-style-pill.lookit-cc-expanded .lookit-notice-body { font-size: 13px !important; line-height: 1.55 !important; margin-bottom: 14px !important; }
	.lookit-cc-style-pill.lookit-cc-expanded .lookit-cc-actions { flex-direction: column !important; gap: 8px !important; }
	.lookit-cc-style-pill.lookit-cc-expanded .lookit-cc-accept { flex: 0 0 100% !important; order: 1 !important; width: 100% !important; }
	.lookit-cc-style-pill.lookit-cc-expanded .lookit-cc-reject { flex: 0 0 100% !important; order: 2 !important; width: 100% !important; }
	.lookit-cc-style-pill.lookit-cc-expanded .lookit-cc-learn-more { order: 3 !important; text-align: center !important; }

	@media (max-width: 600px) {
		#lookit-cc-popup { bottom: 0 !important; left: 0 !important; right: 0 !important; top: auto !important; transform: none !important; width: 100% !important; max-width: 100% !important; border-radius: 12px 12px 0 0 !important; }
		.lookit-panel { max-height: 60vh !important; }
	}
	</style>

	<div id="lookit-cc-popup" class="lookit-cc-style-<?php echo esc_attr( $style ); ?>" role="dialog" aria-modal="true" aria-label="Cookie Consent" aria-live="polite" aria-hidden="true">

		<!-- Pill collapsed strip — only visible in pill style, hidden once expanded -->
		<div class="lookit-cc-pill-strip">
			<span class="lookit-cc-pill-text"><?php esc_html_e( 'This site uses cookies.', 'lookit-cookie-consent' ); ?></span>
			<button class="lookit-cc-btn lookit-cc-accept lookit-cc-pill-accept"><?php echo esc_html( $opts['accept_label'] ); ?></button>
			<button class="lookit-cc-pill-expand" aria-label="<?php esc_attr_e( 'Manage cookie preferences', 'lookit-cookie-consent' ); ?>"><?php echo esc_html( $opts['learn_more_label'] ); ?></button>
		</div>

		<?php if ( $logo ) : ?>
		<img class="lookit-cc-logo" src="<?php echo esc_url( $logo ); ?>" alt="Logo" width="512" height="512">
		<?php endif; ?>

		<!-- Tabs -->
		<div class="lookit-tabs" role="tablist">
			<button class="lookit-tab active" role="tab" aria-selected="true" data-tab="notice"><?php esc_html_e( 'Notice', 'lookit-cookie-consent' ); ?></button>
			<button class="lookit-tab" role="tab" aria-selected="false" data-tab="preferences"><?php esc_html_e( 'Preferences', 'lookit-cookie-consent' ); ?></button>
		</div>

		<!-- Notice Panel -->
		<div class="lookit-panel active" id="lookit-panel-notice" role="tabpanel">
			<div class="lookit-notice-body"><?php echo wp_kses_post( $body_html ); ?></div>
			<div class="lookit-cc-actions">
				<button class="lookit-cc-btn lookit-cc-reject"><?php echo esc_html( $opts['reject_label'] ); ?></button>
				<button class="lookit-cc-btn lookit-cc-accept"><?php echo esc_html( $opts['accept_label'] ); ?></button>
				<button class="lookit-cc-btn lookit-cc-learn-more"><?php echo esc_html( $opts['learn_more_label'] ); ?></button>
			</div>
		</div>

		<!-- Preferences Panel -->
		<div class="lookit-panel" id="lookit-panel-preferences" role="tabpanel">
			<p style="font-size:13px;color:#666;margin:0 0 12px;line-height:1.5;">
				<?php esc_html_e( 'In this panel you can express preferences for the processing of your personal information. You may review and change your choices at any time.', 'lookit-cookie-consent' ); ?>
			</p>

			<div class="lookit-purposes">

				<!-- Necessary — always on -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name required"><?php esc_html_e( 'Necessary', 'lookit-cookie-consent' ); ?></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Required for the site to function. Cannot be disabled.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" checked disabled>
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

				<!-- Functionality -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name"><?php esc_html_e( 'Functionality', 'lookit-cookie-consent' ); ?></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Enables enhanced functionality and personalisation such as live chats and videos.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" id="pref-functionality" name="functionality">
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

				<!-- Experience -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name"><?php esc_html_e( 'Experience', 'lookit-cookie-consent' ); ?></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Allows us to improve your experience based on how you use the site.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" id="pref-experience" name="experience">
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

				<!-- Measurement -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name"><?php esc_html_e( 'Measurement', 'lookit-cookie-consent' ); ?></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Helps us measure traffic and analyse your behaviour to improve our service.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" id="pref-measurement" name="measurement">
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

				<!-- Marketing -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name"><?php esc_html_e( 'Marketing', 'lookit-cookie-consent' ); ?></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Used to deliver personalised ads and marketing content based on your interests.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" id="pref-marketing" name="marketing">
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

				<!-- Sale of personal info (CCPA/US) -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name"><strong><?php esc_html_e( 'Sale', 'lookit-cookie-consent' ); ?></strong> <?php esc_html_e( 'of my personal information', 'lookit-cookie-consent' ); ?></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Under US state privacy laws you may opt out of the sale of your personal information.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" id="pref-sale" name="sale_of_personal_information">
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

				<!-- Sharing -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name"><strong><?php esc_html_e( 'Sharing', 'lookit-cookie-consent' ); ?></strong> <?php esc_html_e( 'of my personal information', 'lookit-cookie-consent' ); ?></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Controls whether your personal information is shared with third parties.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" id="pref-sharing" name="sharing_of_personal_information">
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

				<!-- Targeted Advertising -->
				<div class="lookit-purpose">
					<div class="lookit-purpose-info">
						<span class="lookit-purpose-name"><?php esc_html_e( 'Processing for', 'lookit-cookie-consent' ); ?> <strong><?php esc_html_e( 'targeted advertising', 'lookit-cookie-consent' ); ?></strong></span>
						<span class="lookit-purpose-desc"><?php esc_html_e( 'Allows processing of your data to deliver targeted advertising across platforms.', 'lookit-cookie-consent' ); ?></span>
					</div>
					<label class="lookit-toggle">
						<input type="checkbox" id="pref-advertising" name="targeted_advertising">
						<span class="lookit-toggle-slider"></span>
					</label>
				</div>

			</div><!-- .lookit-purposes -->

			<div class="lookit-cc-actions">
				<button class="lookit-cc-btn lookit-cc-reject"><?php echo esc_html( $opts['reject_label'] ); ?></button>
				<button class="lookit-cc-btn lookit-cc-save"><?php esc_html_e( 'Save and continue', 'lookit-cookie-consent' ); ?></button>
			</div>
		</div>

	</div><!-- #lookit-cc-popup -->

	<script id="lookit-cc-script">
	(function () {
		var COOKIE_NAME = 'lookit_cc_consent';
		var PREFS_KEY   = 'lookit_cc_prefs';
		var DURATION    = <?php echo (int) $duration; ?>;
		var AJAX_URL    = '<?php echo esc_js( $ajax_url ); ?>';
		var NONCE       = '<?php echo esc_js( $nonce ); ?>';
		var POLICY_ID   = '<?php echo esc_js( $policy_id ); ?>';

		function getCookie(name) {
			var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
			return m ? decodeURIComponent(m[1]) : null;
		}
		function setCookie(name, value, days) {
			var d = new Date();
			d.setTime(d.getTime() + days * 864e5);
			document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
		}
		function getSubjectId() {
			try {
				var id = localStorage.getItem('lookit_subject_id');
				if (!id) { id = 'anon-' + Math.random().toString(36).substr(2,9) + '-' + Date.now(); localStorage.setItem('lookit_subject_id', id); }
				return id;
			} catch(e) { return 'anon-' + Math.random().toString(36).substr(2,9); }
		}

		var popup = document.getElementById('lookit-cc-popup');
		if (popup && popup.parentNode !== document.body) document.body.appendChild(popup);

		function show() { popup.classList.add('lookit-cc-visible'); popup.setAttribute('aria-hidden','false'); }
		function hide() { popup.classList.remove('lookit-cc-visible'); popup.setAttribute('aria-hidden','true'); }

		/* ── Tab switching ── */
		popup.querySelectorAll('.lookit-tab').forEach(function(tab) {
			tab.addEventListener('click', function() {
				popup.querySelectorAll('.lookit-tab').forEach(function(t) { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
				popup.querySelectorAll('.lookit-panel').forEach(function(p) { p.classList.remove('active'); });
				tab.classList.add('active');
				tab.setAttribute('aria-selected','true');
				document.getElementById('lookit-panel-' + tab.dataset.tab).classList.add('active');
			});
		});

		/* ── Read/write toggle states ── */
		var purposeCheckboxes = popup.querySelectorAll('.lookit-purposes input[type="checkbox"]:not([disabled])');

		function getPreferences() {
			var prefs = {};
			purposeCheckboxes.forEach(function(cb) { prefs[cb.name] = cb.checked; });
			return prefs;
		}

		function setToggles(accepted) {
			purposeCheckboxes.forEach(function(cb) { cb.checked = accepted; });
		}

		function restoreSavedPrefs() {
			try {
				var saved = JSON.parse(localStorage.getItem(PREFS_KEY));
				if (saved) {
					purposeCheckboxes.forEach(function(cb) {
						if (cb.name in saved) cb.checked = saved[cb.name];
					});
				}
			} catch(e) {}
		}

		function setButtonsLoading(loading) {
			popup.querySelectorAll('.lookit-cc-btn').forEach(function(btn) { btn.disabled = loading; });
		}

		/*
		 * ── Record consent to iubenda API via WP AJAX ──
		 * preferences object contains granular purpose values.
		 * accepted = true/false for the overall consent flag.
		 */
		function recordConsent(accepted, preferences) {
			setButtonsLoading(true);

			/* Save prefs locally */
			try { localStorage.setItem(PREFS_KEY, JSON.stringify(preferences)); } catch(e) {}

			var formData = new FormData();
			formData.append('action',      'lookit_cc_record');
			formData.append('nonce',       NONCE);
			formData.append('accepted',    accepted ? 'true' : 'false');
			formData.append('subject_id',  getSubjectId());
			formData.append('preferences', JSON.stringify(preferences));

			fetch(AJAX_URL, { method: 'POST', body: formData })
				.then(function(r) { return r.json(); })
				.then(function() {
					setCookie(COOKIE_NAME, accepted ? 'accepted' : 'rejected', DURATION);
					hide();
				})
				.catch(function() {
					setCookie(COOKIE_NAME, accepted ? 'accepted' : 'rejected', DURATION);
					hide();
				})
				.finally(function() { setButtonsLoading(false); });
		}

		/* Accept all — turn all toggles on */
		popup.querySelectorAll('.lookit-cc-accept').forEach(function(btn) {
			btn.addEventListener('click', function() {
				setToggles(true);
				recordConsent(true, getPreferences());
			});
		});

		/* Reject all — turn all toggles off */
		popup.querySelectorAll('.lookit-cc-reject').forEach(function(btn) {
			btn.addEventListener('click', function() {
				setToggles(false);
				recordConsent(false, getPreferences());
			});
		});

		/* Save and continue — use current toggle states */
		var saveBtn = popup.querySelector('.lookit-cc-save');
		if (saveBtn) {
			saveBtn.addEventListener('click', function() {
				var prefs = getPreferences();
				/* Overall consent = true if at least one purpose is on */
				var anyOn = Object.values(prefs).some(function(v) { return v; });
				recordConsent(anyOn, prefs);
			});
		}

		/* Learn more link — switch to Preferences tab */
		var learnMore = popup.querySelector('.lookit-cc-learn-more');
		if (learnMore) {
			learnMore.addEventListener('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				/* Deactivate all tabs and panels */
				popup.querySelectorAll('.lookit-tab').forEach(function(t) {
					t.classList.remove('active');
					t.setAttribute('aria-selected', 'false');
				});
				popup.querySelectorAll('.lookit-panel').forEach(function(p) {
					p.classList.remove('active');
				});
				/* Activate Preferences tab */
				var prefTab = popup.querySelector('[data-tab="preferences"]');
				var prefPanel = document.getElementById('lookit-panel-preferences');
				if (prefTab) { prefTab.classList.add('active'); prefTab.setAttribute('aria-selected', 'true'); }
				if (prefPanel) { prefPanel.classList.add('active'); }
			});
		}

		/* ── Pill expand: reveal the full notice card ── */
		var pillExpand = popup.querySelector('.lookit-cc-pill-expand');
		if (pillExpand) {
			pillExpand.addEventListener('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				popup.classList.add('lookit-cc-expanded');
				/* Make sure the Notice panel is the one showing */
				var noticeTab = popup.querySelector('[data-tab="notice"]');
				var noticePanel = document.getElementById('lookit-panel-notice');
				popup.querySelectorAll('.lookit-tab').forEach(function(t) { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
				popup.querySelectorAll('.lookit-panel').forEach(function(p) { p.classList.remove('active'); });
				if (noticeTab) { noticeTab.classList.add('active'); noticeTab.setAttribute('aria-selected','true'); }
				if (noticePanel) { noticePanel.classList.add('active'); }
			});
		}

		/* Escape = reject all */
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && popup.classList.contains('lookit-cc-visible')) {
				setToggles(false);
				recordConsent(false, getPreferences());
			}
		});

		window.lookitCCShow = show;

		function init() {
			if (!getCookie(COOKIE_NAME)) {
				restoreSavedPrefs();
				setTimeout(show, 800);
			}
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	})();
	</script>
	<?php
}
