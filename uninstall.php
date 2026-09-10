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
 * consent/code/access/refresh artifact. The fixed ChatGPT metadata/JWKS caches,
 * short-lived client-assertion replay claims, and their cleanup events are also
 * removed. Persistent Workspace data is a post-v0.1 concern and must not be added
 * to this cleanup without its separate explicit uninstall-retention contract.
 *
 * @return void
 */
function wp_native_builder_bridge_uninstall_site_options() {
	delete_option( 'wp_native_builder_bridge_settings' );
	delete_option( 'wp_native_builder_bridge_recent_actions' );
	delete_option( 'wp_native_builder_bridge_oauth_instance' );
	delete_transient( 'wpnb_oauth_chatgpt_cimd_ok' );
	delete_transient( 'wpnb_oauth_chatgpt_jwks' );
	delete_transient( 'wpnb_oauth_chatgpt_jwks_refresh' );
	wp_unschedule_hook( 'wpnb_oauth_cleanup_client_assertion' );

	// Client-assertion replay claims contain only a hashed JWT ID and expiry,
	// but uninstall should still remove every Bridge-owned claim immediately.
	global $wpdb;
	$assertion_prefix = $wpdb->esc_like( 'wpnb_oauth_assertion_' ) . '%';
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-only deletion of this plugin's bounded option prefix.
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $assertion_prefix ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table identifier is the current WordPress options table; the value is prepared below.
	);
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
