<?php
/**
 * Dependency-free regression coverage for Issue #34 generic post metadata abilities.
 */

define( 'WP_NATIVE_BUILDER_BRIDGE_TEST_MODE', true );
require __DIR__ . '/bootstrap.php';

use WP_Native_Builder_Bridge\Abilities\Post_Meta_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue34_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['wpnb_issue34_posts'] = array(
	101 => (object) array(
		'ID'          => 101,
		'post_type'   => 'wpnb_builder_fixture',
		'post_status' => 'draft',
	),
	102 => (object) array(
		'ID'          => 102,
		'post_type'   => 'wpnb_doc',
		'post_status' => 'private',
	),
);
$GLOBALS['wpnb_issue34_meta'] = array(
	101 => array(
		'_builder_markup' => array( '<footer>Old</footer>' ),
		'api_secret'      => array( 'must-not-be-exposed' ),
		'multi_value_key' => array( 'first', 'second' ),
		'nested_object'   => array( array( 'object' => (object) array( 'state' => 'keep' ) ) ),
	),
	102 => array(
		'_wpnb_workspace_state' => array( '{"private":true}' ),
	),
);
$GLOBALS['wpnb_issue34_registered_meta'] = array();
$GLOBALS['wpnb_issue34_mapped_caps']     = null;

function get_post( $post_id ) {
	return isset( $GLOBALS['wpnb_issue34_posts'][ $post_id ] ) ? $GLOBALS['wpnb_issue34_posts'][ $post_id ] : null;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$all = isset( $GLOBALS['wpnb_issue34_meta'][ $post_id ] ) ? $GLOBALS['wpnb_issue34_meta'][ $post_id ] : array();
	if ( '' === $key ) {
		return $all;
	}
	$values = isset( $all[ $key ] ) ? $all[ $key ] : array();
	if ( $single ) {
		return isset( $values[0] ) ? $values[0] : '';
	}
	return $values;
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	$values = get_post_meta( $post_id, $key, false );
	if ( $unique && $values ) {
		return false;
	}
	$GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ][] = $value;
	return count( $GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ] );
}

function update_post_meta( $post_id, $key, $value, $prev_value = '' ) {
	$values = get_post_meta( $post_id, $key, false );
	if ( ! $values ) {
		$GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ] = array( $value );
		return true;
	}
	if ( '' !== $prev_value && serialize( $values[0] ) !== serialize( $prev_value ) ) {
		return false;
	}
	$GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ] = array( $value );
	return true;
}

function delete_post_meta( $post_id, $key, $value = '' ) {
	$values = get_post_meta( $post_id, $key, false );
	if ( ! $values ) {
		return false;
	}
	if ( '' !== $value && serialize( $values[0] ) !== serialize( $value ) ) {
		return false;
	}
	unset( $GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ] );
	return true;
}

function is_protected_meta( $key, $type = null ) {
	return 0 === strpos( (string) $key, '_' );
}

function get_registered_meta_keys( $object_type, $object_subtype = '' ) {
	return $GLOBALS['wpnb_issue34_registered_meta'];
}

function has_filter( $hook_name, $callback = false ) {
	return false;
}

function map_meta_cap( $capability, $user_id, ...$args ) {
	$extra = $GLOBALS['wpnb_issue34_mapped_caps'];
	return is_array( $extra ) ? array_merge( array( $capability ), $extra ) : array( $capability );
}

function maybe_serialize( $data ) {
	return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data;
}

function maybe_unserialize( $data ) {
	if ( is_string( $data ) && preg_match( '/^[aObisd]:/', $data ) ) {
		$value = @unserialize( $data );
		return false !== $value || 'b:0;' === $data ? $value : $data;
	}
	return $data;
}

wpnb_test_reset_state();
$GLOBALS['wpnb_test']['capabilities']['read']             = true;
$GLOBALS['wpnb_test']['capabilities']['edit_post']        = true;
$GLOBALS['wpnb_test']['capabilities']['edit_post_meta']   = true;
$GLOBALS['wpnb_test']['capabilities']['add_post_meta']    = true;
$GLOBALS['wpnb_test']['capabilities']['delete_post_meta'] = true;

$settings    = new Settings();
$permissions = new Permissions( $settings );
$abilities   = new Post_Meta_Abilities( $permissions, new Mutation_Log() );
$defaults    = $settings->defaults();
wpnb_issue34_assert( isset( $defaults[ Settings::GROUP_ADVANCED_METADATA ] ), 'Advanced Metadata group is missing.' );
wpnb_issue34_assert( 0 === $defaults[ Settings::GROUP_ADVANCED_METADATA ], 'Advanced Metadata must default to disabled.' );
update_option( Settings::OPTION_NAME, $defaults, false );
wpnb_issue34_assert( ! $abilities->can_read( array( 'post_id' => 101, 'key' => '_builder_markup' ) ), 'Metadata was readable while Advanced Metadata was disabled.' );

$access                                      = $defaults;
$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );

$listed = $abilities->read( array( 'post_id' => 101 ) );
wpnb_issue34_assert( ! is_wp_error( $listed ), 'Metadata discovery failed.' );
$listed_keys = array_column( $listed['items'], 'key' );
wpnb_issue34_assert( in_array( '_builder_markup', $listed_keys, true ), 'Builder metadata was not discoverable.' );
wpnb_issue34_assert( ! in_array( 'api_secret', $listed_keys, true ), 'Credential metadata leaked through discovery.' );

foreach ( array( 'sessionToken', 'session_token', 'idToken', 'id_token', 'jwtToken', 'jwt_token', 'clientSecret', 'accessToken' ) as $secret_key ) {
	$GLOBALS['wpnb_issue34_meta'][101][ $secret_key ] = array( 'secret-fixture' );
	$secret = $abilities->read( array( 'post_id' => 101, 'key' => $secret_key, 'include_values' => true ) );
	wpnb_issue34_assert( is_wp_error( $secret ) && 'sensitive_post_meta_key' === $secret->get_error_code(), 'Credential-like key was not denied: ' . $secret_key );
}

$read = $abilities->read( array( 'post_id' => 101, 'key' => '_builder_markup', 'include_values' => true ) );
wpnb_issue34_assert( ! is_wp_error( $read ) && 1 === $read['items'][0]['count'], 'Builder metadata read failed.' );
$old_hash = $read['items'][0]['state_hash'];
$updated  = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'value_json'          => json_encode( '<footer>New</footer>' ),
		'expected_state_hash' => $old_hash,
	)
);
wpnb_issue34_assert( ! is_wp_error( $updated ), 'Builder metadata update failed.' );
wpnb_issue34_assert( '<footer>New</footer>' === get_post_meta( 101, '_builder_markup', true ), 'Builder metadata update did not persist.' );

$stale = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'value_json'          => json_encode( 'stale' ),
		'expected_state_hash' => $old_hash,
	)
);
wpnb_issue34_assert( is_wp_error( $stale ) && 'stale_post_meta_conflict' === $stale->get_error_code(), 'Stale update was not rejected.' );

$multi = $abilities->read( array( 'post_id' => 101, 'key' => 'multi_value_key' ) );
$multi_update = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => 'multi_value_key',
		'value_json'          => json_encode( 'replacement' ),
		'expected_state_hash' => $multi['items'][0]['state_hash'],
	)
);
wpnb_issue34_assert( is_wp_error( $multi_update ) && 'post_meta_multiple_values_unsupported' === $multi_update->get_error_code(), 'Multi-row update did not fail closed.' );

$nested = $abilities->read( array( 'post_id' => 101, 'key' => 'nested_object' ) );
$nested_delete = $abilities->delete(
	array(
		'post_id'             => 101,
		'key'                 => 'nested_object',
		'expected_state_hash' => $nested['items'][0]['state_hash'],
	)
);
wpnb_issue34_assert( is_wp_error( $nested_delete ) && 'post_meta_object_value_unsupported' === $nested_delete->get_error_code(), 'Opaque nested-object metadata delete did not fail closed.' );
wpnb_issue34_assert( isset( $GLOBALS['wpnb_issue34_meta'][101]['nested_object'] ), 'Denied opaque metadata delete changed storage.' );

$workspace = $abilities->read( array( 'post_id' => 102 ) );
wpnb_issue34_assert( is_wp_error( $workspace ) && 'post_meta_target_not_allowed' === $workspace->get_error_code(), 'Workspace metadata became reachable.' );

$fresh   = $abilities->read( array( 'post_id' => 101, 'key' => '_builder_markup' ) );
$deleted = $abilities->delete(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'expected_state_hash' => $fresh['items'][0]['state_hash'],
	)
);
wpnb_issue34_assert( ! is_wp_error( $deleted ) && true === $deleted['deleted'], 'Authorized metadata delete failed.' );
wpnb_issue34_assert( array() === get_post_meta( 101, '_builder_markup', false ), 'Metadata delete left the row behind.' );

echo "PASS: Issue #34 advanced post metadata regression coverage.\n";
