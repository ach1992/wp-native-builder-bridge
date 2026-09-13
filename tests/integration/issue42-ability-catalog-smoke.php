<?php
/** Real WordPress and Adapter contract-inspection tests. */
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue42_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$user = get_current_user_id();
try {
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	$catalog = wp_get_ability( 'wp-native-builder/abilities-read' );
	wpnb_issue42_assert( $catalog instanceof WP_Ability, 'Missing native catalog Ability.' );
	wpnb_issue42_assert( wp_get_ability( 'catalog-fixture/operation-136' ) instanceof WP_Ability, 'Isolated catalog fixture was not registered.' );
	$first = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'per_page' => 100 ) );
	wpnb_issue42_assert( ! is_wp_error( $first ), 'Catalog list failed native input/output validation.' );
	wpnb_issue42_assert( 137 === $first['total'] && 100 === count( $first['items'] ) && 2 === $first['total_pages'], 'Native catalog pagination is incorrect.' );
	wpnb_issue42_assert( 'catalog-fixture/operation-000' === $first['items'][0]['name'], 'Catalog ordering must be independent of registration order.' );
	wpnb_issue42_assert( 'not_evaluated' === $first['execution_permission'], 'Discovery must not claim permission.' );
	wpnb_issue42_assert( ! isset( $first['items'][0]['input_schema'] ), 'List must not dump all schemas.' );
	$second = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'per_page' => 100, 'page' => 2 ) );
	wpnb_issue42_assert( 37 === count( $second['items'] ) && 'catalog-fixture/operation-100' === $second['items'][0]['name'], 'Second page did not reach the remainder of the native registry.' );
	$filtered = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'search' => 'OPERATION-13' ) );
	wpnb_issue42_assert( 7 === $filtered['total'], 'Search and namespace filters failed.' );
	$empty_page = $catalog->execute( array( 'namespace' => 'catalog-fixture', 'page' => PHP_INT_MAX ) );
	wpnb_issue42_assert( array() === $empty_page['items'], 'Very large page must be empty and not overflow.' );
	$detail = $catalog->execute( array( 'action' => 'get', 'name' => 'catalog-fixture/operation-125' ) );
	wpnb_issue42_assert( ! is_wp_error( $detail ), 'Exact schema lookup failed native output validation.' );
	$provider = wp_get_ability( 'catalog-fixture/operation-125' );
	wpnb_issue42_assert( $provider->get_input_schema() === $detail['items'][0]['input_schema'], 'Native input schema was changed.' );
	wpnb_issue42_assert( $provider->get_output_schema() === $detail['items'][0]['output_schema'], 'Native output schema was changed.' );
	wpnb_issue42_assert( false === strpos( wp_json_encode( $detail ), 'CATALOG_PRIVATE_METADATA_MUST_NOT_LEAK' ), 'Arbitrary provider metadata leaked.' );
	$empty_schema = $catalog->execute( array( 'action' => 'get', 'name' => 'catalog-empty/schema' ) );
	wpnb_issue42_assert( ! is_wp_error( $empty_schema ) && array() === $empty_schema['items'][0]['input_schema'] && array() === $empty_schema['items'][0]['output_schema'], 'Absent native schemas were not preserved.' );
	$large_schema = $catalog->execute( array( 'action' => 'get', 'name' => 'catalog-large/schema' ) );
	wpnb_issue42_assert( is_wp_error( $large_schema ) && 'ability_catalog_response_too_large' === $large_schema->get_error_code(), 'Oversized contract must fail rather than truncate.' );
	$hidden_list = $catalog->execute( array( 'namespace' => 'catalog-hidden' ) );
	wpnb_issue42_assert( 0 === $hidden_list['total'], 'Hidden provider contracts leaked in list.' );
	foreach ( array( 'catalog-hidden/private', 'catalog-hidden/optout', 'catalog-missing/name' ) as $name ) {
		$result = $catalog->execute( array( 'action' => 'get', 'name' => $name ) );
		wpnb_issue42_assert( is_wp_error( $result ) && 'ability_contract_not_found' === $result->get_error_code(), 'Hidden and unknown names must have the same error.' );
	}
	foreach ( array( array( 'per_page' => 101 ), array( 'page' => 0 ), array( 'action' => 'execute' ), array( 'unknown' => true ), array( 'action' => 'get' ) ) as $input ) {
		wpnb_issue42_assert( is_wp_error( $catalog->execute( $input ) ), 'Malformed native catalog input must fail.' );
	}
	$adapter_info = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-native-builder/abilities-read' ) );
	wpnb_issue42_assert( ! is_wp_error( $adapter_info ) && 'wp-native-builder/abilities-read' === $adapter_info['name'], 'Official Adapter could not inspect the catalog contract.' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	$wrapped = $adapter->execute( array( 'ability_name' => 'wp-native-builder/abilities-read', 'parameters' => array( 'namespace' => 'catalog-fixture', 'page' => 2, 'per_page' => 100 ) ) );
	wpnb_issue42_assert( ! is_wp_error( $wrapped ) && true === $wrapped['success'] && 37 === count( $wrapped['data']['items'] ), 'Official Adapter execution did not preserve the catalog response.' );
	wpnb_issue42_assert( 0 === $GLOBALS['wpnb_catalog_permission_calls'] && 0 === $GLOBALS['wpnb_catalog_execute_calls'], 'Catalog invoked a target callback during inspection.' );
	$provider_denial = $provider->execute( array( 'target' => 1 ) );
	wpnb_issue42_assert( is_wp_error( $provider_denial ) && $GLOBALS['wpnb_catalog_permission_calls'] > 0 && 0 === $GLOBALS['wpnb_catalog_execute_calls'], 'Discovery must not change a provider execution denial.' );
	$off = $settings->defaults();
	$off[ Settings::GROUP_SITE_READ ] = 0;
	update_option( Settings::OPTION_NAME, $off, false );
	wpnb_issue42_assert( is_wp_error( $catalog->execute( array() ) ), 'Disabled Site Read must deny inspection.' );
	wpnb_issue42_assert( is_wp_error( $adapter->execute( array( 'ability_name' => 'wp-native-builder/abilities-read', 'parameters' => array() ) ) ), 'Adapter bypassed revoked Site Read.' );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	wp_set_current_user( 0 );
	wpnb_issue42_assert( is_wp_error( $catalog->execute( array() ) ), 'Anonymous caller could inspect the public registry through the Bridge.' );
	echo "PASS: Ability catalog native registry, privacy, pagination, permissions, and Adapter integration.\n";
} finally {
	wp_set_current_user( $user );
	update_option( Settings::OPTION_NAME, $original, false );
}
