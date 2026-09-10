<?php
/**
 * Managed Code Snippets lifecycle fallback abilities.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Uses the documented Code Snippets programmatic lifecycle; never evaluates supplied code directly.
 */
final class Code_Snippets_Abilities {
	/** @var Permissions */ private $permissions;
	/** @var Mutation_Log */ private $log;
	/** @param Permissions $permissions Permissions. @param Mutation_Log $log Log. */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;}

	/** @return void */
	public function register() {
		if ( ! $this->available() ) {
			return;}
		wp_register_ability(
			'wp-native-builder/snippets-read',
			array(
				'label'               => __( 'Read Managed Snippets', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists or retrieves site-local managed Code Snippets through the plugin public lifecycle API.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_schema(),
				'output_schema'       => $this->list_schema(),
				'execute_callback'    => array( $this, 'read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);
		wp_register_ability(
			'wp-native-builder/snippet-upsert',
			array(
				'label'               => __( 'Create or Update Managed Snippet', 'wp-native-builder-bridge' ),
				'description'         => __( 'Creates or updates a managed snippet using an installed Code Snippets scope; type is derived by the provider and supplied code is never directly evaluated by the Bridge.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->upsert_schema(),
				'output_schema'       => $this->result_schema(),
				'execute_callback'    => array( $this, 'upsert' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		wp_register_ability(
			'wp-native-builder/snippet-lifecycle',
			array(
				'label'               => __( 'Change Managed Snippet Lifecycle', 'wp-native-builder-bridge' ),
				'description'         => __( 'Activates, deactivates, trashes, or restores a managed Code Snippet through its supported lifecycle.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'action' => array(
							'type' => 'string',
							'enum' => array( 'activate', 'deactivate', 'trash', 'restore' ),
						),
					),
					'required'             => array( 'id', 'action' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->result_schema(),
				'execute_callback'    => array( $this, 'lifecycle' ),
				'permission_callback' => array( $this, 'can_lifecycle' ),
				'meta'                => $this->meta( false, false, false ),
			)
		);
		wp_register_ability(
			'wp-native-builder/snippet-delete',
			array(
				'label'               => __( 'Permanently Delete Managed Snippet', 'wp-native-builder-bridge' ),
				'description'         => __( 'Permanently deletes an already-trashed site-local Code Snippet under destructive access.', 'wp-native-builder-bridge' ),
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
						'deleted' => array( 'type' => 'boolean' ),
						'id'      => array( 'type' => 'integer' ),
					),
					'required'             => array( 'deleted', 'id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);
	}
	/** @return bool */ public function can_read() {
		return $this->permissions->allowed( Settings::GROUP_SITE_READ, $this->provider_capability() );}
	/** @return bool */ public function can_manage() {
		return $this->permissions->allowed( Settings::GROUP_CODE_EXTENSIONS, $this->provider_capability() );}
	/** @param array<string,mixed> $input Input. @return bool */ public function can_lifecycle( $input ) {
		return is_array( $input ) && ! empty( $input['action'] ) && $this->permissions->allowed( Settings::GROUP_CODE_EXTENSIONS, $this->provider_capability() );}
	/** @return bool */ public function can_delete() {
		return $this->permissions->allowed( Settings::GROUP_USERS_DESTRUCTIVE, $this->provider_capability() );}

	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function read( $input ) {
		if ( 'get' === $input['action'] ) {
			$snippet = \Code_Snippets\get_snippet( (int) $input['id'], false );
			if ( ! $snippet || empty( $snippet->id ) ) {
				return new WP_Error( 'snippet_not_found', __( 'The managed snippet was not found.', 'wp-native-builder-bridge' ) );
			}return array( 'items' => array( $this->format( $snippet ) ) );}
		$items = array();
		foreach ( \Code_Snippets\get_snippets( array(), false ) as $snippet ) {
			if ( $snippet && ( ! empty( $input['include_trash'] ) || empty( $snippet->trashed ) ) ) {
				$items[] = $this->format( $snippet );
			}
		}return array( 'items' => $items );
	}
	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function upsert( $input ) {
		$class  = 'Code_Snippets\\Model\\Snippet';
		$scopes = $class::get_all_scopes();
		$scope  = (string) $input['scope'];
		if ( ! in_array( $scope, $scopes, true ) ) {
			return new WP_Error( 'invalid_snippet_scope', __( 'The installed Code Snippets provider does not support that scope.', 'wp-native-builder-bridge' ) );}
		if ( 'create' === $input['action'] ) {
			$snippet = new $class(
				array(
					'name'     => sanitize_text_field( (string) $input['name'] ),
					'desc'     => isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : '',
					'code'     => (string) $input['code'],
					'tags'     => isset( $input['tags'] ) ? array_map( 'sanitize_text_field', $input['tags'] ) : array(),
					'scope'    => $scope,
					'priority' => isset( $input['priority'] ) ? (int) $input['priority'] : 10,
					'network'  => false,
				)
			);
		} else {
			$snippet = \Code_Snippets\get_snippet( (int) $input['id'], false );
			if ( ! $snippet || empty( $snippet->id ) ) {
				return new WP_Error( 'snippet_not_found', __( 'The managed snippet was not found.', 'wp-native-builder-bridge' ) );
			}if ( ! empty( $snippet->locked ) ) {
				return new WP_Error( 'snippet_locked', __( 'The managed snippet is locked by Code Snippets and cannot be changed.', 'wp-native-builder-bridge' ) );
			}$snippet->name = sanitize_text_field( (string) $input['name'] );
			$snippet->desc  = isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : '';
			$snippet->code  = (string) $input['code'];
			$snippet->tags  = isset( $input['tags'] ) ? array_map( 'sanitize_text_field', $input['tags'] ) : array();
			$snippet->scope = $scope;
			if ( isset( $input['priority'] ) ) {
				$snippet->priority = (int) $input['priority'];}
		}
		$saved = \Code_Snippets\save_snippet( $snippet );
		if ( ! $saved || empty( $saved->id ) ) {
			return new WP_Error( 'snippet_save_failed', __( 'Code Snippets did not save the managed snippet.', 'wp-native-builder-bridge' ) );
		}$this->log->record( 'wp-native-builder/snippet-upsert', 'snippet', (int) $saved->id, true, '' );
		return array( 'snippet' => $this->format( $saved ) );
	}
	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function lifecycle( $input ) {
		$id      = (int) $input['id'];
		$snippet = \Code_Snippets\get_snippet( $id, false );
		if ( ! $snippet || empty( $snippet->id ) ) {
			return new WP_Error( 'snippet_not_found', __( 'The managed snippet was not found.', 'wp-native-builder-bridge' ) );
		}if ( ! empty( $snippet->locked ) ) {
			return new WP_Error( 'snippet_locked', __( 'The managed snippet is locked by Code Snippets and cannot be changed.', 'wp-native-builder-bridge' ) );
		}$action = (string) $input['action'];
		if ( 'activate' === $action ) {
			$result = \Code_Snippets\activate_snippet( $id, false );
			if ( is_string( $result ) || ! $result ) {
				return new WP_Error( 'snippet_activation_failed', is_string( $result ) ? $result : __( 'Code Snippets did not activate the snippet.', 'wp-native-builder-bridge' ) );}
		} elseif ( 'deactivate' === $action ) {
			$result = \Code_Snippets\deactivate_snippet( $id, false );
			if ( ! $result ) {
				return new WP_Error( 'snippet_deactivation_failed', __( 'Code Snippets did not deactivate the snippet.', 'wp-native-builder-bridge' ) );}
		} elseif ( 'trash' === $action ) {
			if ( ! \Code_Snippets\trash_snippet( $id, false ) ) {
				return new WP_Error( 'snippet_trash_failed', __( 'Code Snippets did not trash the snippet.', 'wp-native-builder-bridge' ) );}
		} elseif ( ! \Code_Snippets\restore_snippet( $id, false ) ) {
			return new WP_Error( 'snippet_restore_failed', __( 'Code Snippets did not restore the snippet.', 'wp-native-builder-bridge' ) );
		}
		$fresh = \Code_Snippets\get_snippet( $id, false );
		$this->log->record( 'wp-native-builder/snippet-lifecycle', 'snippet', $id, true, '' );
		return array( 'snippet' => $this->format( $fresh ) );}
	/** @param array<string,mixed> $input Input. @return array<string,mixed>|WP_Error */
	public function delete( $input ) {
		$id      = (int) $input['id'];
		$snippet = \Code_Snippets\get_snippet( $id, false );
		if ( ! $snippet || empty( $snippet->id ) ) {
			return new WP_Error( 'snippet_not_found', __( 'The managed snippet was not found.', 'wp-native-builder-bridge' ) );
		}if ( ! empty( $snippet->locked ) ) {
			return new WP_Error( 'snippet_locked', __( 'The managed snippet is locked by Code Snippets and cannot be deleted.', 'wp-native-builder-bridge' ) );
		}if ( empty( $snippet->trashed ) ) {
			return new WP_Error( 'snippet_trash_required', __( 'Trash the managed snippet before permanent deletion.', 'wp-native-builder-bridge' ) );
		}if ( ! \Code_Snippets\delete_snippet( $id, false ) ) {
			return new WP_Error( 'snippet_delete_failed', __( 'Code Snippets did not permanently delete the snippet.', 'wp-native-builder-bridge' ) );
		}$this->log->record( 'wp-native-builder/snippet-delete', 'snippet', $id, true, '' );
		return array(
			'deleted' => true,
			'id'      => $id,
		);}

	/** @return bool */ private function available() {
		$functions = array( 'Code_Snippets\\code_snippets', 'Code_Snippets\\get_snippet', 'Code_Snippets\\get_snippets', 'Code_Snippets\\save_snippet', 'Code_Snippets\\activate_snippet', 'Code_Snippets\\deactivate_snippet', 'Code_Snippets\\trash_snippet', 'Code_Snippets\\restore_snippet', 'Code_Snippets\\delete_snippet' );
		if ( ! class_exists( 'Code_Snippets\\Model\\Snippet' ) ) {
			return false;
		}foreach ( $functions as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return false;
			}
		}return true;}
	/** @return string */ private function provider_capability() {
		$plugin = \Code_Snippets\code_snippets();
		return is_object( $plugin ) && method_exists( $plugin, 'get_cap' ) ? (string) $plugin->get_cap() : 'do_not_allow';}
	/** @param object $s Snippet. @return array<string,mixed> */ private function format( $s ) {
		return array(
			'id'          => (int) $s->id,
			'name'        => (string) $s->name,
			'description' => (string) $s->desc,
			'code'        => (string) $s->code,
			'tags'        => is_array( $s->tags ) ? array_values( $s->tags ) : array(),
			'scope'       => (string) $s->scope,
			'type'        => (string) $s->type,
			'active'      => (bool) $s->active,
			'trashed'     => (bool) $s->trashed,
			'locked'      => (bool) $s->locked,
			'priority'    => (int) $s->priority,
			'modified'    => (string) $s->modified,
			'revision'    => (int) $s->revision,
		);}
	/** @return array<string,mixed> */ private function item_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer' ),
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'code'        => array( 'type' => 'string' ),
				'tags'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'scope'       => array( 'type' => 'string' ),
				'type'        => array(
					'type' => 'string',
					'enum' => array( 'php', 'html', 'css', 'js', 'cond' ),
				),
				'active'      => array( 'type' => 'boolean' ),
				'trashed'     => array( 'type' => 'boolean' ),
				'locked'      => array( 'type' => 'boolean' ),
				'priority'    => array( 'type' => 'integer' ),
				'modified'    => array( 'type' => 'string' ),
				'revision'    => array( 'type' => 'integer' ),
			),
			'required'             => array( 'id', 'name', 'description', 'code', 'tags', 'scope', 'type', 'active', 'trashed', 'locked', 'priority', 'modified', 'revision' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function list_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items' => array(
					'type'  => 'array',
					'items' => $this->item_schema(),
				),
			),
			'required'             => array( 'items' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function result_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'snippet' => $this->item_schema() ),
			'required'             => array( 'snippet' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function read_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'        => array(
					'type' => 'string',
					'enum' => array( 'list', 'get' ),
				),
				'id'            => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_trash' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);}
	/** @return array<string,mixed> */ private function upsert_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type' => 'string',
					'enum' => array( 'create', 'update' ),
				),
				'id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'name'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'code'        => array(
					'type'      => 'string',
					'maxLength' => 500000,
				),
				'tags'        => array(
					'type'     => 'array',
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'maxItems' => 50,
				),
				'scope'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 50,
				),
				'priority'    => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 1000,
				),
			),
			'required'             => array( 'action', 'name', 'code', 'scope' ),
			'additionalProperties' => false,
		);}
	/** @param bool $is_readonly Read-only. @param bool $destructive D. @param bool $idempotent I. @return array<string,mixed> */ private function meta( $is_readonly, $destructive, $idempotent ) {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'    => $is_readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);}
}
