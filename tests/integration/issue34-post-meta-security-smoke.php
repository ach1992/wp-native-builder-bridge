<?php
/**
 * Security-critical real WordPress regression coverage for Issue #34.
 *
 * Run inside the disposable integration WordPress environment as an administrator.
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

$settings_class = 'WP_Native_Builder_Bridge\\Support\\Settings';
$original       = class_exists( $settings_class ) ? get_option( $settings_class::OPTION_NAME, null ) : null;
$post_id        = 0;
$revision_id    = 0;
$registered     = array();
$filters        = array();

$empty_state_hash = hash( 'sha256', (string) maybe_serialize( array() ) );

$add_filter_guard = static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$filters ) {
	add_filter( $hook, $callback, $priority, $accepted_args );
	$filters[] = array( $hook, $callback, $priority );
};

try {
	$assert( class_exists( $settings_class ), 'Settings service is unavailable.' );
	$assert( function_exists( 'wp_get_ability' ), 'WordPress Ability API is unavailable.' );

	$meta_read   = wp_get_ability( 'wp-native-builder/post-meta-read' );
	$meta_update = wp_get_ability( 'wp-native-builder/post-meta-update' );
	$meta_delete = wp_get_ability( 'wp-native-builder/post-meta-delete' );
	$assert( null !== $meta_read && null !== $meta_update && null !== $meta_delete, 'Issue #34 metadata Abilities are unavailable.' );

	$settings = new $settings_class();
	$enabled  = $settings->defaults();
	$enabled[ $settings_class::GROUP_ADVANCED_METADATA ] = 1;
	$enabled[ $settings_class::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( $settings_class::OPTION_NAME, $enabled, false );

	register_post_type(
		'wpnb_meta_security',
		array(
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'supports'     => array( 'title', 'revisions' ),
		)
	);

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'wpnb_meta_security',
			'post_status' => 'draft',
			'post_title'  => 'Issue 34 security fixture',
		),
		true
	);
	$assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Security fixture post could not be created.' );
	if ( is_wp_error( $post_id ) || $post_id < 1 || ! $meta_read || ! $meta_update || ! $meta_delete ) {
		throw new RuntimeException( 'Issue #34 security fixture prerequisites failed.' );
	}

	/* F-001: canonical unslashed key identity must survive WordPress mutation APIs. */
	$literal_key     = 'literal\\path';
	$collapsed_key   = 'literalpath';
	$locked_key      = '_wpnb_provider_locked';
	$locked_alias    = '\\_wpnb_provider_locked';
	$sensitive_key   = 'api_secret';
	$sensitive_alias = 'api_\\secret';

	add_post_meta( $post_id, wp_slash( $literal_key ), 'literal-old', true );
	add_post_meta( $post_id, $collapsed_key, 'collapsed-untouched', true );
	add_post_meta( $post_id, $locked_key, 'locked-value', true );
	add_post_meta( $post_id, $sensitive_key, 'secret-untouched', true );

	register_post_meta(
		'wpnb_meta_security',
		$locked_key,
		array(
			'type'          => 'string',
			'single'        => true,
			'auth_callback' => '__return_false',
		)
	);
	$registered[] = $locked_key;

	$literal_read = $meta_read->execute(
		array(
			'post_id' => $post_id,
			'key'     => $literal_key,
		)
	);
	$assert( ! is_wp_error( $literal_read ), 'Literal-backslash metadata key could not be read.' );
	if ( ! is_wp_error( $literal_read ) ) {
		$literal_update = $meta_update->execute(
			array(
				'post_id'             => $post_id,
				'key'                 => $literal_key,
				'value_json'          => wp_json_encode( 'literal-new' ),
				'expected_state_hash' => $literal_read['items'][0]['state_hash'],
			)
		);
		$assert( ! is_wp_error( $literal_update ), 'Literal-backslash metadata update failed.' );
		$assert( 'literal-new' === get_post_meta( $post_id, $literal_key, true ), 'Literal-backslash metadata update targeted the wrong key.' );
		$assert( 'collapsed-untouched' === get_post_meta( $post_id, $collapsed_key, true ), 'Literal-backslash metadata update changed the collapsed key.' );
	}

	$locked_alias_update = $meta_update->execute(
		array(
			'post_id'             => $post_id,
			'key'                 => $locked_alias,
			'value_json'          => wp_json_encode( 'must-not-reach-locked-key' ),
			'expected_state_hash' => $empty_state_hash,
		)
	);
	$assert( 'locked-value' === get_post_meta( $post_id, $locked_key, true ), 'Slashed alias reached provider-locked metadata.' );
	$assert( is_wp_error( $locked_alias_update ) || 'must-not-reach-locked-key' === get_post_meta( $post_id, $locked_alias, true ), 'Slashed provider alias did not remain isolated to its exact key.' );

	$sensitive_alias_update = $meta_update->execute(
		array(
			'post_id'             => $post_id,
			'key'                 => $sensitive_alias,
			'value_json'          => wp_json_encode( 'must-not-reach-secret' ),
			'expected_state_hash' => $empty_state_hash,
		)
	);
	$assert( is_wp_error( $sensitive_alias_update ), 'Backslash-obfuscated credential key was not denied.' );
	$assert( 'secret-untouched' === get_post_meta( $post_id, $sensitive_key, true ), 'Backslash-obfuscated key reached the credential key.' );

	/* F-004: provider-neutral secret matching must cover common naming styles. */
	$secret_variants = array(
		'clientSecret',
		'clientsecret',
		'accessToken',
		'refreshToken',
		'privateKey',
		'applicationPassword',
		'consumerSecret',
		'bearerToken',
		'authToken',
		'oauthToken',
		'client-secret',
		'client.secret',
		'client:secret',
	);
	foreach ( $secret_variants as $variant ) {
		add_post_meta( $post_id, wp_slash( $variant ), 'secret-fixture', true );
		$secret_read = $meta_read->execute(
			array(
				'post_id'        => $post_id,
				'key'            => $variant,
				'include_values' => true,
			)
		);
		$assert( is_wp_error( $secret_read ), 'Credential-like metadata key was readable: ' . $variant );
	}
	$list = $meta_read->execute( array( 'post_id' => $post_id ) );
	$assert( ! is_wp_error( $list ), 'Metadata key discovery failed during credential filtering test.' );
	if ( ! is_wp_error( $list ) ) {
		$listed_keys = array_column( $list['items'], 'key' );
		foreach ( $secret_variants as $variant ) {
			$assert( ! in_array( $variant, $listed_keys, true ), 'Credential-like key leaked through broad discovery: ' . $variant );
		}
	}

	/* F-002: revision IDs must canonicalize to the parent before every meta decision. */
	$revision_locked_key = '_wpnb_revision_locked';
	register_post_meta(
		'wpnb_meta_security',
		$revision_locked_key,
		array(
			'type'          => 'string',
			'single'        => true,
			'auth_callback' => '__return_false',
		)
	);
	$registered[] = $revision_locked_key;
	add_post_meta( $post_id, $revision_locked_key, 'parent-locked', true );
	$revision_id = wp_insert_post(
		array(
			'post_type'   => 'revision',
			'post_parent' => $post_id,
			'post_status' => 'inherit',
			'post_title'  => 'Issue 34 revision fixture',
		),
		true
	);
	$assert( ! is_wp_error( $revision_id ) && $revision_id > 0 && wp_is_post_revision( $revision_id ), 'Revision fixture could not be created.' );
	if ( ! is_wp_error( $revision_id ) && $revision_id > 0 ) {
		$revision_read = $meta_read->execute(
			array(
				'post_id' => $revision_id,
				'key'     => $revision_locked_key,
			)
		);
		$assert( is_wp_error( $revision_read ), 'Revision alias bypassed parent subtype metadata authorization on read.' );

		$revision_update = $meta_update->execute(
			array(
				'post_id'             => $revision_id,
				'key'                 => $revision_locked_key,
				'value_json'          => wp_json_encode( 'revision-bypass' ),
				'expected_state_hash' => $empty_state_hash,
			)
		);
		$assert( is_wp_error( $revision_update ), 'Revision alias bypassed parent subtype metadata authorization on update.' );
		$assert( 'parent-locked' === get_post_meta( $post_id, $revision_locked_key, true ), 'Revision alias changed parent protected metadata.' );
	}

	/* F-003: physical storage identity must not be confused with registered defaults. */
	$edit_only_key = '_wpnb_default_edit_only';
	register_post_meta(
		'wpnb_meta_security',
		$edit_only_key,
		array(
			'type'          => 'string',
			'single'        => true,
			'default'       => 'registered-default',
			'auth_callback' => static function ( $allowed, $meta_key, $object_id, $user_id, $cap ) {
				return 'edit_post_meta' === $cap;
			},
		)
	);
	$registered[] = $edit_only_key;
	$assert( false === metadata_exists( 'post', $post_id, $edit_only_key ), 'Edit-only default fixture unexpectedly has a physical row.' );
	$assert( 'registered-default' === get_post_meta( $post_id, $edit_only_key, true ), 'Registered default fixture is not returning its effective default.' );
	$edit_only_update = $meta_update->execute(
		array(
			'post_id'             => $post_id,
			'key'                 => $edit_only_key,
			'value_json'          => wp_json_encode( 'should-not-add' ),
			'expected_state_hash' => $empty_state_hash,
		)
	);
	$assert( is_wp_error( $edit_only_update ), 'Edit-only registered default authorized an actual add.' );
	$assert( false === metadata_exists( 'post', $post_id, $edit_only_key ), 'Edit-only registered default created a physical row.' );

	$add_only_key = '_wpnb_default_add_only';
	register_post_meta(
		'wpnb_meta_security',
		$add_only_key,
		array(
			'type'          => 'string',
			'single'        => true,
			'default'       => 'registered-default',
			'auth_callback' => static function ( $allowed, $meta_key, $object_id, $user_id, $cap ) {
				return 'add_post_meta' === $cap;
			},
		)
	);
	$registered[] = $add_only_key;
	$add_only_read = $meta_read->execute(
		array(
			'post_id' => $post_id,
			'key'     => $add_only_key,
		)
	);
	$assert( ! is_wp_error( $add_only_read ) && 0 === $add_only_read['items'][0]['count'], 'Absent add-only metadata did not expose its physical empty state.' );
	if ( ! is_wp_error( $add_only_read ) ) {
		$add_only_update = $meta_update->execute(
			array(
				'post_id'             => $post_id,
				'key'                 => $add_only_key,
				'value_json'          => wp_json_encode( 'created' ),
				'expected_state_hash' => $add_only_read['items'][0]['state_hash'],
			)
		);
		$assert( ! is_wp_error( $add_only_update ), 'Add-only registered metadata could not create its first physical row.' );
		$assert( 'created' === get_post_meta( $post_id, $add_only_key, true ), 'Add-only registered metadata did not persist the created row.' );
		$second_update = $meta_update->execute(
			array(
				'post_id'             => $post_id,
				'key'                 => $add_only_key,
				'value_json'          => wp_json_encode( 'must-not-edit' ),
				'expected_state_hash' => $add_only_update['state_hash'],
			)
		);
		$assert( is_wp_error( $second_update ), 'Add-only registered metadata authorized an edit of the stored row.' );
		$assert( 'created' === get_post_meta( $post_id, $add_only_key, true ), 'Denied edit changed add-only metadata.' );
	}

	$identity_key = '_wpnb_default_identity';
	register_post_meta(
		'wpnb_meta_security',
		$identity_key,
		array(
			'type'          => 'string',
			'single'        => true,
			'default'       => 'registered-default',
			'auth_callback' => '__return_true',
		)
	);
	$registered[] = $identity_key;
	$identity_before = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $identity_key ) );
	$assert( ! is_wp_error( $identity_before ) && 0 === $identity_before['items'][0]['count'], 'Registered default was mistaken for a physical row.' );
	if ( ! is_wp_error( $identity_before ) ) {
		$identity_write = $meta_update->execute(
			array(
				'post_id'             => $post_id,
				'key'                 => $identity_key,
				'value_json'          => wp_json_encode( 'registered-default' ),
				'expected_state_hash' => $identity_before['items'][0]['state_hash'],
			)
		);
		$assert( ! is_wp_error( $identity_write ), 'Registered-default-equivalent physical row could not be created.' );
		$assert( 1 === $identity_write['count'], 'Stored registered-default-equivalent row was not identified as physical state.' );
		$assert( $identity_before['items'][0]['state_hash'] !== $identity_write['state_hash'], 'Absent default and stored default produced the same mutation identity.' );
	}

	/* F-007: final map_meta_cap requirements must remain authoritative. */
	$map_key = '_wpnb_map_locked';
	add_post_meta( $post_id, $map_key, 'map-value', true );
	$extra_cap_filter = static function ( $caps, $cap, $user_id, $args ) use ( $post_id, $map_key ) {
		if ( 'edit_post_meta' === $cap && isset( $args[0], $args[1] ) && (int) $args[0] === (int) $post_id && (string) $args[1] === $map_key ) {
			$caps[] = 'wpnb_issue34_extra_cap';
		}
		return $caps;
	};
	$add_filter_guard( 'map_meta_cap', $extra_cap_filter, 99, 4 );
	$map_denied = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $map_key ) );
	$assert( is_wp_error( $map_denied ), 'Additional map_meta_cap primitive requirement was ignored.' );
	remove_filter( 'map_meta_cap', $extra_cap_filter, 99 );

	$do_not_allow_filter = static function ( $caps, $cap, $user_id, $args ) use ( $post_id, $map_key ) {
		if ( 'edit_post_meta' === $cap && isset( $args[0], $args[1] ) && (int) $args[0] === (int) $post_id && (string) $args[1] === $map_key ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	};
	$add_filter_guard( 'map_meta_cap', $do_not_allow_filter, 99, 4 );
	$map_hard_denied = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $map_key ) );
	$assert( is_wp_error( $map_hard_denied ), 'do_not_allow injected through map_meta_cap was ignored.' );
	remove_filter( 'map_meta_cap', $do_not_allow_filter, 99 );

	/* F-005: nested PHP objects must fail closed instead of round-tripping to arrays. */
	$nested_key   = '_wpnb_nested_object';
	$nested_value = array( 'outer' => array( 'object' => (object) array( 'secret_state' => 'preserve-me' ) ) );
	add_post_meta( $post_id, $nested_key, $nested_value, true );
	$nested_read = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $nested_key ) );
	$assert( ! is_wp_error( $nested_read ), 'Nested-object metadata state could not be inspected without values.' );
	if ( ! is_wp_error( $nested_read ) ) {
		$nested_update = $meta_update->execute(
			array(
				'post_id'             => $post_id,
				'key'                 => $nested_key,
				'value_json'          => wp_json_encode( array( 'outer' => array( 'object' => array( 'replacement' => true ) ) ) ),
				'expected_state_hash' => $nested_read['items'][0]['state_hash'],
			)
		);
		$assert( is_wp_error( $nested_update ), 'Nested PHP object metadata was accepted for lossy replacement.' );
		$stored_nested = get_post_meta( $post_id, $nested_key, true );
		$assert( isset( $stored_nested['outer']['object'] ) && is_object( $stored_nested['outer']['object'] ), 'Nested PHP object metadata was corrupted.' );
		$assert( 'preserve-me' === $stored_nested['outer']['object']->secret_state, 'Nested PHP object state changed after denied mutation.' );
	}

	/* F-006: values Core cannot condition atomically must fail closed. */
	$empty_value_key = '_wpnb_empty_atomic';
	add_post_meta( $post_id, $empty_value_key, '', true );
	$empty_read = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $empty_value_key ) );
	$assert( ! is_wp_error( $empty_read ), 'Empty-value metadata state could not be read.' );
	if ( ! is_wp_error( $empty_read ) ) {
		$empty_update = $meta_update->execute(
			array(
				'post_id'             => $post_id,
				'key'                 => $empty_value_key,
				'value_json'          => wp_json_encode( 'replacement' ),
				'expected_state_hash' => $empty_read['items'][0]['state_hash'],
			)
		);
		$assert( is_wp_error( $empty_update ) && 'post_meta_atomic_mutation_unsupported' === $empty_update->get_error_code(), 'Unconditionable empty-value update did not fail closed.' );
		$empty_delete = $meta_delete->execute(
			array(
				'post_id'             => $post_id,
				'key'                 => $empty_value_key,
				'expected_state_hash' => $empty_read['items'][0]['state_hash'],
			)
		);
		$assert( is_wp_error( $empty_delete ) && 'post_meta_atomic_mutation_unsupported' === $empty_delete->get_error_code(), 'Unconditionable empty-value delete did not fail closed.' );
		$assert( metadata_exists( 'post', $post_id, $empty_value_key ), 'Fail-closed atomic guard deleted the empty-value row.' );
	}

	/* F-006: deterministic races must return stale conflict without clobbering concurrent state. */
	$race_key = '_wpnb_race_value';
	add_post_meta( $post_id, $race_key, 'race-old', true );
	$race_read = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $race_key ) );
	$race_filter = null;
	$race_filter = static function ( $check, $object_id, $meta_key ) use ( &$race_filter, $post_id, $race_key ) {
		if ( (int) $object_id === (int) $post_id && (string) $meta_key === $race_key ) {
			remove_filter( 'update_post_metadata', $race_filter, 1 );
			update_post_meta( $post_id, $race_key, 'race-concurrent', 'race-old' );
		}
		return $check;
	};
	$add_filter_guard( 'update_post_metadata', $race_filter, 1, 5 );
	$race_update = $meta_update->execute(
		array(
			'post_id'             => $post_id,
			'key'                 => $race_key,
			'value_json'          => wp_json_encode( 'race-bridge' ),
			'expected_state_hash' => $race_read['items'][0]['state_hash'],
		)
	);
	$assert( is_wp_error( $race_update ) && 'stale_post_meta_conflict' === $race_update->get_error_code(), 'Concurrent value change was not reported as a stale conflict.' );
	$assert( 'race-concurrent' === get_post_meta( $post_id, $race_key, true ), 'Bridge overwrote the concurrent value change.' );

	$race_rows_key = '_wpnb_race_rows';
	add_post_meta( $post_id, $race_rows_key, 'row-old', true );
	$race_rows_read = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $race_rows_key ) );
	$race_rows_filter = null;
	$race_rows_filter = static function ( $check, $object_id, $meta_key ) use ( &$race_rows_filter, $post_id, $race_rows_key ) {
		if ( (int) $object_id === (int) $post_id && (string) $meta_key === $race_rows_key ) {
			global $wpdb;
			remove_filter( 'update_post_metadata', $race_rows_filter, 1 );
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $post_id,
					'meta_key'   => $race_rows_key,
					'meta_value' => 'row-concurrent',
				),
				array( '%d', '%s', '%s' )
			);
			wp_cache_delete( $post_id, 'post_meta' );
		}
		return $check;
	};
	$add_filter_guard( 'update_post_metadata', $race_rows_filter, 1, 5 );
	$race_rows_update = $meta_update->execute(
		array(
			'post_id'             => $post_id,
			'key'                 => $race_rows_key,
			'value_json'          => wp_json_encode( 'row-bridge' ),
			'expected_state_hash' => $race_rows_read['items'][0]['state_hash'],
		)
	);
	$assert( is_wp_error( $race_rows_update ) && 'stale_post_meta_conflict' === $race_rows_update->get_error_code(), 'Concurrent second row was not reported as a stale conflict.' );
	$rows_after = get_post_meta( $post_id, $race_rows_key, false );
	$assert( 2 === count( $rows_after ), 'Concurrent second row was collapsed by the Bridge update.' );
	$assert( in_array( 'row-concurrent', $rows_after, true ), 'Concurrent second row was lost.' );

	$delete_race_key = '_wpnb_delete_race';
	add_post_meta( $post_id, $delete_race_key, 'delete-old', true );
	$delete_race_read = $meta_read->execute( array( 'post_id' => $post_id, 'key' => $delete_race_key ) );
	$delete_race_filter = null;
	$delete_race_filter = static function ( $check, $object_id, $meta_key ) use ( &$delete_race_filter, $post_id, $delete_race_key ) {
		if ( (int) $object_id === (int) $post_id && (string) $meta_key === $delete_race_key ) {
			remove_filter( 'delete_post_metadata', $delete_race_filter, 1 );
			add_post_meta( $post_id, $delete_race_key, 'delete-concurrent', false );
		}
		return $check;
	};
	$add_filter_guard( 'delete_post_metadata', $delete_race_filter, 1, 5 );
	$delete_race = $meta_delete->execute(
		array(
			'post_id'             => $post_id,
			'key'                 => $delete_race_key,
			'expected_state_hash' => $delete_race_read['items'][0]['state_hash'],
		)
	);
	$assert( is_wp_error( $delete_race ) && 'stale_post_meta_conflict' === $delete_race->get_error_code(), 'Concurrent delete race was not reported as a stale conflict.' );
	$assert( array( 'delete-concurrent' ) === array_values( get_post_meta( $post_id, $delete_race_key, false ) ), 'Conditional delete removed concurrent metadata state.' );
} catch ( Throwable $exception ) {
	$failures[] = 'Unexpected Issue #34 security test exception: ' . $exception->getMessage();
} finally {
	foreach ( array_reverse( $filters ) as $filter ) {
		remove_filter( $filter[0], $filter[1], $filter[2] );
	}
	foreach ( array_unique( $registered ) as $meta_key ) {
		unregister_post_meta( 'wpnb_meta_security', $meta_key );
	}
	if ( is_int( $revision_id ) && $revision_id > 0 ) {
		wp_delete_post( $revision_id, true );
	}
	if ( is_int( $post_id ) && $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( post_type_exists( 'wpnb_meta_security' ) ) {
		unregister_post_type( 'wpnb_meta_security' );
	}
	if ( class_exists( $settings_class ) ) {
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

echo 'PASS: Issue #34 post metadata security semantics.' . PHP_EOL;
