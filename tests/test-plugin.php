<?php
/**
 * @package Lookit_Cookie_Consent
 */

class Test_Lookit_Cookie_Consent_Settings extends WP_UnitTestCase {

	public function test_plugin_defines_version() {
		$this->assertTrue( defined( 'LOOKIT_CC_VERSION' ) );
	}

	public function test_sanitize_falls_back_to_panel_for_unknown_style() {
		$result = lookit_cc_sanitize( array( 'display_style' => 'nope' ) );
		$this->assertSame( 'panel', $result['display_style'] );
	}

	public function test_sanitize_accepts_card_style() {
		$result = lookit_cc_sanitize( array( 'display_style' => 'card' ) );
		$this->assertSame( 'card', $result['display_style'] );
	}

	public function test_sanitize_stores_public_key() {
		$result = lookit_cc_sanitize( array( 'iubenda_public_key' => ' abc123 ' ) );
		$this->assertSame( 'abc123', $result['iubenda_public_key'] );
	}

	public function test_settings_page_hidden_from_subscriber() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		ob_start();
		lookit_cc_settings_page();
		$out = ob_get_clean();
		$this->assertSame( '', $out );
	}

	public function test_consent_subject_identifiers_are_bounded() {
		$this->assertTrue( lookit_cc_is_valid_subject_id( 'anon-abc123-1789076884000' ) );
		$this->assertTrue( lookit_cc_is_valid_subject_id( 'anon-abc123' ) );
		$this->assertFalse( lookit_cc_is_valid_subject_id( 'customer@example.com' ) );
		$this->assertFalse( lookit_cc_is_valid_subject_id( 'anon-' . str_repeat( 'a', 100 ) ) );
	}

	public function test_consent_requests_are_rate_limited_by_address() {
		$ip  = '192.0.2.10';
		$key = 'lookit_cc_rate_' . md5( $ip );
		delete_transient( $key );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( lookit_cc_consume_rate_limit( $ip ) );
		}
		$this->assertFalse( lookit_cc_consume_rate_limit( $ip ) );
		delete_transient( $key );
	}
}
