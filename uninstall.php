<?php
/**
 * Uninstall routine for Lookit Cookie Consent.
 *
 * @package Lookit_Cookie_Consent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lookit_cc_uninstall_options = array(
	'lookit_cc_options',
);

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $lookit_cc_uninstall_site_id ) {
		switch_to_blog( $lookit_cc_uninstall_site_id );
		foreach ( $lookit_cc_uninstall_options as $lookit_cc_uninstall_option ) {
			delete_option( $lookit_cc_uninstall_option );
		}
		restore_current_blog();
	}
} else {
	foreach ( $lookit_cc_uninstall_options as $lookit_cc_uninstall_option ) {
		delete_option( $lookit_cc_uninstall_option );
	}
}
