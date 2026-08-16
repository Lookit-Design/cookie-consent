<?php
/**
 * @package Lookit_Cookie_Consent
 */

class Test_Lookit_Cookie_Consent_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_deletes_plugin_options() {
		update_option( 'lookit_cc_options', 'lookit-test-value' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lookit-cookie-consent/lookit-cookie-consent.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'lookit_cc_options' ) );
	}
}
