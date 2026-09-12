<?php
/**
 * Generic post metadata abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Exposes provider-neutral post metadata behind explicit advanced access.
 */
final class Post_Meta_Abilities {
	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the metadata Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers generic post metadata abilities.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_ability(
			'wp-native-builder/post-meta-read',
			array(
				'label'               => __( 'Read Post Metadata', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists metadata keys or reads one exact metadata key for a WordPress post object when Advanced Metadata access is enabled.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-native-builder/post-meta-update',
			array(
				'label'               => __( 'Update Post Metadata', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates or replaces one single-value metadata key after checking Advanced Metadata access, WordPress authority, and the expected metadata state.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->update_input_schema(),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_update' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-native-builder/post-meta-delete',
			array(
				'label'               => __( 'Delete Post Metadata', 'wp-native-builder-bridge' ),
				'description'         => __( 'Deletes one single-value metadata key after checking Advanced Metadata, destructive access, WordPress authority, and the expected metadata state.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->delete_input_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'    => array( 'type' => 'integer' ),
						'key'        => array( 'type' => 'string' ),
						'deleted'    => array( 'type' => 'boolean' ),
						'state_hash' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'key', 'deleted', 'state_hash' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);
	}

	/**
	 * Checks post metadata read permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool
	 */
	public function can_read( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) ) {
			return false;
		}

		$post = $this->authorized_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post ) {
			return false;
		}

		if ( empty( $input['key'] ) ) {
			return true;
		}

		$key = (string) $input['key'];
		return ! $this->is_sensitive_key( $key ) && $this->can_access_meta_key( $post, $key, 'edit' );
	}

	/**
	 * Checks post metadata update permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool
	 */
	public function can_update( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) || ! is_array( $input ) || empty( $input['key'] ) ) {
			return false;
		}

		$post = $this->authorized_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		$key  = (string) $input['key'];
		if ( ! $post || $this->is_sensitive_key( $key ) ) {
			return false;
		}

		$values = get_post_meta( $post->ID, $key, false );
		return $this->can_access_meta_key( $post, $key, empty( $values ) ? 'add' : 'edit' );
	}

	/**
	 * Checks destructive post metadata permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool
	 */
	public function can_delete( $input ) {
		if (
			! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' )
			|| ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' )
			|| ! is_array( $input )
			|| empty( $input['key'] )
		) {
			return false;
		}

		$post = $this->authorized_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		$key  = (string) $input['key'];
		return $post && ! $this->is_sensitive_key( $key ) && $this->can_access_meta_key( $post, $key, 'delete' );
	}

	/**
	 * Lists keys or reads one exact key.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		$post = $this->validated_post( $input );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$key            = isset( $input['key'] ) ? (string) $input['key'] : '';
		$include_values = ! empty( $input['include_values'] );
		if ( $include_values && '' === $key ) {
			return new WP_Error( 'post_meta_key_required_for_values', __( 'Specify one exact metadata key before requesting metadata values.', 'wp-native-builder-bridge' ) );
		}

		if ( '' !== $key ) {
			$key_error = $this->validate_key_access( $post, $key, 'edit' );
			if ( is_wp_error( $key_error ) ) {
				return $key_error;
			}

			$item = $this->item( $post->ID, $key, $include_values );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			return array(
				'post_id'   => (int) $post->ID,
				'post_type' => (string) $post->post_type,
				'items'     => array( $item ),
			);
		}

		$keys = function_exists( 'get_post_custom_keys' ) ? get_post_custom_keys( $post->ID ) : array_keys( get_post_meta( $post->ID ) );
		$keys = is_array( $keys ) ? array_values( array_unique( array_map( 'strval', $keys ) ) ) : array();
		sort( $keys, SORT_STRING );
		$items = array();

		foreach ( $keys as $candidate ) {
			if ( $this->is_sensitive_key( $candidate ) || ! $this->can_access_meta_key( $post, $candidate, 'edit' ) ) {
				continue;
			}
			$item = $this->item( $post->ID, $candidate, false );
			if ( ! is_wp_error( $item ) ) {
				$items[] = $item;
			}
		}

		return array(
			'post_id'   => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'items'     => $items,
		);
	}

	/**
	 * Creates or replaces one single-value metadata key.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( $input ) {
		$post = $this->validated_post( $input );
		if ( is_wp_error( $post ) ) {
			return $this->logged_error( $post, isset( $input['post_id'] ) ? (int) $input['post_id'] : 0, 'wp-native-builder/post-meta-update' );
		}

		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return $this->logged_error( new WP_Error( 'post_meta_key_required', __( 'A metadata key is required.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-update' );
		}

		$values    = get_post_meta( $post->ID, $key, false );
		$operation = empty( $values ) ? 'add' : 'edit';
		$key_error = $this->validate_key_access( $post, $key, $operation );
		if ( is_wp_error( $key_error ) ) {
			return $this->logged_error( $key_error, $post->ID, 'wp-native-builder/post-meta-update' );
		}
		if ( count( $values ) > 1 ) {
			return $this->logged_error( new WP_Error( 'post_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic updater refuses to guess which row should be replaced.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-update' );
		}
		if ( $values && is_object( $values[0] ) ) {
			return $this->logged_error( new WP_Error( 'post_meta_object_value_unsupported', __( 'This metadata key contains a PHP object and cannot be losslessly replaced through the generic JSON metadata contract.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-update' );
		}

		$current_hash = $this->state_hash( $values );
		$expected     = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current_hash, $expected ) ) {
			return $this->logged_error( new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before updating it.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-update' );
		}

		$value = $this->decode_value( isset( $input['value_json'] ) ? (string) $input['value_json'] : '' );
		if ( is_wp_error( $value ) ) {
			return $this->logged_error( $value, $post->ID, 'wp-native-builder/post-meta-update' );
		}

		if ( 1 === count( $values ) && $current_hash === $this->state_hash( array( $value ) ) ) {
			return $this->item( $post->ID, $key, true );
		}

		$result = update_post_meta( $post->ID, $key, wp_slash( $value ) );
		if ( false === $result ) {
			return $this->logged_error( new WP_Error( 'post_meta_update_failed', __( 'WordPress could not update the requested metadata key.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-update' );
		}

		$this->log->record( 'wp-native-builder/post-meta-update', 'post_meta', (int) $post->ID, true, '' );
		return $this->item( $post->ID, $key, true );
	}

	/**
	 * Deletes one single-value metadata key.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete( $input ) {
		$post_id = is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( ! $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'read' ) ) {
			return $this->logged_error( new WP_Error( 'destructive_access_disabled', __( 'Users & Destructive access is required before deleting post metadata.', 'wp-native-builder-bridge' ) ), $post_id, 'wp-native-builder/post-meta-delete' );
		}

		$post = $this->validated_post( $input );
		if ( is_wp_error( $post ) ) {
			return $this->logged_error( $post, $post_id, 'wp-native-builder/post-meta-delete' );
		}

		$key = isset( $input['key'] ) ? (string) $input['key'] : '';
		if ( '' === $key ) {
			return $this->logged_error( new WP_Error( 'post_meta_key_required', __( 'A metadata key is required.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-delete' );
		}

		$key_error = $this->validate_key_access( $post, $key, 'delete' );
		if ( is_wp_error( $key_error ) ) {
			return $this->logged_error( $key_error, $post->ID, 'wp-native-builder/post-meta-delete' );
		}

		$values = get_post_meta( $post->ID, $key, false );
		if ( count( $values ) > 1 ) {
			return $this->logged_error( new WP_Error( 'post_meta_multiple_values_unsupported', __( 'This metadata key has multiple rows. The generic deleter refuses to remove an ambiguous multi-row value set.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-delete' );
		}

		$current_hash = $this->state_hash( $values );
		$expected     = isset( $input['expected_state_hash'] ) ? (string) $input['expected_state_hash'] : '';
		if ( '' === $expected || ! hash_equals( $current_hash, $expected ) ) {
			return $this->logged_error( new WP_Error( 'stale_post_meta_conflict', __( 'Post metadata changed after it was read. Refresh the metadata state before deleting it.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-delete' );
		}

		if ( empty( $values ) ) {
			return array(
				'post_id'    => (int) $post->ID,
				'key'        => $key,
				'deleted'    => false,
				'state_hash' => $current_hash,
			);
		}

		if ( ! delete_post_meta( $post->ID, $key ) ) {
			return $this->logged_error( new WP_Error( 'post_meta_delete_failed', __( 'WordPress could not delete the requested metadata key.', 'wp-native-builder-bridge' ) ), $post->ID, 'wp-native-builder/post-meta-delete' );
		}

		$this->log->record( 'wp-native-builder/post-meta-delete', 'post_meta', (int) $post->ID, true, '' );
		return array(
			'post_id'    => (int) $post->ID,
			'key'        => $key,
			'deleted'    => true,
			'state_hash' => $this->state_hash( array() ),
		);
	}

	/**
	 * Returns a post only when it is outside Bridge-private storage and the user may edit it.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	private function authorized_post( $post_id ) {
		if ( $post_id < 1 ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_type, array( 'wpnb_doc', 'wpnb_task' ), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Validates common target and Advanced Metadata access inside execute callbacks.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return object|WP_Error
	 */
	private function validated_post( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_ADVANCED_METADATA, 'read' ) ) {
			return new WP_Error( 'advanced_metadata_access_disabled', __( 'Advanced Metadata access is disabled in WP Native Builder settings.', 'wp-native-builder-bridge' ) );
		}
		$post = $this->authorized_post( is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
		if ( ! $post ) {
			return new WP_Error( 'post_meta_target_not_allowed', __( 'The requested post does not exist, belongs to Bridge-private Workspace storage, or cannot be edited by the current WordPress user.', 'wp-native-builder-bridge' ) );
		}
		return $post;
	}

	/**
	 * Validates one metadata key and the corresponding WordPress authority.
	 *
	 * @param object $post      Post object.
	 * @param string $key       Exact metadata key.
	 * @param string $operation add, edit, or delete.
	 * @return true|WP_Error
	 */
	private function validate_key_access( $post, $key, $operation ) {
		if ( $this->is_sensitive_key( $key ) ) {
			return new WP_Error( 'sensitive_post_meta_key', __( 'Credential-like metadata keys are outside the generic Bridge metadata surface.', 'wp-native-builder-bridge' ) );
		}
		if ( ! $this->can_access_meta_key( $post, $key, $operation ) ) {
			return new WP_Error( 'post_meta_permission_denied', __( 'The current WordPress user is not allowed to perform this metadata operation.', 'wp-native-builder-bridge' ) );
		}
		return true;
	}

	/**
	 * Preserves explicit provider/Core meta authorization while allowing deliberately
	 * enabled private unregistered metadata through the post's own edit capability.
	 *
	 * For protected unregistered keys, Core adds the requested meta capability itself
	 * to the mapped primitive-cap list as the default deny sentinel. Advanced Metadata
	 * removes only that sentinel. Any additional requirements injected through the
	 * final map_meta_cap filter remain enforced.
	 *
	 * @param object $post      Post object.
	 * @param string $key       Exact metadata key.
	 * @param string $operation add, edit, or delete.
	 * @return bool
	 */
	private function can_access_meta_key( $post, $key, $operation ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		$capability = 'edit_post_meta';
		if ( 'add' === $operation ) {
			$capability = 'add_post_meta';
		} elseif ( 'delete' === $operation ) {
			$capability = 'delete_post_meta';
		}

		$protected = function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'post' );
		if ( ! $protected || $this->has_explicit_meta_auth_contract( $post->post_type, $key ) ) {
			return current_user_can( $capability, $post->ID, $key );
		}

		if ( ! function_exists( 'map_meta_cap' ) || ! function_exists( 'get_current_user_id' ) ) {
			return true;
		}

		$mapped_caps = map_meta_cap( $capability, get_current_user_id(), $post->ID, $key );
		foreach ( array_unique( (array) $mapped_caps ) as $required_cap ) {
			if ( $capability === $required_cap ) {
				continue;
			}
			if ( 'do_not_allow' === $required_cap || ! current_user_can( $required_cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Detects whether Core/provider code explicitly owns authorization for a key.
	 *
	 * @param string $post_type Post subtype.
	 * @param string $key       Metadata key.
	 * @return bool
	 */
	private function has_explicit_meta_auth_contract( $post_type, $key ) {
		if ( function_exists( 'get_registered_meta_keys' ) ) {
			$registered_global  = get_registered_meta_keys( 'post' );
			$registered_subtype = get_registered_meta_keys( 'post', $post_type );
			if (
				( is_array( $registered_global ) && isset( $registered_global[ $key ] ) )
				|| ( is_array( $registered_subtype ) && isset( $registered_subtype[ $key ] ) )
			) {
				return true;
			}
		}

		if ( ! function_exists( 'has_filter' ) ) {
			return false;
		}

		return (bool) has_filter( 'auth_post_meta_' . $key . '_for_' . $post_type )
			|| (bool) has_filter( 'auth_post_meta_' . $key )
			|| (bool) has_filter( 'auth_post_' . $post_type . '_meta_' . $key );
	}

	/**
	 * Denies credential-like metadata keys without introducing provider allowlists.
	 *
	 * @param string $key Metadata key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		$normalized = strtolower( str_replace( array( '-', '.', ':' ), '_', (string) $key ) );
		return 1 === preg_match(
			'/(^|_)(password|passwd|secret|credential|credentials|access_token|refresh_token|bearer_token|auth_token|oauth_token|api_key|apikey|private_key|application_password|client_secret|consumer_secret)($|_)/',
			$normalized
		);
	}

	/**
	 * Builds one metadata item.
	 *
	 * @param int    $post_id        Post ID.
	 * @param string $key            Metadata key.
	 * @param bool   $include_values Include exact values.
	 * @return array<string,mixed>|WP_Error
	 */
	private function item( $post_id, $key, $include_values ) {
		$values      = get_post_meta( $post_id, $key, false );
		$value_types = array();
		$encoded     = array();

		foreach ( $values as $value ) {
			$value_types[] = $this->value_type( $value );
			if ( $include_values ) {
				$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false === $json ) {
					return new WP_Error( 'post_meta_value_not_json_compatible', __( 'This metadata value cannot be represented safely through the JSON Ability contract.', 'wp-native-builder-bridge' ) );
				}
				$encoded[] = array(
					'type'       => $this->value_type( $value ),
					'value_json' => $json,
				);
			}
		}

		return array(
			'key'         => $key,
			'count'       => count( $values ),
			'state_hash'  => $this->state_hash( $values ),
			'value_types' => array_values( array_unique( $value_types ) ),
			'values'      => $encoded,
		);
	}

	/**
	 * Decodes a typed JSON value supplied by the client.
	 *
	 * @param string $value_json JSON value.
	 * @return mixed|WP_Error
	 */
	private function decode_value( $value_json ) {
		try {
			return json_decode( $value_json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			return new WP_Error( 'invalid_post_meta_value_json', __( 'value_json must contain one valid JSON value.', 'wp-native-builder-bridge' ) );
		}
	}

	/**
	 * Returns a stable mutation identity for the complete value set of one key.
	 *
	 * @param array<int,mixed> $values Current metadata values.
	 * @return string
	 */
	private function state_hash( $values ) {
		return hash( 'sha256', (string) maybe_serialize( array_values( $values ) ) );
	}

	/**
	 * Maps PHP values to stable JSON-oriented type labels.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function value_type( $value ) {
		if ( is_null( $value ) ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_array( $value ) ) {
			return 'array';
		}
		if ( is_object( $value ) ) {
			return 'object';
		}
		return 'string';
	}

	/**
	 * Logs a failed mutation without logging metadata values or keys.
	 *
	 * @param WP_Error $error   Error.
	 * @param int      $post_id Post ID.
	 * @param string   $ability Ability name.
	 * @return WP_Error
	 */
	private function logged_error( WP_Error $error, $post_id, $ability ) {
		$this->log->record( $ability, 'post_meta', (int) $post_id, false, $error->get_error_code() );
		return $error;
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'include_values' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function update_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'                 => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'value_json'          => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'expected_state_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'post_id', 'key', 'value_json', 'expected_state_hash' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function delete_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'key'                 => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'expected_state_hash' => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'post_id', 'key', 'expected_state_hash' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array( 'type' => 'integer' ),
				'post_type' => array( 'type' => 'string' ),
				'items'     => array(
					'type'  => 'array',
					'items' => $this->item_schema(),
				),
			),
			'required'             => array( 'post_id', 'post_type', 'items' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function item_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'         => array( 'type' => 'string' ),
				'count'       => array( 'type' => 'integer' ),
				'state_hash'  => array( 'type' => 'string' ),
				'value_types' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'values'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'       => array( 'type' => 'string' ),
							'value_json' => array( 'type' => 'string' ),
						),
						'required'             => array( 'type', 'value_json' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'key', 'count', 'state_hash', 'value_types', 'values' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Ability metadata annotations.
	 *
	 * @param bool $read_only   Readonly.
	 * @param bool $destructive Destructive.
	 * @param bool $idempotent  Idempotent.
	 * @return array<string,mixed>
	 */
	private function meta( $read_only, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => (bool) $read_only,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
		);
	}
}
