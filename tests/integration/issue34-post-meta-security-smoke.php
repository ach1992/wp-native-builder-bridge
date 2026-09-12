<?php
/** Security-critical real WordPress coverage for Issue #34 / Contract Revision 3. */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "WordPress is not loaded.\n" ); exit( 1 ); }

$failures = array();
$ok = static function ( $condition, $message ) use ( &$failures ) { if ( ! $condition ) { $failures[] = $message; } };
$settings_class = 'WP_Native_Builder_Bridge\\Support\\Settings';
$original = class_exists( $settings_class ) ? get_option( $settings_class::OPTION_NAME, null ) : null;
$post_id = 0;
$revision_id = 0;
$registered = array();
$filters = array();
$actions = array();
$guard_filter = static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$filters ) { add_filter( $hook, $callback, $priority, $accepted_args ); $filters[] = array( $hook, $callback, $priority ); };
$guard_action = static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$actions ) { add_action( $hook, $callback, $priority, $accepted_args ); $actions[] = array( $hook, $callback, $priority ); };
$physical = static function ( $post_id, $key ) { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $post_id, $key ) ); };

try {
	$ok( class_exists( $settings_class ) && function_exists( 'wp_get_ability' ), 'Issue #34 prerequisites are unavailable.' );
	$read = wp_get_ability( 'wp-native-builder/post-meta-read' );
	$update = wp_get_ability( 'wp-native-builder/post-meta-update' );
	$delete = wp_get_ability( 'wp-native-builder/post-meta-delete' );
	if ( ! $read || ! $update || ! $delete ) { throw new RuntimeException( 'Issue #34 Abilities are unavailable.' ); }
	$settings = new $settings_class();
	$enabled = $settings->defaults();
	$enabled[ $settings_class::GROUP_ADVANCED_METADATA ] = 1;
	$enabled[ $settings_class::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( $settings_class::OPTION_NAME, $enabled, false );
	register_post_type( 'wpnb_meta_security', array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title', 'revisions' ) ) );
	$post_id = wp_insert_post( array( 'post_type' => 'wpnb_meta_security', 'post_status' => 'draft', 'post_title' => 'Issue 34 Rev3 fixture' ), true );
	if ( is_wp_error( $post_id ) || $post_id < 1 ) { throw new RuntimeException( 'Fixture post creation failed.' ); }

	/* F-001/F-004: canonical backslashes and credential/session/identity token normalization. */
	$literal = 'literal\\path';
	add_post_meta( $post_id, wp_slash( $literal ), 'old', true );
	$literal_read = $read->execute( array( 'post_id' => $post_id, 'key' => $literal ) );
	$literal_update = $update->execute( array( 'post_id' => $post_id, 'key' => $literal, 'value_json' => wp_json_encode( 'new' ), 'expected_state_hash' => $literal_read['items'][0]['state_hash'] ) );
	$ok( ! is_wp_error( $literal_update ) && 'new' === get_post_meta( $post_id, $literal, true ), 'Literal-backslash key identity regressed.' );
	$secrets = array( 'sessionToken', 'session_token', 'session-token', 'session.token', 'SESSION__TOKEN', 'session\\token', 'idToken', 'id_token', 'idtoken', 'jwtToken', 'jwt_token', 'jwttoken', 'clientSecret', 'accessToken' );
	foreach ( $secrets as $key ) { add_post_meta( $post_id, wp_slash( $key ), 'secret', true ); $input = array( 'post_id' => $post_id, 'key' => $key, 'include_values' => true ); $ok( false === $read->check_permissions( $input ), 'Sensitive key passed the permission gate: ' . $key ); $result = $read->execute( $input ); $ok( is_wp_error( $result ) && 'ability_invalid_permissions' === $result->get_error_code(), 'Sensitive key execution was not denied: ' . $key ); }
	$design_token = 'design_token'; add_post_meta( $post_id, $design_token, 'blue', true ); $ok( ! is_wp_error( $read->execute( array( 'post_id' => $post_id, 'key' => $design_token ) ) ), 'Unrelated design_token was overblocked.' );

	/* F-002: revisions canonicalize to parent before subtype metadata authorization. */
	$revision_key = '_wpnb_revision_locked';
	register_post_meta( 'wpnb_meta_security', $revision_key, array( 'type' => 'string', 'single' => true, 'auth_callback' => '__return_false' ) ); $registered[] = $revision_key;
	add_post_meta( $post_id, $revision_key, 'locked', true );
	$revision_id = wp_insert_post( array( 'post_type' => 'revision', 'post_parent' => $post_id, 'post_status' => 'inherit', 'post_title' => 'rev' ), true );
	$ok( ! is_wp_error( $revision_id ) && wp_is_post_revision( $revision_id ), 'Revision fixture failed.' );
	if ( ! is_wp_error( $revision_id ) ) { $ok( is_wp_error( $read->execute( array( 'post_id' => $revision_id, 'key' => $revision_key ) ) ), 'Revision bypassed parent metadata authorization.' ); }

	/* F-003/F-008: physical state ignores registered defaults and virtual get_post_metadata reads. */
	$default_key = '_wpnb_default';
	register_post_meta( 'wpnb_meta_security', $default_key, array( 'type' => 'string', 'single' => true, 'default' => 'registered-default', 'auth_callback' => '__return_true' ) ); $registered[] = $default_key;
	$before = $read->execute( array( 'post_id' => $post_id, 'key' => $default_key ) );
	$ok( ! is_wp_error( $before ) && 0 === $before['items'][0]['count'], 'Registered default was mistaken for a physical row.' );
	$created = $update->execute( array( 'post_id' => $post_id, 'key' => $default_key, 'value_json' => wp_json_encode( 'registered-default' ), 'expected_state_hash' => $before['items'][0]['state_hash'] ) );
	$ok( ! is_wp_error( $created ) && 1 === $created['count'] && $created['state_hash'] !== $before['items'][0]['state_hash'], 'Physical default row identity failed.' );
	$virtual_key = '_wpnb_virtual';
	add_post_meta( $post_id, $virtual_key, 'one', false ); add_post_meta( $post_id, $virtual_key, 'two', false );
	$virtual = static function ( $check, $object_id, $meta_key ) use ( $post_id, $virtual_key ) { return (int) $object_id === (int) $post_id && (string) $meta_key === $virtual_key ? array( 'virtual-one' ) : $check; };
	$guard_filter( 'get_post_metadata', $virtual, 10, 5 );
	$virtual_read = $read->execute( array( 'post_id' => $post_id, 'key' => $virtual_key ) );
	$ok( ! is_wp_error( $virtual_read ) && 2 === $virtual_read['items'][0]['count'], 'Virtual metadata filter hid physical row cardinality.' );
	$virtual_update = $update->execute( array( 'post_id' => $post_id, 'key' => $virtual_key, 'value_json' => wp_json_encode( 'replace' ), 'expected_state_hash' => $virtual_read['items'][0]['state_hash'] ) );
	$ok( is_wp_error( $virtual_update ) && 'post_meta_multiple_values_unsupported' === $virtual_update->get_error_code(), 'Virtual read permitted ambiguous physical mutation.' );
	remove_filter( 'get_post_metadata', $virtual, 10 );

	/* F-005/F-009: opaque/non-lossless state is neither updateable nor deletable. */
	$opaque_key = '_wpnb_opaque'; $opaque = array( 'nested' => (object) array( 'keep' => true ) ); add_post_meta( $post_id, $opaque_key, $opaque, true );
	$opaque_read = $read->execute( array( 'post_id' => $post_id, 'key' => $opaque_key ) );
	$opaque_update = $update->execute( array( 'post_id' => $post_id, 'key' => $opaque_key, 'value_json' => wp_json_encode( array( 'nested' => array( 'replace' => true ) ) ), 'expected_state_hash' => $opaque_read['items'][0]['state_hash'] ) );
	$opaque_delete = $delete->execute( array( 'post_id' => $post_id, 'key' => $opaque_key, 'expected_state_hash' => $opaque_read['items'][0]['state_hash'] ) );
	$ok( is_wp_error( $opaque_update ) && is_wp_error( $opaque_delete ) && is_object( get_post_meta( $post_id, $opaque_key, true )['nested'] ), 'Opaque metadata did not fail closed.' );

	/* F-006: same-value concurrent update cannot fan out; Bridge row is compensated. */
	$update_key = '_wpnb_same_update'; add_post_meta( $post_id, $update_key, 'old', true ); $update_read = $read->execute( array( 'post_id' => $post_id, 'key' => $update_key ) );
	$race_update = null; $race_update = static function ( $check, $object_id, $meta_key ) use ( &$race_update, $post_id, $update_key ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $update_key ) { global $wpdb; remove_filter( 'update_post_metadata', $race_update, 1 ); $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $update_key, 'meta_value' => 'old' ), array( '%d', '%s', '%s' ) ); wp_cache_delete( $post_id, 'post_meta' ); } return $check; };
	$guard_filter( 'update_post_metadata', $race_update, 1, 5 );
	$update_result = $update->execute( array( 'post_id' => $post_id, 'key' => $update_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $update_read['items'][0]['state_hash'] ) );
	$update_rows = $physical( $post_id, $update_key );
	$ok( is_wp_error( $update_result ) && 'stale_post_meta_conflict' === $update_result->get_error_code() && 2 === count( $update_rows ) && 'old' === $update_rows[0]->meta_value && 'old' === $update_rows[1]->meta_value, 'Same-value update race changed concurrent state.' );

	/* F-006: same-value concurrent delete cannot be collateral-deleted; original row is restored. */
	$delete_key = '_wpnb_same_delete'; add_post_meta( $post_id, $delete_key, 'old', true ); $delete_read = $read->execute( array( 'post_id' => $post_id, 'key' => $delete_key ) );
	$race_delete = null; $race_delete = static function ( $check, $object_id, $meta_key ) use ( &$race_delete, $post_id, $delete_key ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $delete_key ) { global $wpdb; remove_filter( 'delete_post_metadata', $race_delete, 1 ); $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $delete_key, 'meta_value' => 'old' ), array( '%d', '%s', '%s' ) ); wp_cache_delete( $post_id, 'post_meta' ); } return $check; };
	$guard_filter( 'delete_post_metadata', $race_delete, 1, 5 );
	$delete_result = $delete->execute( array( 'post_id' => $post_id, 'key' => $delete_key, 'expected_state_hash' => $delete_read['items'][0]['state_hash'] ) );
	$delete_rows = $physical( $post_id, $delete_key );
	$ok( is_wp_error( $delete_result ) && 'stale_post_meta_conflict' === $delete_result->get_error_code() && 2 === count( $delete_rows ) && 'old' === $delete_rows[0]->meta_value && 'old' === $delete_rows[1]->meta_value, 'Same-value delete race removed concurrent/original state.' );

	/* F-010: Core unique add race cleans up only the Bridge-created row. */
	$create_key = '_wpnb_create_race'; $create_read = $read->execute( array( 'post_id' => $post_id, 'key' => $create_key ) );
	$race_create = null; $race_create = static function ( $object_id, $meta_key ) use ( &$race_create, $post_id, $create_key ) { if ( (int) $object_id === (int) $post_id && (string) $meta_key === $create_key ) { global $wpdb; remove_action( 'add_post_meta', $race_create, 1 ); $wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $create_key, 'meta_value' => 'concurrent' ), array( '%d', '%s', '%s' ) ); wp_cache_delete( $post_id, 'post_meta' ); } };
	$guard_action( 'add_post_meta', $race_create, 1, 3 );
	$create_result = $update->execute( array( 'post_id' => $post_id, 'key' => $create_key, 'value_json' => wp_json_encode( 'bridge' ), 'expected_state_hash' => $create_read['items'][0]['state_hash'] ) );
	$create_rows = $physical( $post_id, $create_key );
	$ok( is_wp_error( $create_result ) && 'stale_post_meta_conflict' === $create_result->get_error_code() && 1 === count( $create_rows ) && 'concurrent' === $create_rows[0]->meta_value, 'Create contention left a Bridge duplicate row.' );

	/* Final destructive group gate still applies. */
	$gate_key = '_wpnb_delete_gate'; add_post_meta( $post_id, $gate_key, 'keep', true ); $gate_read = $read->execute( array( 'post_id' => $post_id, 'key' => $gate_key ) ); $enabled[ $settings_class::GROUP_USERS_DESTRUCTIVE ] = 0; update_option( $settings_class::OPTION_NAME, $enabled, false );
	$gate_result = $delete->execute( array( 'post_id' => $post_id, 'key' => $gate_key, 'expected_state_hash' => $gate_read['items'][0]['state_hash'] ) );
	$ok( is_wp_error( $gate_result ) && metadata_exists( 'post', $post_id, $gate_key ), 'Destructive access gate was bypassed.' );
} catch ( Throwable $exception ) { $failures[] = 'Unexpected Issue #34 test exception: ' . $exception->getMessage(); }
finally {
	foreach ( array_reverse( $filters ) as $filter ) { remove_filter( $filter[0], $filter[1], $filter[2] ); }
	foreach ( array_reverse( $actions ) as $action ) { remove_action( $action[0], $action[1], $action[2] ); }
	foreach ( array_unique( $registered ) as $key ) { unregister_post_meta( 'wpnb_meta_security', $key ); }
	if ( is_int( $revision_id ) && $revision_id > 0 ) { wp_delete_post( $revision_id, true ); }
	if ( is_int( $post_id ) && $post_id > 0 ) { wp_delete_post( $post_id, true ); }
	unregister_post_type( 'wpnb_meta_security' );
	if ( null === $original ) { delete_option( $settings_class::OPTION_NAME ); } else { update_option( $settings_class::OPTION_NAME, $original, false ); }
}
if ( $failures ) { foreach ( $failures as $failure ) { fwrite( STDERR, 'FAIL: ' . $failure . "\n" ); } exit( 1 ); }
echo "PASS: Issue #34 post metadata security semantics.\n";
