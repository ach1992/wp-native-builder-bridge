<?php
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Ability_Resolver;
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

function wpnb_test_ability( $name, array $properties = array(), array $meta = array(), $category = 'test' ) {
	return new WP_Native_Builder_Test_Ability(
		$name,
		$name,
		'Test ability ' . $name,
		$category,
		array(
			'type'       => 'object',
			'properties' => $properties,
		),
		$meta
	);
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
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/site-context'] ), 'Site context ability is registered.' );
$ability = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/bridge-info'];
wpnb_assert( true === $ability['meta']['mcp']['public'], 'Bridge discovery ability explicitly opts into MCP exposure.' );
wpnb_assert( true === $ability['meta']['annotations']['readonly'], 'Bridge discovery ability is marked read-only.' );
wpnb_assert( true === call_user_func( $ability['permission_callback'] ), 'Bridge discovery ability permission callback honors Site Read.' );
$bridge_info = call_user_func( $ability['execute_callback'] );
wpnb_assert( '0.1.0-dev' === $bridge_info['plugin_version'], 'Bridge discovery ability returns plugin version.' );
wpnb_assert( true === $bridge_info['mcp_adapter']['available'], 'Bridge discovery ability reports adapter availability.' );

wpnb_test_reset_state();
$GLOBALS['wpnb_test']['abilities'] = array(
	'vendor/private' => wpnb_test_ability(
		'vendor/private',
		array( 'post_type' => array( 'type' => 'string' ) ),
		array( 'public' => false )
	),
	'vendor/mcp-optout' => wpnb_test_ability(
		'vendor/mcp-optout',
		array( 'post_type' => array( 'type' => 'string' ) ),
		array( 'public' => true, 'mcp' => array( 'public' => false ) )
	),
	'vendor/compatible' => wpnb_test_ability(
		'vendor/compatible',
		array(
			'post_type' => array( 'type' => 'string' ),
			'fields'    => array( 'type' => 'array' ),
		),
		array( 'public' => true ),
		'content'
	),
	'wp-native-builder/internal' => wpnb_test_ability( 'wp-native-builder/internal', array(), array( 'public' => true ) ),
	'mcp-adapter/meta' => wpnb_test_ability( 'mcp-adapter/meta', array(), array( 'public' => true ) ),
);
$resolver = new Ability_Resolver();
$resolved = $resolver->find(
	array( 'vendor/private', 'vendor/mcp-optout', 'vendor/compatible' ),
	array( 'post_type', 'fields' )
);
wpnb_assert( $resolved instanceof WP_Native_Builder_Test_Ability, 'Resolver finds a compatible external Ability.' );
wpnb_assert( 'vendor/compatible' === $resolved->get_name(), 'Resolver skips private and MCP-opted-out candidates.' );
wpnb_assert( null === $resolver->find( array( 'vendor/compatible' ), array( 'missing_input' ) ), 'Resolver rejects an incompatible input contract.' );
wpnb_assert( false === $resolver->is_mcp_exposed( $GLOBALS['wpnb_test']['abilities']['vendor/mcp-optout'] ), 'Explicit MCP opt-out overrides general public exposure.' );

$catalog       = $resolver->public_catalog( 50 );
$catalog_names = array_map(
	static function ( $item ) {
		return $item['name'];
	},
	$catalog
);
wpnb_assert( in_array( 'vendor/compatible', $catalog_names, true ), 'Catalog surfaces an already-installed third-party public Ability.' );
wpnb_assert( ! in_array( 'wp-native-builder/internal', $catalog_names, true ), 'Catalog does not mirror Bridge-owned Abilities.' );
wpnb_assert( ! in_array( 'mcp-adapter/meta', $catalog_names, true ), 'Catalog omits MCP Adapter meta abilities.' );

wpnb_test_reset_state();
$GLOBALS['wpnb_test']['options'] = array(
	Settings::OPTION_NAME   => $settings->defaults(),
	'permalink_structure'   => '/%postname%/',
	'show_on_front'         => 'page',
	'page_on_front'         => 42,
	'page_for_posts'        => 43,
	'active_plugins'        => array( 'acme/acme.php' ),
);
$GLOBALS['wpnb_test']['capabilities'] = array(
	'read'              => true,
	'edit_posts'        => true,
	'publish_posts'     => true,
	'upload_files'      => true,
	'manage_categories' => true,
);
$GLOBALS['wpnb_test']['plugins'] = array(
	'acme/acme.php' => array( 'Name' => 'Acme Content', 'Version' => '2.3.4' ),
);
$GLOBALS['wpnb_test']['theme'] = array(
	'Name'       => 'Twenty Twenty-Six',
	'Version'    => '1.0',
	'stylesheet' => 'twentytwentysix',
	'template'   => 'twentytwentysix',
	'block'      => true,
);
$GLOBALS['wpnb_test']['post_types'] = array(
	'book' => (object) array(
		'name'         => 'book',
		'label'        => 'Books',
		'hierarchical' => false,
		'show_in_rest' => true,
		'rest_base'    => 'books',
	),
);
$GLOBALS['wpnb_test']['post_type_supports'] = array(
	'book' => array( 'editor' => true, 'thumbnail' => true ),
);
$GLOBALS['wpnb_test']['taxonomies'] = array(
	'genre' => (object) array(
		'name'         => 'genre',
		'label'        => 'Genres',
		'hierarchical' => true,
		'show_in_rest' => true,
		'rest_base'    => 'genres',
		'object_type'  => array( 'book' ),
	),
);
$GLOBALS['wpnb_test']['abilities'] = array(
	'core/get-site-info' => wpnb_test_ability( 'core/get-site-info', array( 'fields' => array( 'type' => 'array' ) ), array( 'public' => true ), 'site' ),
	'core/get-user-info' => wpnb_test_ability( 'core/get-user-info', array( 'fields' => array( 'type' => 'array' ) ), array( 'public' => true ), 'user' ),
	'core/get-environment-info' => wpnb_test_ability( 'core/get-environment-info', array( 'fields' => array( 'type' => 'array' ) ), array( 'public' => true ), 'site' ),
	'core/read-content' => wpnb_test_ability(
		'core/read-content',
		array(
			'post_type' => array( 'type' => 'string' ),
			'fields'    => array( 'type' => 'array' ),
		),
		array( 'public' => true ),
		'content'
	),
	'acme/site-builder-info' => wpnb_test_ability( 'acme/site-builder-info', array(), array( 'public' => true ), 'acme' ),
);
$permissions = new Permissions( $settings );
$registrar   = new Registrar( $environment, $settings, $permissions );
$registrar->register_abilities();
wpnb_assert( ! isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/content-read'] ), 'Compatible upstream content-read Ability suppresses the duplicate Bridge read fallback.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/content-upsert'] ), 'Bridge content mutation fallback remains available for uncovered operations.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/blocks-read'] ), 'Generic Gutenberg block inspection ability is registered.' );
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/blocks-mutate'] ), 'Generic Gutenberg block mutation ability is registered.' );
$site_ability = $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/site-context'];
wpnb_assert( true === call_user_func( $site_ability['permission_callback'] ), 'Site context honors Site Read plus WordPress read capability.' );
$site_context = call_user_func( $site_ability['execute_callback'] );
wpnb_assert( 'core/get-site-info' === $site_context['reuse']['site_info'], 'Site context advertises compatible Core site-info reuse.' );
wpnb_assert( 'core/get-user-info' === $site_context['reuse']['user_info'], 'Site context advertises compatible Core user-info reuse.' );
wpnb_assert( 'core/get-environment-info' === $site_context['reuse']['environment_info'], 'Site context advertises compatible Core environment-info reuse.' );
wpnb_assert( 'core/read-content' === $site_context['reuse']['content_read'], 'Site context advertises a compatible installed content-read Ability.' );
wpnb_assert( 'Twenty Twenty-Six' === $site_context['theme']['name'], 'Site context is theme-neutral and reports a non-Astra block theme.' );
wpnb_assert( true === $site_context['theme']['is_block_theme'], 'Site context reports block-theme capability.' );
wpnb_assert( 'book' === $site_context['post_types'][0]['name'], 'Generic site inspection discovers a plugin-provided editable post type.' );
wpnb_assert( true === $site_context['post_types'][0]['supports_editor'], 'Generic post-type discovery reports editor support.' );
wpnb_assert( 'genre' === $site_context['taxonomies'][0]['name'], 'Generic site inspection discovers a plugin-provided taxonomy.' );
wpnb_assert( 'acme/acme.php' === $site_context['plugins'][0]['file'], 'Site context reports the installed provider plugin.' );
wpnb_assert( true === $site_context['plugins'][0]['active'], 'Site context reports provider activation state.' );
$site_catalog_names = array_map(
	static function ( $item ) {
		return $item['name'];
	},
	$site_context['external_abilities']
);
wpnb_assert( in_array( 'acme/site-builder-info', $site_catalog_names, true ), 'Site context exposes a third-party Ability hint without a hardcoded Acme integration.' );

unset( $GLOBALS['wpnb_test']['abilities']['core/read-content'] );
$site_context_without_read = call_user_func( $site_ability['execute_callback'] );
wpnb_assert( '' === $site_context_without_read['reuse']['content_read'], 'Missing optional external content Ability cleanly falls back to no reuse candidate.' );
$GLOBALS['wpnb_test']['registered_abilities'] = array();
$registrar_without_read = new Registrar( $environment, $settings, $permissions );
$registrar_without_read->register_abilities();
wpnb_assert( isset( $GLOBALS['wpnb_test']['registered_abilities']['wp-native-builder/content-read'] ), 'Bridge content-read fallback registers when the compatible upstream Ability is absent.' );

$GLOBALS['wpnb_test']['options'][ Settings::OPTION_NAME ][ Settings::GROUP_SITE_READ ] = 0;
wpnb_assert( false === call_user_func( $site_ability['permission_callback'] ), 'Disabled Site Read group denies site-context.' );

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
