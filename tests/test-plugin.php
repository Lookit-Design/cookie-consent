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
}
