<?php
/**
 * WordPress media abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides generic Media Library inspection and mutation through WordPress APIs.
 */
final class Media_Abilities {
	const ABSOLUTE_MAX_UPLOAD_BYTES = 20971520;

	/**
	 * Bridge permission service.
	 *
	 * @var Permissions
	 */
	private $permissions;

	/**
	 * Bounded mutation logger.
	 *
	 * @var Mutation_Log
	 */
	private $log;

	/**
	 * Creates the media Ability provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Mutation logger.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers media abilities.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_ability(
			'wp-native-builder/media-read',
			array(
				'label'               => __( 'Read Media', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists or retrieves Media Library attachments without exposing server filesystem paths.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-native-builder/media-upload',
			array(
				'label'               => __( 'Upload Media', 'wp-native-builder-bridge' ),
				'description'         => __( 'Uploads base64-encoded media through WordPress upload and MIME handling. Client-supplied server paths are not accepted.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->upload_input_schema(),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-native-builder/media-update',
			array(
				'label'               => __( 'Update Media', 'wp-native-builder-bridge' ),
				'description'         => __( 'Updates bounded Media Library metadata and attachment parent using WordPress APIs.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->update_input_schema(),
				'output_schema'       => $this->item_schema(),
				'execute_callback'    => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_update' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);

		wp_register_ability(
			'wp-native-builder/media-delete',
			array(
				'label'               => __( 'Delete Media', 'wp-native-builder-bridge' ),
				'description'         => __( 'Permanently deletes a Media Library attachment when destructive access is enabled.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id', 'deleted' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);
	}

	/**
	 * Checks media-read permission.
	 *
	 * @return bool Whether media inspection is allowed.
	 */
	public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, 'read' );
	}

	/**
	 * Checks media-upload permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether upload is allowed.
	 */
	public function can_upload( $input ) {
		if ( ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'upload_files' ) ) {
			return false;
		}

		return empty( $input['post_id'] ) || current_user_can( 'edit_post', (int) $input['post_id'] );
	}

	/**
	 * Checks media-update permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether metadata update is allowed.
	 */
	public function can_update( $input ) {
		if ( ! is_array( $input ) || empty( $input['id'] ) || ! $this->permissions->allowed( Settings::GROUP_BUILDER_WRITE, 'upload_files' ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_post', (int) $input['id'] ) ) {
			return false;
		}

		return empty( $input['parent_id'] ) || current_user_can( 'edit_post', (int) $input['parent_id'] );
	}

	/**
	 * Checks media-delete permission.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return bool Whether permanent deletion is allowed.
	 */
	public function can_delete( $input ) {
		return is_array( $input )
			&& ! empty( $input['id'] )
			&& $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, 'upload_files' )
			&& current_user_can( 'delete_post', (int) $input['id'] );
	}

	/**
	 * Lists or retrieves attachments.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Media result.
	 */
	public function read( $input ) {
		$action = isset( $input['action'] ) ? (string) $input['action'] : 'list';
		if ( 'get' === $action ) {
			$attachment = ! empty( $input['id'] ) ? get_post( (int) $input['id'] ) : null;
			if ( ! $attachment || 'attachment' !== $attachment->post_type || ! current_user_can( 'read_post', $attachment->ID ) ) {
				return new WP_Error( 'media_not_found', __( 'The requested Media Library item is not available.', 'wp-native-builder-bridge' ) );
			}
			return array(
				'items'       => array( $this->format_attachment( $attachment ) ),
				'page'        => 1,
				'per_page'    => 1,
				'total'       => 1,
				'total_pages' => 1,
			);
		}

		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 20;
		$args     = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'paged'          => $page,
			'posts_per_page' => $per_page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = (string) $input['mime_type'];
		}
		if ( isset( $input['parent_id'] ) ) {
			$args['post_parent'] = (int) $input['parent_id'];
		}

		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $attachment ) {
			if ( current_user_can( 'read_post', $attachment->ID ) ) {
				$items[] = $this->format_attachment( $attachment );
			}
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Uploads one base64-encoded file through WordPress sideload handling.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Uploaded attachment or error.
	 */
	public function upload( $input ) {
		$ability  = 'wp-native-builder/media-upload';
		$filename = sanitize_file_name( (string) $input['filename'] );
		if ( '' === $filename || false === strpos( $filename, '.' ) ) {
			return $this->logged_error( $ability, 'invalid_media_filename', __( 'A valid filename with an extension is required.', 'wp-native-builder-bridge' ) );
		}

		$max_bytes   = min( (int) wp_max_upload_size(), self::ABSOLUTE_MAX_UPLOAD_BYTES );
		$encoded     = (string) $input['content_base64'];
		$encoded_max = (int) ceil( $max_bytes * 4 / 3 ) + 8;
		if ( '' === $encoded || strlen( $encoded ) > $encoded_max ) {
			return $this->logged_error( $ability, 'media_too_large', __( 'The media payload exceeds the permitted upload size.', 'wp-native-builder-bridge' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- MCP transports binary media as explicit base64 data.
		$bytes = base64_decode( $encoded, true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > $max_bytes ) {
			return $this->logged_error( $ability, 'invalid_media_payload', __( 'content_base64 must be valid base64 within the permitted upload size.', 'wp-native-builder-bridge' ) );
		}

		$this->load_media_dependencies();
		$tmp_name = wp_tempnam( $filename );
		if ( ! $tmp_name ) {
			return $this->logged_error( $ability, 'media_temp_failed', __( 'WordPress could not allocate a temporary upload file.', 'wp-native-builder-bridge' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp_tempnam() created this bounded upload staging file.
		$written = file_put_contents( $tmp_name, $bytes );
		unset( $bytes );
		if ( false === $written ) {
			wp_delete_file( $tmp_name );
			return $this->logged_error( $ability, 'media_temp_write_failed', __( 'WordPress could not write the temporary upload file.', 'wp-native-builder-bridge' ) );
		}

		$post_data = array();
		if ( array_key_exists( 'title', $input ) ) {
			$post_data['post_title'] = (string) $input['title'];
		}
		if ( array_key_exists( 'caption', $input ) ) {
			$post_data['post_excerpt'] = (string) $input['caption'];
		}
		if ( array_key_exists( 'description', $input ) ) {
			$post_data['post_content'] = (string) $input['description'];
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_name,
			'error'    => 0,
			'size'     => (int) $written,
		);
		$post_id    = ! empty( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$result     = media_handle_sideload( $file_array, $post_id, null, $post_data );
		if ( is_wp_error( $result ) ) {
			wp_delete_file( $tmp_name );
			$this->log->record( $ability, 'attachment', 0, false, $result->get_error_code() );
			return $result;
		}

		$attachment_id = (int) $result;
		if ( array_key_exists( 'alt_text', $input ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
		}

		$this->log->record( $ability, 'attachment', $attachment_id, true, '' );
		return $this->format_attachment( get_post( $attachment_id ) );
	}

	/**
	 * Updates bounded attachment metadata.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Updated attachment or error.
	 */
	public function update( $input ) {
		$ability    = 'wp-native-builder/media-update';
		$id         = (int) $input['id'];
		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->logged_error( $ability, 'media_not_found', __( 'The requested Media Library item does not exist.', 'wp-native-builder-bridge' ), $id );
		}

		$data = array( 'ID' => $id );
		$map  = array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
			'parent_id'   => 'post_parent',
		);
		foreach ( $map as $input_key => $post_key ) {
			if ( array_key_exists( $input_key, $input ) ) {
				$data[ $post_key ] = $input[ $input_key ];
			}
		}

		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			$this->log->record( $ability, 'attachment', $id, false, $result->get_error_code() );
			return $result;
		}
		if ( array_key_exists( 'alt_text', $input ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
		}

		$this->log->record( $ability, 'attachment', $id, true, '' );
		return $this->format_attachment( get_post( $id ) );
	}

	/**
	 * Permanently deletes one attachment through WordPress.
	 *
	 * @param array<string,mixed> $input Validated Ability input.
	 * @return array<string,mixed>|WP_Error Delete result.
	 */
	public function delete( $input ) {
		$ability    = 'wp-native-builder/media-delete';
		$id         = (int) $input['id'];
		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return $this->logged_error( $ability, 'media_not_found', __( 'The requested Media Library item does not exist.', 'wp-native-builder-bridge' ), $id );
		}

		$result = wp_delete_attachment( $id, true );
		if ( ! $result ) {
			return $this->logged_error( $ability, 'media_delete_failed', __( 'WordPress could not delete the Media Library item.', 'wp-native-builder-bridge' ), $id );
		}
		$this->log->record( $ability, 'attachment', $id, true, '' );
		return array(
			'id'      => $id,
			'deleted' => true,
		);
	}

	/**
	 * Loads WordPress media upload helpers on demand.
	 *
	 * @return void
	 */
	private function load_media_dependencies() {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Formats one attachment without returning its server path.
	 *
	 * @param object $attachment Attachment post object.
	 * @return array<string,mixed> Attachment summary.
	 */
	private function format_attachment( $attachment ) {
		$metadata = wp_get_attachment_metadata( $attachment->ID );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$url      = wp_get_attachment_url( $attachment->ID );

		return array(
			'id'           => (int) $attachment->ID,
			'parent_id'    => (int) $attachment->post_parent,
			'mime_type'    => (string) $attachment->post_mime_type,
			'title'        => (string) $attachment->post_title,
			'caption'      => (string) $attachment->post_excerpt,
			'description'  => (string) $attachment->post_content,
			'alt_text'     => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'url'          => $url ? (string) $url : '',
			'modified_gmt' => (string) $attachment->post_modified_gmt,
			'width'        => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
			'height'       => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
			'filesize'     => isset( $metadata['filesize'] ) ? (int) $metadata['filesize'] : 0,
		);
	}

	/**
	 * Returns the media-read input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function read_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'    => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'get' ),
					'default' => 'list',
				),
				'id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'search'    => array( 'type' => 'string' ),
				'mime_type' => array( 'type' => 'string' ),
				'parent_id' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the media-upload input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function upload_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'filename'       => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'content_base64' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'post_id'        => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'title'          => array( 'type' => 'string' ),
				'caption'        => array( 'type' => 'string' ),
				'description'    => array( 'type' => 'string' ),
				'alt_text'       => array( 'type' => 'string' ),
			),
			'required'             => array( 'filename', 'content_base64' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the media-update input schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function update_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'parent_id'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'title'       => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'alt_text'    => array( 'type' => 'string' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the media-read output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'       => array(
					'type'  => 'array',
					'items' => $this->item_schema(),
				),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
				'total'       => array( 'type' => 'integer' ),
				'total_pages' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'items', 'page', 'per_page', 'total', 'total_pages' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the attachment output schema.
	 *
	 * @return array<string,mixed> JSON schema.
	 */
	private function item_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer' ),
				'parent_id'    => array( 'type' => 'integer' ),
				'mime_type'    => array( 'type' => 'string' ),
				'title'        => array( 'type' => 'string' ),
				'caption'      => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
				'alt_text'     => array( 'type' => 'string' ),
				'url'          => array( 'type' => 'string' ),
				'modified_gmt' => array( 'type' => 'string' ),
				'width'        => array( 'type' => 'integer' ),
				'height'       => array( 'type' => 'integer' ),
				'filesize'     => array( 'type' => 'integer' ),
			),
			'required'             => array( 'id', 'parent_id', 'mime_type', 'title', 'caption', 'description', 'alt_text', 'url', 'modified_gmt', 'width', 'height', 'filesize' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds standard MCP exposure metadata.
	 *
	 * @param bool $read_only   Whether read-only.
	 * @param bool $destructive Whether destructive.
	 * @param bool $idempotent  Whether idempotent.
	 * @return array<string,mixed> Ability metadata.
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

	/**
	 * Records a bounded media mutation failure.
	 *
	 * @param string $ability   Ability name.
	 * @param string $code      Error code.
	 * @param string $message   Error message.
	 * @param int    $target_id Optional attachment ID.
	 * @return WP_Error Error object.
	 */
	private function logged_error( $ability, $code, $message, $target_id = 0 ) {
		$this->log->record( $ability, 'attachment', (int) $target_id, false, $code );
		return new WP_Error( $code, $message );
	}
}
