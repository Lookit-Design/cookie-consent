<?php
/**
 * @package Lookit_Cookie_Consent
 */

class Test_Lookit_Cookie_Consent_Credential_Hygiene extends WP_UnitTestCase {

	const SECRET = 'secret-iubenda-key';

	public function tear_down() {
		delete_option( 'lookit_cc_options' );
		parent::tear_down();
	}

	public function test_sanitize_blank_keeps_existing_key() {
		update_option( 'lookit_cc_options', array( 'iubenda_public_key' => self::SECRET ) );

		$result = lookit_cc_sanitize( array( 'iubenda_public_key' => '' ) );

		$this->assertSame( self::SECRET, $result['iubenda_public_key'] );
	}

	public function test_sanitize_replaces_key_when_new_value_submitted() {
		update_option( 'lookit_cc_options', array( 'iubenda_public_key' => 'old-key' ) );

		$result = lookit_cc_sanitize( array( 'iubenda_public_key' => '  new-key  ' ) );

		$this->assertSame( 'new-key', $result['iubenda_public_key'] );
	}

	public function test_settings_page_never_outputs_the_saved_key() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'lookit_cc_options', array( 'iubenda_public_key' => self::SECRET ) );

		ob_start();
		lookit_cc_settings_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'name="lookit_cc_options[iubenda_public_key]"', $html );
		$this->assertStringContainsString( 'value=""', $html );
	}

	public function test_maybe_disable_autoload_removes_options_from_autoload() {
		delete_option( 'lookit_cc_options' );
		add_option( 'lookit_cc_options', array( 'iubenda_public_key' => self::SECRET ), '', 'yes' );

		$this->assertArrayHasKey( 'lookit_cc_options', wp_load_alloptions() );

		lookit_cc_maybe_disable_autoload();

		$this->assertArrayNotHasKey( 'lookit_cc_options', wp_load_alloptions() );
		$opts = lookit_cc_get_options();
		$this->assertSame( self::SECRET, $opts['iubenda_public_key'] );
	}
}
