<?php
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Registrar;
use WP_Native_Builder_Bridge\Admin\Settings_Page;
use WP_Native_Builder_Bridge\Support\Environment;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

$failures = 0;
$tests    = 0;

function wpnb_assert( $condition, $message ) {
	global $failures, $tests;
	++$tests;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

$environment = new Environment();
wpnb_assert( false === $environment->abilities_api_available(), 'Abilities API is absent when WP_Ability is unavailable.' );
wpnb_assert( false === $environment->mcp_adapter_available(), 'MCP Adapter is absent when its class is unavailable.' );

eval( 'class WP_Ability {}' );
wpnb_assert( true === $environment->abilities_api_available(), 'Abilities API is detected on the supported WordPress baseline.' );

eval( 'namespace WP\\MCP\\Core; class McpAdapter {}' );
define( 'WP_MCP_VERSION', '0.6.1' );
wpnb_assert( true === $environment->mcp_adapter_available(), 'MCP Adapter class detection works.' );
wpnb_assert( '0.6.1' === $environment->mcp_adapter_version(), 'MCP Adapter version detection uses WP_MCP_VERSION.' );

wpnb_test_reset_state();
$settings = new Settings();
$defaults = $settings->defaults();
wpnb_assert( 1 === $defaults[ Settings::GROUP_SITE_READ ], 'Site Read defaults to enabled.' );
wpnb_assert( 0 === $defaults[ Settings::GROUP_BUILDER_WRITE ], 'Builder Write defaults to disabled.' );
wpnb_assert( 0 === $defaults[ Settings::GROUP_LIVE_CONTENT ], 'Live Content defaults to disabled.' );

$sanitized = $settings->sanitize(
	array(
		Settings::GROUP_SITE_READ     => '1',
		Settings::GROUP_BUILDER_WRITE => 'yes',
		'unknown_group'               => '1',
	)
);
wpnb_assert( ! isset( $sanitized['unknown_group'] ), 'Unknown access groups are discarded.' );
wpnb_assert( 1 === $sanitized[ Settings::GROUP_BUILDER_WRITE ], 'Known enabled access group is persisted as a boolean integer.' );
wpnb_assert( 0 === $sanitized[ Settings::GROUP_LIVE_CONTENT ], 'Missing checkbox values persist as disabled.' );

$settings->register();
$registered_setting = $GLOBALS['wpnb_test']['registered_settings'][ Settings::OPTION_NAME ];
wpnb_assert( Settings::OPTION_GROUP === $registered_setting['group'], 'Settings register in the bridge option group.' );
wpnb_assert( false === $registered_setting['args']['show_in_rest'], 'Bridge access settings are not exposed for REST writes.' );
wpnb_assert( is_callable( $registered_setting['args']['sanitize_callback'] ), 'Settings have a sanitize callback.' );

$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = array( Settings::GROUP_SITE_READ => 1 );
$permissions = new Permissions( $settings );
wpnb_assert( true === $permissions->allowed( Settings::GROUP_SITE_READ, 'read' ), 'Enabled group plus WordPress capability authorizes an ability.' );
$GLOBALS['wpnb_test']['capabilities']['read'] = false;
wpnb_assert( false === $permissions->allowed( Settings::GROUP_SITE_READ, 'read' ), 'Missing WordPress capability denies an ability.' );
$GLOBALS['wpnb_test']['capabilities']['read'] = true;
$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_SITE_READ ] = 0;
wpnb_assert( false === $permissions->allowed( Settings::GROUP_SITE_READ, 'read' ), 'Disabled bridge group denies an ability.' );
wpnb_assert( false === $permissions->allowed( 'unknown_group', 'read' ), 'Unknown bridge group is denied.' );

$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ] = $settings->defaults();
$registrar = new Registrar( $environment, $settings, $permissions );
$registrar->register_category();
$registrar->register_abilities();
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_categories'][ Registrar::CATEGORY ] ), 'Bridge ability category is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/bridge-info'] ), 'Bridge discovery ability is registered.' );
$ability = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/bridge-info'];
wpnb_assert( true === $ability['meta']['mcp']['public'], 'Bridge discovery ability explicitly opts into MCP exposure.' );
wpnb_assert( true === $ability['meta']['annotations']['readonly'], 'Bridge discovery ability is marked read-only.' );
wpnb_assert( true === call_user_func( $ability['permission_callback'] ), 'Bridge discovery ability permission callback honors Site Read.' );
$bridge_info = call_user_func( $ability['execute_callback'] );
wpnb_assert( '0.1.0-dev' === $bridge_info['plugin_version'], 'Bridge discovery ability returns plugin version.' );
wpnb_assert( true === $bridge_info['mcp_adapter']['available'], 'Bridge discovery ability reports adapter availability.' );

wpnb_test_reset_state();
$log = new Mutation_Log();
for ( $i = 0; $i < 55; ++$i ) {
	$log->record( 'wp-native-builder/test-' . $i, 'post', $i, true, '' );
}
$entries = $log->recent( 100 );
wpnb_assert( 50 === count( $entries ), 'Mutation log is bounded to 50 records.' );
wpnb_assert(
	array( 'timestamp', 'user_id', 'ability', 'target_type', 'target_id', 'success', 'error_code' ) === array_keys( $entries[0] ),
	'Mutation log stores only bounded metadata fields.'
);

wpnb_test_reset_state();
$page = new Settings_Page( $environment, $settings );
$page->register_menu();
wpnb_assert( 'manage_options' === $GLOBALS['wpnb_test']['options_pages'][ Settings_Page::PAGE_SLUG ]['capability'], 'Settings page requires manage_options.' );
ob_start();
$page->render();
$html = ob_get_clean();
wpnb_assert( in_array( Settings::OPTION_GROUP, $GLOBALS['wpnb_test']['settings_fields'], true ), 'Settings page uses WordPress Settings API nonce fields.' );
wpnb_assert( false !== strpos( $html, 'options.php' ), 'Settings page posts through the WordPress Settings API.' );

$GLOBALS['wpnb_test']['capabilities']['manage_options'] = false;
try {
	$page->render();
	wpnb_assert( false, 'Settings page must deny users without manage_options.' );
} catch ( RuntimeException $exception ) {
	wpnb_assert( true, 'Settings page denies users without manage_options.' );
}

if ( 0 !== $failures ) {
	fwrite( STDERR, "\n{$failures} failure(s), {$tests} assertion(s).\n" );
	exit( 1 );
}

echo "PASS: {$tests} assertions.\n";
