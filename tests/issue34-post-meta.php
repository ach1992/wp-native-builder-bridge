<?php
/**
 * Regression coverage for Issue #34 generic post metadata abilities.
 */

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
		'_builder_markup'  => array( '<footer>Old</footer>' ),
		'api_secret'       => array( 'must-not-be-exposed' ),
		'multi_value_key'  => array( 'first', 'second' ),
		'_provider_locked' => array( 'locked' ),
	),
	102 => array(
		'_wpnb_workspace_state' => array( '{"private":true}' ),
	),
);
$GLOBALS['wpnb_issue34_registered_meta'] = array();
$GLOBALS['wpnb_issue34_auth_filters']    = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return isset( $GLOBALS['wpnb_issue34_posts'][ $post_id ] ) ? $GLOBALS['wpnb_issue34_posts'][ $post_id ] : null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
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
}
if ( ! function_exists( 'get_post_custom_keys' ) ) {
	function get_post_custom_keys( $post_id ) {
		return array_keys( get_post_meta( $post_id ) );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$current = get_post_meta( $post_id, $key, false );
		if ( 1 === count( $current ) && serialize( $current[0] ) === serialize( $value ) ) {
			return false;
		}
		$GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ] = array( $value );
		return true;
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		if ( ! isset( $GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ] ) ) {
			return false;
		}
		unset( $GLOBALS['wpnb_issue34_meta'][ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'is_protected_meta' ) ) {
	function is_protected_meta( $key, $type = null ) {
		return 0 === strpos( (string) $key, '_' );
	}
}
if ( ! function_exists( 'get_registered_meta_keys' ) ) {
	function get_registered_meta_keys( $object_type, $object_subtype = '' ) {
		return $GLOBALS['wpnb_issue34_registered_meta'];
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook_name, $callback = false ) {
		return ! empty( $GLOBALS['wpnb_issue34_auth_filters'][ $hook_name ] );
	}
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data;
	}
}

wpnb_test_reset_state();
$GLOBALS['wpnb_test']['post_types']['wpnb_builder_fixture'] = (object) array(
	'name'               => 'wpnb_builder_fixture',
	'public'             => false,
	'publicly_queryable' => false,
	'show_in_rest'       => false,
	'cap'                => (object) array( 'edit_posts' => 'edit_posts' ),
);
$GLOBALS['wpnb_test']['capabilities']['read']             = true;
$GLOBALS['wpnb_test']['capabilities']['edit_post']        = true;
$GLOBALS['wpnb_test']['capabilities']['edit_post_meta']   = true;
$GLOBALS['wpnb_test']['capabilities']['add_post_meta']    = true;
$GLOBALS['wpnb_test']['capabilities']['delete_post_meta'] = true;

$settings    = new Settings();
$permissions = new Permissions( $settings );
$abilities   = new Post_Meta_Abilities( $permissions, new Mutation_Log() );

$defaults = $settings->defaults();
wpnb_issue34_assert( isset( $defaults[ Settings::GROUP_ADVANCED_METADATA ] ), 'Advanced Metadata group was not registered.' );
wpnb_issue34_assert( 0 === $defaults[ Settings::GROUP_ADVANCED_METADATA ], 'Advanced Metadata must default to disabled.' );
update_option( Settings::OPTION_NAME, $defaults, false );

wpnb_issue34_assert(
	! $abilities->can_read( array( 'post_id' => 101, 'key' => '_builder_markup' ) ),
	'Private metadata was exposed while Advanced Metadata was disabled.'
);

$access                                      = $defaults;
$access[ Settings::GROUP_ADVANCED_METADATA ] = 1;
update_option( Settings::OPTION_NAME, $access, false );

wpnb_issue34_assert(
	$abilities->can_read( array( 'post_id' => 101, 'key' => '_builder_markup' ) ),
	'Private non-REST CPT metadata stayed blocked after Advanced Metadata was enabled.'
);

$listed = $abilities->read( array( 'post_id' => 101 ) );
wpnb_issue34_assert( ! is_wp_error( $listed ), 'Metadata list failed.' );
$listed_keys = array_column( $listed['items'], 'key' );
wpnb_issue34_assert( in_array( '_builder_markup', $listed_keys, true ), 'Protected builder metadata was not discoverable.' );
wpnb_issue34_assert( ! in_array( 'api_secret', $listed_keys, true ), 'Credential-like metadata leaked through key discovery.' );

$read = $abilities->read(
	array(
		'post_id'        => 101,
		'key'            => '_builder_markup',
		'include_values' => true,
	)
);
wpnb_issue34_assert( ! is_wp_error( $read ), 'Exact private metadata read failed.' );
wpnb_issue34_assert( 1 === $read['items'][0]['count'], 'Exact metadata count was incorrect.' );
wpnb_issue34_assert( '<footer>Old</footer>' === json_decode( $read['items'][0]['values'][0]['value_json'], true ), 'Metadata value did not round-trip through value_json.' );
$old_hash = $read['items'][0]['state_hash'];

$updated = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'value_json'          => json_encode( '<footer>New</footer>' ),
		'expected_state_hash' => $old_hash,
	)
);
wpnb_issue34_assert( ! is_wp_error( $updated ), 'Protected builder metadata update failed.' );
wpnb_issue34_assert( '<footer>New</footer>' === get_post_meta( 101, '_builder_markup', true ), 'Metadata update did not persist the requested value.' );

$stale = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'value_json'          => json_encode( '<footer>Stale</footer>' ),
		'expected_state_hash' => $old_hash,
	)
);
wpnb_issue34_assert( is_wp_error( $stale ) && 'stale_post_meta_conflict' === $stale->get_error_code(), 'Stale metadata update was not rejected.' );
wpnb_issue34_assert( '<footer>New</footer>' === get_post_meta( 101, '_builder_markup', true ), 'Stale metadata update changed stored data.' );

$multi_read = $abilities->read( array( 'post_id' => 101, 'key' => 'multi_value_key' ) );
$multi_update = $abilities->update(
	array(
		'post_id'             => 101,
		'key'                 => 'multi_value_key',
		'value_json'          => json_encode( 'replacement' ),
		'expected_state_hash' => $multi_read['items'][0]['state_hash'],
	)
);
wpnb_issue34_assert( is_wp_error( $multi_update ) && 'post_meta_multiple_values_unsupported' === $multi_update->get_error_code(), 'Ambiguous multi-row metadata update did not fail closed.' );

$secret = $abilities->read( array( 'post_id' => 101, 'key' => 'api_secret', 'include_values' => true ) );
wpnb_issue34_assert( is_wp_error( $secret ) && 'sensitive_post_meta_key' === $secret->get_error_code(), 'Credential-like metadata was not denied.' );

$workspace = $abilities->read( array( 'post_id' => 102 ) );
wpnb_issue34_assert( is_wp_error( $workspace ) && 'post_meta_target_not_allowed' === $workspace->get_error_code(), 'Workspace internal metadata became reachable through the generic metadata surface.' );

$GLOBALS['wpnb_issue34_registered_meta']['_provider_locked'] = array( 'type' => 'string' );
$GLOBALS['wpnb_test']['capabilities']['edit_post_meta']      = false;
wpnb_issue34_assert(
	! $abilities->can_read( array( 'post_id' => 101, 'key' => '_provider_locked' ) ),
	'Registered provider metadata bypassed its explicit WordPress meta capability.'
);
$GLOBALS['wpnb_test']['capabilities']['edit_post_meta'] = true;
wpnb_issue34_assert(
	$abilities->can_read( array( 'post_id' => 101, 'key' => '_provider_locked' ) ),
	'Registered provider metadata stayed blocked after its WordPress meta capability allowed access.'
);

$fresh = $abilities->read( array( 'post_id' => 101, 'key' => '_builder_markup' ) );
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
update_option( Settings::OPTION_NAME, $access, false );
$delete_denied = $abilities->delete(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'expected_state_hash' => $fresh['items'][0]['state_hash'],
	)
);
wpnb_issue34_assert( is_wp_error( $delete_denied ) && 'destructive_access_disabled' === $delete_denied->get_error_code(), 'Metadata delete bypassed the destructive access group.' );

$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
update_option( Settings::OPTION_NAME, $access, false );
$fresh = $abilities->read( array( 'post_id' => 101, 'key' => '_builder_markup' ) );
$deleted = $abilities->delete(
	array(
		'post_id'             => 101,
		'key'                 => '_builder_markup',
		'expected_state_hash' => $fresh['items'][0]['state_hash'],
	)
);
wpnb_issue34_assert( ! is_wp_error( $deleted ) && true === $deleted['deleted'], 'Metadata delete failed after destructive access was enabled.' );
wpnb_issue34_assert( array() === get_post_meta( 101, '_builder_markup', false ), 'Metadata delete left the value behind.' );

echo "PASS: Issue #34 advanced post metadata regression coverage.\n";
