<?php
/**
 * Physical term metadata storage helpers for exact-row mutations.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Support;

use WP_Error;

/**
 * Keeps Advanced Metadata mutations bound to one physical termmeta row.
 *
 * This class is intentionally not a generic database abstraction. It only operates on
 * fixed wp_termmeta columns after the Ability layer has authorized one exact term/taxonomy/key.
 */
final class Term_Meta_Store {
	const MAX_VALUE_BYTES = 1048576;
	/**
	 * Returns at most two rows: enough to distinguish absence, singleton, and ambiguity.
	 * A bounded CASE prevents an oversized stored value from being loaded into PHP.
	 *
	 * @param int    $term_id Canonical term ID.
	 * @param string $key     Exact key.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function rows( $term_id, $key ) {
		global $wpdb;
		if ( (int) $term_id < 1 || ! is_string( $key ) || '' === $key ) {
			return $this->state_error();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed, uncached physical termmeta identity; at most two bounded rows.
		$metadata = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_id, term_id, meta_key, CASE WHEN OCTET_LENGTH(meta_value) <= 1048576 THEN meta_value ELSE NULL END AS meta_value, OCTET_LENGTH(meta_value) AS value_bytes FROM %i WHERE term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) ORDER BY meta_id LIMIT 2',
				$wpdb->termmeta,
				(int) $term_id,
				$key
			),
			ARRAY_A
		);
		if ( ! is_array( $metadata ) || '' !== $wpdb->last_error ) {
			return $this->state_error();
		}
		$rows = array();
		foreach ( $metadata as $meta ) {
			if ( ! isset( $meta['meta_id'], $meta['term_id'], $meta['meta_key'] ) || ! array_key_exists( 'meta_value', $meta ) || ! array_key_exists( 'value_bytes', $meta )
				|| (int) $meta['term_id'] !== (int) $term_id || $meta['meta_key'] !== $key ) {
				return $this->state_error();
			}
			if ( (int) $meta['value_bytes'] > self::MAX_VALUE_BYTES ) {
				return new WP_Error( 'term_meta_value_too_large', __( 'Term metadata values are limited to 1 MiB per key.', 'wp-native-builder-bridge' ) );
			}
			$raw   = null === $meta['meta_value'] ? null : (string) $meta['meta_value'];
			$value = $raw;
			$loss  = false;
			if ( is_serialized( $raw ) ) {
				$offset = 0;
				// allowed_classes=false does not prevent enum autoloading. Prove that the
				// complete stream contains only scalar/array tokens before native decoding.
				if ( ! $this->safe_serialized_fragment( $raw, $offset ) || strlen( $raw ) !== $offset ) {
					$value = null;
					$loss  = true;
				} else {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Complete primitive-only stream preflight; classes disabled and depth limited as defense in depth.
					$value = @unserialize(
						$raw,
						array(
							'allowed_classes' => false,
							'max_depth'       => 64,
						)
					);
					$loss  = serialize( $value ) !== $raw;
				}
			}
			$rows[] = array(
				'meta_id'            => (int) $meta['meta_id'],
				'term_id'            => (int) $meta['term_id'],
				'key'                => $key,
				'raw_value'          => $raw,
				'value'              => $value,
				'serialization_loss' => $loss,
			);
		}
		return $rows;
	}

	/**
	 * Preflights scalar/array serialization without instantiating application classes.
	 * String bodies are skipped by byte length. Object, enum and reference tokens
	 * are refused at every depth. Native decoding then checks canonical bytes.
	 *
	 * @param string $raw    Bounded serialized bytes.
	 * @param int    $offset Current byte offset.
	 * @param int    $depth  Array nesting depth.
	 * @return bool
	 */
	private function safe_serialized_fragment( $raw, &$offset, $depth = 0 ) {
		if ( $depth > 64 || $offset >= strlen( $raw ) ) {
			return false;
		}
		if ( 'N;' === substr( $raw, $offset, 2 ) ) {
			$offset += 2;
			return true;
		}
		if ( preg_match( '/\G(?:b:[01]|i:-?[0-9]+|d:[+-]?[0-9]+(?:\.[0-9]*)?(?:[Ee][+-]?[0-9]+)?);/', $raw, $match, 0, $offset ) ) {
			$offset += strlen( $match[0] );
			return true;
		}
		if ( preg_match( '/\Gs:([0-9]+):"/', $raw, $match, 0, $offset ) ) {
			$offset += strlen( $match[0] );
			$length  = (int) $match[1];
			if ( $length > strlen( $raw ) - $offset - 2 || '";' !== substr( $raw, $offset + $length, 2 ) ) {
				return false;
			}
			$offset += $length + 2;
			return true;
		}
		if ( ! preg_match( '/\Ga:([0-9]+):\{/', $raw, $match, 0, $offset ) ) {
			return false;
		}
		$offset += strlen( $match[0] );
		$count   = (int) $match[1];
		if ( $count > intdiv( strlen( $raw ) - $offset, 2 ) ) {
			return false;
		}
		for ( $index = 0; $index < $count; ++$index ) {
			if ( ! in_array( $raw[ $offset ] ?? '', array( 'i', 's' ), true )
				|| ! $this->safe_serialized_fragment( $raw, $offset, $depth + 1 )
				|| ! $this->safe_serialized_fragment( $raw, $offset, $depth + 1 ) ) {
				return false;
			}
		}
		if ( '}' !== ( $raw[ $offset ] ?? '' ) ) {
			return false;
		}
		++$offset;
		return true;
	}

	/**
	 * Lists physical key/count summaries only; no values are loaded or hashed.
	 *
	 * @param int $term_id Canonical term ID.
	 * @param int $limit   Page size plus one look-ahead row, at most 101.
	 * @param int $offset  Bounded physical-key offset.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function list_keys( $term_id, $limit, $offset ) {
		global $wpdb;
		if ( (int) $term_id < 1 ) {
			return $this->state_error();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-schema bounded key summaries; never retrieves metadata values.
		$keys = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT MIN(meta_key) AS meta_key, COUNT(*) AS row_count FROM %i WHERE term_id = %d AND meta_key IS NOT NULL GROUP BY CAST(meta_key AS BINARY) ORDER BY CAST(meta_key AS BINARY) LIMIT %d OFFSET %d',
				$wpdb->termmeta,
				(int) $term_id,
				max( 1, min( 101, (int) $limit ) ),
				max( 0, min( 999900, (int) $offset ) )
			),
			ARRAY_A
		);
		return is_array( $keys ) && '' === $wpdb->last_error ? $keys : $this->state_error();
	}

	/** @return WP_Error */
	private function state_error() {
		return new WP_Error( 'term_meta_physical_state_unavailable', __( 'Physical term metadata state could not be established safely.', 'wp-native-builder-bridge' ) );
	}

	/**
	 * Prepares a JSON-decoded value exactly as WordPress stores term metadata.
	 *
	 * Existing-row direct persistence needs to run the same sanitizer Core would run.
	 * Creation does not call this method because add_term_meta() owns its one sanitizer pass.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Exact unslashed key.
	 * @param mixed  $value   Canonical unslashed value.
	 * @param callable $guard Internal lossless-value guard.
	 * @return array{value:mixed,raw_value:string|null}|WP_Error
	 */
	public function prepare_value( $term_id, $key, $value, callable $guard ) {
		$subtype = function_exists( 'get_object_subtype' ) ? get_object_subtype( 'term', (int) $term_id ) : '';
		if ( function_exists( 'sanitize_meta' ) ) {
			$value = sanitize_meta( (string) $key, $value, 'term', $subtype );
		}

		if ( ! $guard( $value ) ) {
			return new WP_Error( 'term_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
		}
		return $this->stored_value( $value );
	}

	/**
	 * Creates through Core with one sanitizer pass and observes the original invocation.
	 * Non-null provider short circuits are never evidence of physical row ownership.
	 *
	 * @param int      $term_id Term ID.
	 * @param string   $key     Exact unslashed key.
	 * @param mixed    $value   JSON-decoded value.
	 * @param callable $guard   Internal target/authorization/value guard, not client input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create_unique_row( $term_id, $key, $value, callable $guard ) {
		global $wp_current_filter;
		$term_id    = (int) $term_id;
		$key        = (string) $key;
		$base_depth = count( (array) $wp_current_filter );
		$captured   = false;
		$continued  = false;
		$sanitized  = null;
		$added_id   = 0;
		$refused    = false;
		$capture    = function ( $check, $object_id, $meta_key, $meta_value, $unique ) use ( $term_id, $key, $base_depth, $guard, &$captured, &$continued, &$sanitized, &$refused ) {
			global $wp_current_filter;
			if ( count( (array) $wp_current_filter ) !== $base_depth + 1 || (int) $object_id !== $term_id || $meta_key !== $key || ! $unique ) {
				return $check;
			}
			$captured  = true;
			$sanitized = $meta_value;
			if ( null !== $check ) {
				return $check;
			}
			if ( ! $guard( $meta_value ) ) {
				$refused = true;
				return false;
			}
			$prepared = $this->stored_value( $meta_value );
			if ( strlen( (string) $prepared['raw_value'] ) > self::MAX_VALUE_BYTES ) {
				$refused = true;
				return false;
			}
			$continued = true;
			return $check;
		};
		$observe    = static function ( $meta_id, $object_id, $meta_key ) use ( $term_id, $key, $base_depth, &$added_id ) {
			global $wp_current_filter;
			if ( count( (array) $wp_current_filter ) === $base_depth + 1 && (int) $object_id === $term_id && $meta_key === $key ) {
				$added_id = (int) $meta_id;
			}
		};
		add_filter( 'add_term_metadata', $capture, PHP_INT_MAX, 5 );
		add_action( 'added_term_meta', $observe, PHP_INT_MIN, 4 );
		try {
			$result = add_term_meta( $term_id, wp_slash( $key ), wp_slash( $value ), true );
		} finally {
			remove_filter( 'add_term_metadata', $capture, PHP_INT_MAX );
			remove_action( 'added_term_meta', $observe, PHP_INT_MIN );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $refused ) {
			return new WP_Error( 'term_meta_create_refused', __( 'The sanitized term metadata value or current target authority does not permit safe creation.', 'wp-native-builder-bridge' ) );
		}
		if ( ! $captured ) {
			return $this->state_error();
		}
		if ( ! $continued ) {
			return new WP_Error( 'term_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-native-builder-bridge' ) );
		}
		$prepared = $this->stored_value( $sanitized );
		return array(
			'result'       => $result,
			'owned'        => $continued && is_int( $result ) && $result > 0 && $added_id === $result,
			'expected_row' => array(
				'meta_id'   => is_int( $result ) ? $result : 0,
				'term_id'   => $term_id,
				'key'       => $key,
				'raw_value' => $prepared['raw_value'],
				'value'     => $prepared['value'],
			),
		);
	}

	/**
	 * Replaces one exact physical row with byte-exact compare-and-swap semantics.
	 *
	 * Verification and any compensation stay inside this persistence boundary so a
	 * later unrelated write after the verified linearization point is simply a new state.
	 *
	 * @param int                 $term_id  Term ID.
	 * @param string              $key      Exact unslashed key.
	 * @param array<string,mixed> $row      Previously inspected physical row.
	 * @param array<string,mixed> $prepared Sanitized value and exact raw storage representation.
	 * @return array<string,mixed>|WP_Error Verified physical row after the update.
	 */
	public function replace_row( $term_id, $key, array $row, array $prepared, callable $guard ) {
		$current = $this->rows( $term_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' ) );
		}

		if ( ! $guard() ) {
			return new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-native-builder-bridge' ) );
		}
		if ( $row['raw_value'] === $prepared['raw_value'] ) {
			return $current[0];
		}

		$short_circuit = apply_filters( 'update_term_metadata', null, (int) $term_id, (string) $key, $prepared['value'], $row['value'] );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'term_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-native-builder-bridge' ) );
		}

		$meta_id = (int) $row['meta_id'];
		do_action( 'update_term_meta', $meta_id, (int) $term_id, (string) $key, $prepared['value'] );

		if ( ! $guard() ) {
			return new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-native-builder-bridge' ) );
		}

		$result = $this->exact_update_raw_row( $meta_id, (int) $term_id, (string) $key, $row['raw_value'], $prepared['raw_value'] );
		if ( false === $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'term_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-native-builder-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' ) );
		}

		wp_cache_delete( (int) $term_id, 'term_meta' );
		do_action( 'updated_term_meta', $meta_id, (int) $term_id, (string) $key, $prepared['value'] );

		$after = $this->rows( $term_id, $key );
		if ( is_wp_error( $after ) ) {
			if ( ! $this->restore_updated_row( $row, $prepared['raw_value'] ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-native-builder-bridge' ) );
			}
			return $after;
		}
		$expected_row              = $row;
		$expected_row['raw_value'] = $prepared['raw_value'];
		if ( 1 !== count( $after ) || ! $this->row_matches( $after[0], $expected_row ) ) {
			if ( ! $this->restore_updated_row( $row, $prepared['raw_value'] ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-native-builder-bridge' ) );
			}
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' ) );
		}

		return $after[0];
	}

	/**
	 * Deletes one exact physical row with byte-exact compare-and-swap semantics.
	 *
	 * Verification and restoration stay inside the same persistence boundary.
	 *
	 * @param int                 $term_id Term ID.
	 * @param string              $key     Exact unslashed key.
	 * @param array<string,mixed> $row     Previously inspected physical row.
	 * @return true|WP_Error
	 */
	public function delete_row( $term_id, $key, array $row, callable $guard ) {
		$current = $this->rows( $term_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-native-builder-bridge' ) );
		}

		$short_circuit = apply_filters( 'delete_term_metadata', null, (int) $term_id, (string) $key, $row['value'], false );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'term_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-native-builder-bridge' ) );
		}

		$meta_id  = (int) $row['meta_id'];
		$meta_ids = array( $meta_id );
		do_action( 'delete_term_meta', $meta_ids, (int) $term_id, (string) $key, $row['value'] );

		if ( ! $guard() ) {
			return new WP_Error( 'term_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-native-builder-bridge' ) );
		}

		$result = $this->exact_delete_raw_row( $meta_id, (int) $term_id, (string) $key, $row['raw_value'] );
		if ( false === $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'term_meta_delete_failed', __( 'WordPress could not delete the requested metadata key.', 'wp-native-builder-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $term_id, 'term_meta' );
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-native-builder-bridge' ) );
		}

		wp_cache_delete( (int) $term_id, 'term_meta' );
		do_action( 'deleted_term_meta', $meta_ids, (int) $term_id, (string) $key, $row['value'] );

		$after = $this->rows( $term_id, $key );
		if ( is_wp_error( $after ) ) {
			if ( ! $this->restore_deleted_row( $row ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-native-builder-bridge' ) );
			}
			return $after;
		}
		if ( ! empty( $after ) ) {
			if ( ! $this->restore_deleted_row( $row ) ) {
				return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-native-builder-bridge' ) );
			}
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-native-builder-bridge' ) );
		}

		return true;
	}

	/**
	 * Restores one deleted row and emits the matching add lifecycle when compensation succeeds.
	 *
	 * @param array<string,mixed> $row Previously inspected physical row.
	 * @return bool
	 */
	public function restore_deleted_row( array $row ) {
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}
		global $wpdb;
		do_action( 'add_term_meta', (int) $row['term_id'], (string) $row['key'], $row['value'] );
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}
		$result = $wpdb->insert(
			$wpdb->termmeta,
			array(
				'meta_id'    => (int) $row['meta_id'],
				'term_id'    => (int) $row['term_id'],
				'meta_key'   => (string) $row['key'],
				'meta_value' => $row['raw_value'],
			),
			array( '%d', '%d', '%s', '%s' )
		);
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return false;
		}
		wp_cache_delete( (int) $row['term_id'], 'term_meta' );
		do_action( 'added_term_meta', (int) $row['meta_id'], (int) $row['term_id'], (string) $row['key'], $row['value'] );
		return true;
	}

	/**
	 * Rolls back only the row changed by this invocation and emits an update lifecycle
	 * that reflects the restored final value. Short-circuit filters do not veto integrity
	 * compensation after the Bridge has already committed its first exact mutation.
	 *
	 * @param array<string,mixed> $row              Original physical row.
	 * @param string|null         $expected_new_raw Raw value written by this invocation.
	 * @return bool
	 */
	public function restore_updated_row( array $row, $expected_new_raw ) {
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}
		$meta_id = (int) $row['meta_id'];
		do_action( 'update_term_meta', $meta_id, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		if ( ! $this->target_matches( $row ) ) {
			return false;
		}

		$result = $this->exact_update_raw_row( $meta_id, (int) $row['term_id'], (string) $row['key'], $expected_new_raw, $row['raw_value'] );
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return false;
		}
		wp_cache_delete( (int) $row['term_id'], 'term_meta' );
		do_action( 'updated_term_meta', $meta_id, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		return true;
	}

	/**
	 * Removes only an unchanged row created by this invocation during create-race cleanup.
	 *
	 * @param array<string,mixed> $row Expected physical row created by this invocation.
	 * @return true|WP_Error
	 */
	public function cleanup_created_row( array $row ) {
		if ( ! $this->target_matches( $row, true ) ) {
			return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-native-builder-bridge' ) );
		}
		$meta_id  = (int) $row['meta_id'];
		$meta_ids = array( $meta_id );
		do_action( 'delete_term_meta', $meta_ids, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		if ( ! $this->target_matches( $row, true ) ) {
			return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-native-builder-bridge' ) );
		}

		$result = $this->exact_delete_raw_row( $meta_id, (int) $row['term_id'], (string) $row['key'], $row['raw_value'] );
		if ( false === $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return new WP_Error( 'term_meta_compensation_failed', __( 'Concurrent metadata changed during mutation and the Bridge could not restore its exact physical row safely.', 'wp-native-builder-bridge' ) );
		}
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $row['term_id'], 'term_meta' );
			return new WP_Error( 'stale_term_meta_conflict', __( 'Term metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' ) );
		}

		wp_cache_delete( (int) $row['term_id'], 'term_meta' );
		do_action( 'deleted_term_meta', $meta_ids, (int) $row['term_id'], (string) $row['key'], $row['value'] );
		return true;
	}

	/**
	 * Revalidates the original taxonomy and term-taxonomy row before compensation.
	 * Only cleanup of this invocation's unchanged newly-created orphan may proceed
	 * after the term disappears; restoration never recreates an orphan. A term ID
	 * reused or moved to another taxonomy is not the original authorized target.
	 *
	 * @param array<string,mixed> $row           Owned row plus internal target snapshot.
	 * @param bool                $allow_missing Permit removal of an owned create orphan.
	 * @return bool
	 */
	private function target_matches( array $row, $allow_missing = false ) {
		if ( ! isset( $row['target_taxonomy'], $row['target_term_taxonomy_id'] )
			|| ! is_string( $row['target_taxonomy'] ) || '' === $row['target_taxonomy']
			|| ! is_int( $row['target_term_taxonomy_id'] ) || $row['target_term_taxonomy_id'] < 1 ) {
			return false;
		}
		$term_id = (int) $row['term_id'];
		$term    = get_term( $term_id );
		if ( ! $term ) {
			return $allow_missing;
		}
		return ! is_wp_error( $term ) && ! wp_term_is_shared( $term_id )
			&& $term_id === (int) $term->term_id
			&& $row['target_taxonomy'] === $term->taxonomy
			&& $row['target_term_taxonomy_id'] === (int) $term->term_taxonomy_id
			&& get_object_subtype( 'term', $term_id ) === $row['target_taxonomy'];
	}

	/**
	 * Checks whether two physical row snapshots are identical.
	 *
	 * @param array<string,mixed> $left  Row.
	 * @param array<string,mixed> $right Row.
	 * @return bool
	 */
	public function row_matches( array $left, array $right ) {
		return array_key_exists( 'raw_value', $left )
			&& array_key_exists( 'raw_value', $right )
			&& (int) $left['meta_id'] === (int) $right['meta_id']
			&& (int) $left['term_id'] === (int) $right['term_id']
			&& (string) $left['key'] === (string) $right['key']
			&& $left['raw_value'] === $right['raw_value'];
	}

	/**
	 * Converts an already-sanitized WordPress metadata value to its exact DB representation.
	 *
	 * @param mixed $value Sanitized metadata value.
	 * @return array{value:mixed,raw_value:string|null}
	 */
	private function stored_value( $value ) {
		$raw_value = maybe_serialize( $value );
		if ( null === $raw_value ) {
			return array(
				'value'     => $value,
				'raw_value' => null,
			);
		}
		if ( false === $raw_value ) {
			$raw_value = '';
		} elseif ( true === $raw_value ) {
			$raw_value = '1';
		} elseif ( ! is_string( $raw_value ) ) {
			$raw_value = (string) $raw_value;
		}

		return array(
			'value'     => $value,
			'raw_value' => $raw_value,
		);
	}

	/**
	 * Performs one fixed-schema byte-exact row update.
	 *
	 * Every branch contains a complete literal query template so neither the caller nor
	 * an internal string fragment can select SQL structure. SQL NULL is represented by
	 * the two branches that use SET ... NULL or ... IS NULL rather than a text value.
	 *
	 * @param int         $meta_id      Physical meta ID.
	 * @param int         $term_id      Term ID.
	 * @param string      $key          Exact key.
	 * @param string|null $expected_raw Expected old raw storage.
	 * @param string|null $new_raw      New raw storage.
	 * @return int|false
	 */
	private function exact_update_raw_row( $meta_id, $term_id, $key, $expected_raw, $new_raw ) {
		global $wpdb;

		if ( null === $expected_raw && null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_termmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_value = NULL WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
					$wpdb->termmeta,
					(int) $meta_id,
					(int) $term_id,
					(string) $key
				)
			);
		}

		if ( null === $expected_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_termmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
					$wpdb->termmeta,
					$new_raw,
					(int) $meta_id,
					(int) $term_id,
					(string) $key
				)
			);
		}

		if ( null === $new_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_termmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_value = NULL WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
					$wpdb->termmeta,
					(int) $meta_id,
					(int) $term_id,
					(string) $key,
					$expected_raw
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_termmeta CAS.
		return $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET meta_value = %s WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
				$wpdb->termmeta,
				$new_raw,
				(int) $meta_id,
				(int) $term_id,
				(string) $key,
				$expected_raw
			)
		);
	}

	/**
	 * Performs one fixed-schema byte-exact row delete.
	 *
	 * @param int         $meta_id      Physical meta ID.
	 * @param int         $term_id      Term ID.
	 * @param string      $key          Exact key.
	 * @param string|null $expected_raw Expected raw storage.
	 * @return int|false
	 */
	private function exact_delete_raw_row( $meta_id, $term_id, $key, $expected_raw ) {
		global $wpdb;

		if ( null === $expected_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_termmeta CAS.
			return $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND meta_value IS NULL',
					$wpdb->termmeta,
					(int) $meta_id,
					(int) $term_id,
					(string) $key
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed-purpose byte-exact wp_termmeta CAS.
		return $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE meta_id = %d AND term_id = %d AND CAST(meta_key AS BINARY) = CAST(%s AS BINARY) AND CAST(meta_value AS BINARY) = CAST(%s AS BINARY)',
				$wpdb->termmeta,
				(int) $meta_id,
				(int) $term_id,
				(string) $key,
				$expected_raw
			)
		);
	}
}
