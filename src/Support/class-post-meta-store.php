<?php
/**
 * Physical post metadata storage helpers for exact-row mutations.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Support;

use WP_Error;

/**
 * Keeps Advanced Metadata mutations bound to one physical postmeta row.
 *
 * This class is intentionally not a generic database abstraction. It only operates on
 * fixed wp_postmeta columns after the Ability layer has authorized one exact post/key.
 */
final class Post_Meta_Store {
	/**
	 * Returns physical postmeta rows, bypassing get_post_metadata virtual read filters.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $key     Optional exact unslashed key.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function rows( $post_id, $key = null ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-native-builder-bridge' ) );
		}

		if ( $this->is_test_mode() ) {
			return $this->test_rows( $post_id, $key );
		}

		if ( ! function_exists( 'has_meta' ) && defined( 'ABSPATH' ) ) {
			$admin_post_file = ABSPATH . 'wp-admin/includes/post.php';
			if ( is_readable( $admin_post_file ) ) {
				require_once $admin_post_file;
			}
		}

		if ( ! function_exists( 'has_meta' ) ) {
			return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-native-builder-bridge' ) );
		}

		$metadata = has_meta( $post_id );
		if ( ! is_array( $metadata ) ) {
			return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-native-builder-bridge' ) );
		}

		$rows = array();
		foreach ( $metadata as $meta ) {
			if ( ! is_object( $meta ) || ! isset( $meta->meta_id, $meta->post_id, $meta->meta_key ) ) {
				continue;
			}
			$meta_key = (string) $meta->meta_key;
			if ( null !== $key && $meta_key !== (string) $key ) {
				continue;
			}
			$raw_value = isset( $meta->meta_value ) ? $meta->meta_value : '';
			if ( ! is_scalar( $raw_value ) && null !== $raw_value ) {
				return new WP_Error( 'post_meta_physical_state_unavailable', __( 'Physical post metadata state could not be established safely.', 'wp-native-builder-bridge' ) );
			}
			$raw_value = null === $raw_value ? '' : (string) $raw_value;
			$rows[]    = array(
				'meta_id'   => (int) $meta->meta_id,
				'post_id'   => (int) $meta->post_id,
				'key'       => $meta_key,
				'raw_value' => $raw_value,
				'value'     => maybe_unserialize( $raw_value ),
			);
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				return (int) $left['meta_id'] <=> (int) $right['meta_id'];
			}
		);

		return $rows;
	}

	/**
	 * Prepares a JSON-decoded value exactly as WordPress stores post metadata.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Exact unslashed key.
	 * @param mixed  $value   Canonical unslashed value.
	 * @return array{value:mixed,raw_value:string}
	 */
	public function prepare_value( $post_id, $key, $value ) {
		$subtype = function_exists( 'get_object_subtype' ) ? get_object_subtype( 'post', (int) $post_id ) : '';
		if ( function_exists( 'sanitize_meta' ) ) {
			$value = sanitize_meta( (string) $key, $value, 'post', $subtype );
		}

		$raw_value = maybe_serialize( $value );
		if ( null === $raw_value || false === $raw_value ) {
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
	 * Replaces one exact physical row with compare-and-swap semantics.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $key     Exact unslashed key.
	 * @param array<string,mixed> $row     Previously inspected physical row.
	 * @param array<string,mixed> $prepared Sanitized value and exact raw storage representation.
	 * @return array{value:mixed,raw_value:string}|WP_Error
	 */
	public function replace_row( $post_id, $key, array $row, array $prepared ) {
		if ( $this->is_test_mode() ) {
			$result = update_post_meta( (int) $post_id, (string) $key, $prepared['value'], $row['value'] );
			return false === $result ? new WP_Error( 'post_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-native-builder-bridge' ) ) : $prepared;
		}

		$current = $this->rows( $post_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' ) );
		}

		$short_circuit = apply_filters( 'update_post_metadata', null, (int) $post_id, (string) $key, $prepared['value'], $row['value'] );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'post_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-native-builder-bridge' ) );
		}

		global $wpdb;
		$meta_id = (int) $row['meta_id'];
		do_action( 'update_post_meta', $meta_id, (int) $post_id, (string) $key, $prepared['value'] );
		do_action( 'update_postmeta', $meta_id, (int) $post_id, (string) $key, $prepared['raw_value'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed-row CAS; caller cannot select SQL/table/columns.
		$result = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $prepared['raw_value'] ),
			array(
				'meta_id'    => $meta_id,
				'post_id'    => (int) $post_id,
				'meta_key'   => (string) $key,
				'meta_value' => (string) $row['raw_value'],
			),
			array( '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( 1 !== $result ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' ) );
		}

		wp_cache_delete( (int) $post_id, 'post_meta' );
		do_action( 'updated_post_meta', $meta_id, (int) $post_id, (string) $key, $prepared['value'] );
		do_action( 'updated_postmeta', $meta_id, (int) $post_id, (string) $key, $prepared['raw_value'] );
		return $prepared;
	}

	/**
	 * Deletes one exact physical row with compare-and-swap semantics.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $key     Exact unslashed key.
	 * @param array<string,mixed> $row     Previously inspected physical row.
	 * @return true|WP_Error
	 */
	public function delete_row( $post_id, $key, array $row ) {
		if ( $this->is_test_mode() ) {
			return delete_post_meta( (int) $post_id, (string) $key, $row['value'] ) ? true : new WP_Error( 'post_meta_delete_failed', __( 'WordPress could not delete the requested metadata key.', 'wp-native-builder-bridge' ) );
		}

		$current = $this->rows( $post_id, $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( 1 !== count( $current ) || ! $this->row_matches( $current[0], $row ) ) {
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-native-builder-bridge' ) );
		}

		$short_circuit = apply_filters( 'delete_post_metadata', null, (int) $post_id, (string) $key, $row['value'], false );
		if ( null !== $short_circuit ) {
			return new WP_Error( 'post_meta_atomic_mutation_unsupported', __( 'WordPress cannot condition this metadata value atomically. The generic Bridge refuses the mutation to avoid a stale write.', 'wp-native-builder-bridge' ) );
		}

		global $wpdb;
		$meta_id  = (int) $row['meta_id'];
		$meta_ids = array( $meta_id );
		do_action( 'delete_post_meta', $meta_ids, (int) $post_id, (string) $key, $row['value'] );
		do_action( 'delete_postmeta', $meta_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed-row CAS; caller cannot select SQL/table/columns.
		$result = $wpdb->delete(
			$wpdb->postmeta,
			array(
				'meta_id'    => $meta_id,
				'post_id'    => (int) $post_id,
				'meta_key'   => (string) $key,
				'meta_value' => (string) $row['raw_value'],
			),
			array( '%d', '%d', '%s', '%s' )
		);
		if ( 1 !== $result ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
			return new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-native-builder-bridge' ) );
		}

		wp_cache_delete( (int) $post_id, 'post_meta' );
		do_action( 'deleted_post_meta', $meta_ids, (int) $post_id, (string) $key, $row['value'] );
		do_action( 'deleted_postmeta', $meta_ids );
		return true;
	}

	/**
	 * Restores one row after a detected concurrent mutation.
	 *
	 * @param array<string,mixed> $row Previously inspected physical row.
	 * @return bool
	 */
	public function restore_deleted_row( array $row ) {
		if ( $this->is_test_mode() ) {
			return false !== add_post_meta( (int) $row['post_id'], (string) $row['key'], $row['value'], false );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Row-scoped compensation/cleanup only.
		$result = $wpdb->insert(
			$wpdb->postmeta,
			array(
				'meta_id'    => (int) $row['meta_id'],
				'post_id'    => (int) $row['post_id'],
				'meta_key'   => (string) $row['key'],
				'meta_value' => (string) $row['raw_value'],
			),
			array( '%d', '%d', '%s', '%s' )
		);
		wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		return 1 === $result;
	}

	/**
	 * Rolls back only the row changed by this invocation.
	 *
	 * @param array<string,mixed> $row              Original physical row.
	 * @param string              $expected_new_raw Raw value written by this invocation.
	 * @return bool
	 */
	public function restore_updated_row( array $row, $expected_new_raw ) {
		if ( $this->is_test_mode() ) {
			return false !== update_post_meta( (int) $row['post_id'], (string) $row['key'], $row['value'] );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed-row CAS; caller cannot select SQL/table/columns.
		$result = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => (string) $row['raw_value'] ),
			array(
				'meta_id'    => (int) $row['meta_id'],
				'post_id'    => (int) $row['post_id'],
				'meta_key'   => (string) $row['key'],
				'meta_value' => (string) $expected_new_raw,
			),
			array( '%s' ),
			array( '%d', '%d', '%s', '%s' )
		);
		wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		return 1 === $result;
	}

	/**
	 * Removes only the row created by this invocation during create-race cleanup.
	 *
	 * @param array<string,mixed> $row Physical row created by this invocation.
	 * @return bool
	 */
	public function cleanup_created_row( array $row ) {
		if ( $this->is_test_mode() ) {
			return delete_post_meta( (int) $row['post_id'], (string) $row['key'], $row['value'] );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed-row CAS; caller cannot select SQL/table/columns.
		$result = $wpdb->delete(
			$wpdb->postmeta,
			array(
				'meta_id'    => (int) $row['meta_id'],
				'post_id'    => (int) $row['post_id'],
				'meta_key'   => (string) $row['key'],
				'meta_value' => (string) $row['raw_value'],
			),
			array( '%d', '%d', '%s', '%s' )
		);
		wp_cache_delete( (int) $row['post_id'], 'post_meta' );
		return 1 === $result;
	}

	/**
	 * Checks whether two physical row snapshots are identical.
	 *
	 * @param array<string,mixed> $left  Row.
	 * @param array<string,mixed> $right Row.
	 * @return bool
	 */
	public function row_matches( array $left, array $right ) {
		return (int) $left['meta_id'] === (int) $right['meta_id']
			&& (int) $left['post_id'] === (int) $right['post_id']
			&& (string) $left['key'] === (string) $right['key']
			&& (string) $left['raw_value'] === (string) $right['raw_value'];
	}

	/** @return bool */
	private function is_test_mode() {
		return defined( 'WP_NATIVE_BUILDER_BRIDGE_TEST_MODE' ) && WP_NATIVE_BUILDER_BRIDGE_TEST_MODE;
	}

	/**
	 * Dependency-free harness adapter. Never used in WordPress runtime.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $key     Optional key.
	 * @return array<int,array<string,mixed>>
	 */
	private function test_rows( $post_id, $key ) {
		$all  = get_post_meta( (int) $post_id );
		$all  = is_array( $all ) ? $all : array();
		$rows = array();
		foreach ( $all as $meta_key => $values ) {
			if ( null !== $key && (string) $meta_key !== (string) $key ) {
				continue;
			}
			foreach ( (array) $values as $index => $value ) {
				$raw_value = maybe_serialize( $value );
				if ( null === $raw_value || false === $raw_value ) {
					$raw_value = '';
				} elseif ( true === $raw_value ) {
					$raw_value = '1';
				} elseif ( ! is_string( $raw_value ) ) {
					$raw_value = (string) $raw_value;
				}
				$rows[] = array(
					'meta_id'   => abs( crc32( $post_id . "\0" . $meta_key . "\0" . $index ) ) + 1,
					'post_id'   => (int) $post_id,
					'key'       => (string) $meta_key,
					'raw_value' => $raw_value,
					'value'     => $value,
				);
			}
		}
		return $rows;
	}
}
