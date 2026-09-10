<?php
/**
 * Uninstall cleanup for WP Native Builder Bridge v0.1-owned settings.
 *
 * @package WP_Native_Builder_Bridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes only the v0.1 options owned by this plugin.
 *
 * Removing the OAuth installation identity invalidates every outstanding
 * consent/code/access/refresh artifact without needing to enumerate transient
 * rows. Persistent Workspace data is a post-v0.1 concern and must not be added
 * to this cleanup without its separate explicit uninstall-retention contract.
 *
 * @return void
 */
function wp_native_builder_bridge_uninstall_site_options() {
	delete_option( 'wp_native_builder_bridge_settings' );
	delete_option( 'wp_native_builder_bridge_recent_actions' );
	delete_option( 'wp_native_builder_bridge_oauth_instance' );
	delete_transient( 'wpnb_oauth_chatgpt_cimd_ok' );
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wp_native_builder_bridge_uninstall_site_options();
		restore_current_blog();
	}
} else {
	wp_native_builder_bridge_uninstall_site_options();
}
