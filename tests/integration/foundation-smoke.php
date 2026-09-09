<?php
/**
 * Run inside a disposable WordPress environment, for example:
 * wp --user=1 eval-file tests/integration/foundation-smoke.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress is not loaded.\n" );
	exit( 1 );
}

$failures = array();
$assert   = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ), 'WordPress 6.9+ is required.' );
$assert( class_exists( 'WP_Ability' ), 'WP_Ability is unavailable.' );
$assert( function_exists( 'wp_get_ability' ), 'wp_get_ability() is unavailable.' );
$assert( class_exists( 'WP\\MCP\\Core\\McpAdapter' ), 'Official MCP Adapter is unavailable.' );
$assert( class_exists( 'WP_Native_Builder_Bridge\\Plugin' ), 'WP Native Builder Bridge is not loaded.' );

if ( function_exists( 'wp_get_ability' ) ) {
	$ability = wp_get_ability( 'wp-native-builder/bridge-info' );
	$assert( null !== $ability, 'wp-native-builder/bridge-info is not registered.' );
}

$settings_class = 'WP_Native_Builder_Bridge\\Support\\Settings';
$permission_class = 'WP_Native_Builder_Bridge\\Support\\Permissions';

if ( class_exists( $settings_class ) && class_exists( $permission_class ) ) {
	$original = get_option( $settings_class::OPTION_NAME, null );
	$settings = new $settings_class();
	$permissions = new $permission_class( $settings );

	try {
		update_option( $settings_class::OPTION_NAME, $settings->defaults(), false );
		$assert( true === $permissions->allowed( $settings_class::GROUP_SITE_READ, 'read' ), 'Enabled Site Read did not authorize a user with read capability.' );
		$disabled = $settings->defaults();
		$disabled[ $settings_class::GROUP_SITE_READ ] = 0;
		update_option( $settings_class::OPTION_NAME, $disabled, false );
		$assert( false === $permissions->allowed( $settings_class::GROUP_SITE_READ, 'read' ), 'Disabled Site Read still authorized the ability.' );
	} finally {
		if ( null === $original ) {
			delete_option( $settings_class::OPTION_NAME );
		} else {
			update_option( $settings_class::OPTION_NAME, $original, false );
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	exit( 1 );
}

echo 'PASS: foundation integration smoke check.' . PHP_EOL;
