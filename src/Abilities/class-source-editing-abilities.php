<?php
/**
 * Administrator-controlled installed plugin/theme source editing.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Abilities;

use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Error;

/**
 * Provides a fixed-purpose, recovery-aware source-editing lifecycle.
 */
final class Source_Editing_Abilities {
	const MAX_SOURCE_BYTES = 2097152;
	const RECOVERY_OPTION  = 'wp_native_builder_bridge_source_recovery';
	const LOCK_OPTION      = 'wp_native_builder_bridge_source_lock';

	/** @var Permissions */
	private $permissions;

	/** @var Mutation_Log */
	private $log;

	/**
	 * Creates the provider.
	 *
	 * @param Permissions  $permissions Bridge permission service.
	 * @param Mutation_Log $log         Bounded mutation log.
	 */
	public function __construct( Permissions $permissions, Mutation_Log $log ) {
		$this->permissions = $permissions;
		$this->log         = $log;
	}

	/**
	 * Registers the typed source-editing abilities.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_ability(
			'wp-native-builder/source-files-read',
			array(
				'label'               => __( 'Read Installed Extension Source', 'wp-native-builder-bridge' ),
				'description'         => __( 'Lists or reads editable installed plugin/theme source files behind the explicit Source Editing trust boundary.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->read_input_schema(),
				'output_schema'       => $this->read_output_schema(),
				'permission_callback' => array( $this, 'can_source_action' ),
				'execute_callback'    => array( $this, 'read' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-native-builder/source-file-preview',
			array(
				'label'               => __( 'Preview Installed Extension Source Edit', 'wp-native-builder-bridge' ),
				'description'         => __( 'Validates an exact source candidate and binds it to the current installed-file preimage without writing the file.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->candidate_input_schema( false ),
				'output_schema'       => $this->preview_output_schema(),
				'permission_callback' => array( $this, 'can_source_action' ),
				'execute_callback'    => array( $this, 'preview' ),
				'meta'                => $this->meta( true, false, true ),
			)
		);

		wp_register_ability(
			'wp-native-builder/source-file-apply',
			array(
				'label'               => __( 'Apply Installed Extension Source Edit', 'wp-native-builder-bridge' ),
				'description'         => __( 'Applies one exact preview-bound installed plugin/theme source candidate with verified persistence and bounded recovery.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->candidate_input_schema( true ),
				'output_schema'       => $this->apply_output_schema(),
				'permission_callback' => array( $this, 'can_source_action' ),
				'execute_callback'    => array( $this, 'apply' ),
				'meta'                => $this->meta( false, true, false ),
			)
		);

		wp_register_ability(
			'wp-native-builder/source-file-recover',
			array(
				'label'               => __( 'Recover Installed Extension Source Edit', 'wp-native-builder-bridge' ),
				'description'         => __( 'Recovers the single pending Bridge-owned source preimage only when the current file still has the exact candidate identity.', 'wp-native-builder-bridge' ),
				'category'            => Registrar::CATEGORY,
				'input_schema'        => $this->recover_input_schema(),
				'output_schema'       => $this->recover_output_schema(),
				'permission_callback' => array( $this, 'can_recover' ),
				'execute_callback'    => array( $this, 'recover' ),
				'meta'                => $this->meta( false, true, true ),
			)
		);
	}

	/**
	 * Checks source read/preview/apply authorization.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public function can_source_action( $input ) {
		if ( ! $this->source_boundary_enabled() || ! is_array( $input ) || empty( $input['kind'] ) ) {
			return false;
		}
		$kind = (string) $input['kind'];
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return false;
		}
		return current_user_can( $this->capability_for_kind( $kind ) );
	}

	/**
	 * Checks explicit recovery authorization against the recorded target kind.
	 *
	 * @return bool
	 */
	public function can_recover() {
		if ( ! $this->source_boundary_enabled() ) {
			return false;
		}

		$record = get_option( self::RECOVERY_OPTION, null );
		if ( ! is_array( $record ) || empty( $record['kind'] ) ) {
			return current_user_can( 'edit_plugins' ) || current_user_can( 'edit_themes' );
		}

		return current_user_can( $this->capability_for_kind( (string) $record['kind'] ) );
	}

	/**
	 * Lists or reads exact editable source files.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read( $input ) {
		if ( ! is_array( $input ) || empty( $input['kind'] ) ) {
			return $this->invalid_input();
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : 'list';
		if ( ! in_array( $action, array( 'list', 'read' ), true ) ) {
			return $this->invalid_input();
		}

		if ( 'read' === $action ) {
			$target = $this->resolve_target_from_input( $input );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$bytes = $this->read_target_bytes( $target );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
			return array(
				'action'      => 'read',
				'items'       => array(),
				'page'        => 1,
				'per_page'    => 1,
				'total'       => 1,
				'total_pages' => 1,
				'target'      => $this->public_target( $target, $bytes ),
				'content'     => $bytes,
			);
		}

		$kind      = (string) $input['kind'];
		$extension = isset( $input['extension'] ) ? (string) $input['extension'] : '';
		$page      = isset( $input['page'] ) ? (int) $input['page'] : 1;
		$per_page  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 25;
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || $page < 1 || $per_page < 1 || $per_page > 100 ) {
			return $this->invalid_input();
		}

		$items = $this->discover_targets( $kind, $extension );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$total       = count( $items );
		$total_pages = $total ? (int) ceil( $total / $per_page ) : 0;
		$offset      = ( $page - 1 ) * $per_page;
		$page_items  = $offset < $total ? array_slice( $items, $offset, $per_page ) : array();

		return array(
			'action'      => 'list',
			'items'       => $page_items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
			'target'      => null,
			'content'     => null,
		);
	}

	/**
	 * Validates and binds a candidate without mutation.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['candidate'] ) || ! is_string( $input['candidate'] ) ) {
			return $this->invalid_input();
		}
		$target = $this->resolve_target_from_input( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$preimage = $this->read_target_bytes( $target );
		if ( is_wp_error( $preimage ) ) {
			return $preimage;
		}
		$candidate = $input['candidate'];
		$syntax    = $this->validate_candidate( $target, $candidate );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}
		$preimage_hash  = hash( 'sha256', $preimage );
		$candidate_hash = hash( 'sha256', $candidate );

		return array(
			'kind'                        => $target['kind'],
			'extension'                   => $target['extension'],
			'file'                        => $target['file'],
			'preimage_sha256'             => $preimage_hash,
			'candidate_sha256'            => $candidate_hash,
			'candidate_id'                => $this->candidate_id( $target, $preimage_hash, $candidate_hash ),
			'candidate_bytes'             => strlen( $candidate ),
			'php_syntax_valid'            => true,
			'runtime_validation_required' => $this->runtime_validation_required( $target ),
			'control_plane_risk'          => $target['control_plane_risk'],
		);
	}

	/**
	 * Applies an exact preview-bound candidate.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function apply( $input ) {
		if ( ! is_array( $input ) || ! isset( $input['candidate'], $input['preimage_sha256'], $input['candidate_sha256'], $input['candidate_id'] )
			|| ! is_string( $input['candidate'] ) || ! is_string( $input['preimage_sha256'] ) || ! is_string( $input['candidate_sha256'] ) || ! is_string( $input['candidate_id'] ) ) {
			return $this->invalid_input();
		}

		$target = $this->resolve_target_from_input( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$candidate = $input['candidate'];
		$syntax    = $this->validate_candidate( $target, $candidate );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}
		$candidate_hash = hash( 'sha256', $candidate );
		if ( ! hash_equals( strtolower( $input['candidate_sha256'] ), $candidate_hash ) ) {
			return new WP_Error( 'source_candidate_changed', __( 'The submitted source candidate no longer matches the previewed candidate hash.', 'wp-native-builder-bridge' ), array( 'outcome' => 'conflict' ) );
		}
		$expected_id = $this->candidate_id( $target, strtolower( $input['preimage_sha256'] ), $candidate_hash );
		if ( ! hash_equals( strtolower( $input['candidate_id'] ), $expected_id ) ) {
			return new WP_Error( 'source_candidate_binding_changed', __( 'The submitted source candidate is not bound to this exact target and preimage.', 'wp-native-builder-bridge' ), array( 'outcome' => 'conflict' ) );
		}

		$preimage = $this->read_target_bytes( $target );
		if ( is_wp_error( $preimage ) ) {
			return $preimage;
		}
		$preimage_hash = hash( 'sha256', $preimage );
		if ( ! hash_equals( strtolower( $input['preimage_sha256'] ), $preimage_hash ) ) {
			return new WP_Error( 'source_preimage_stale', __( 'The installed source file changed after preview. Read and preview it again before applying.', 'wp-native-builder-bridge' ), array( 'outcome' => 'conflict' ) );
		}
		if ( ! $target['writable'] ) {
			return new WP_Error( 'source_file_not_directly_writable', __( 'The exact installed source file is not directly writable by the WordPress PHP process. Bridge does not collect FTP or SSH filesystem credentials.', 'wp-native-builder-bridge' ) );
		}
		if ( false !== get_option( self::RECOVERY_OPTION, false ) ) {
			return new WP_Error( 'source_recovery_required', __( 'A previous source edit still owns pending recovery material. Recover or reconcile it before another source write.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}

		$token = wp_generate_uuid4();
		if ( ! add_option( self::LOCK_OPTION, $token, '', false ) ) {
			return new WP_Error( 'source_edit_locked', __( 'Another Bridge source edit is already in progress.', 'wp-native-builder-bridge' ) );
		}

		try {
			$locked_target = $this->resolve_target_from_input( $input );
			if ( is_wp_error( $locked_target ) || $locked_target['canonical_path'] !== $target['canonical_path'] ) {
				return new WP_Error( 'source_target_changed', __( 'The installed source target changed before the write could begin.', 'wp-native-builder-bridge' ), array( 'outcome' => 'conflict' ) );
			}
			$locked_preimage = $this->read_target_bytes( $locked_target );
			if ( is_wp_error( $locked_preimage ) || ! hash_equals( $preimage_hash, hash( 'sha256', (string) $locked_preimage ) ) ) {
				return new WP_Error( 'source_preimage_stale', __( 'The installed source file changed before the write could begin.', 'wp-native-builder-bridge' ), array( 'outcome' => 'conflict' ) );
			}

			$record = array(
				'version'          => 1,
				'token'            => $token,
				'kind'             => $target['kind'],
				'extension'        => $target['extension'],
				'file'             => $target['file'],
				'preimage_sha256'  => $preimage_hash,
				'candidate_sha256' => $candidate_hash,
				'preimage'         => $preimage,
				'created_gmt'      => gmdate( 'c' ),
			);
			if ( ! add_option( self::RECOVERY_OPTION, $record, '', false ) ) {
				return new WP_Error( 'source_recovery_required', __( 'Recovery material already exists for another source edit.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}

			register_shutdown_function( array( $this, 'shutdown_recover' ), $token );
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			$write = $this->write_exact_bytes( $target, $candidate );
			if ( is_wp_error( $write ) ) {
				return $this->handle_failed_write( $record );
			}

			$persisted = $this->read_target_bytes( $target );
			if ( is_wp_error( $persisted ) || ! hash_equals( $candidate_hash, hash( 'sha256', (string) $persisted ) ) ) {
				return $this->handle_failed_write( $record );
			}

			if ( $this->runtime_validation_required( $target ) ) {
				$runtime = $this->validate_runtime_boot();
				if ( is_wp_error( $runtime ) ) {
					$restored = $this->restore_record( $record );
					if ( true === $restored ) {
						$this->log->record( 'wp-native-builder/source-file-apply', $target['kind'], 0, false, 'runtime_validation_failed' );
						return new WP_Error( 'source_runtime_validation_failed', __( 'WordPress runtime validation failed and the exact previous source bytes were restored.', 'wp-native-builder-bridge' ), array( 'outcome' => 'validation_failed_restored' ) );
					}
					return $restored;
				}
			}

			if ( ! $this->delete_recovery_if_token( $token ) ) {
				return new WP_Error( 'source_recovery_cleanup_failed', __( 'The source candidate was verified, but Bridge could not clear its private recovery record.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
			}

			$this->log->record( 'wp-native-builder/source-file-apply', $target['kind'], 0, true, '' );
			return array(
				'kind'               => $target['kind'],
				'extension'          => $target['extension'],
				'file'               => $target['file'],
				'outcome'            => 'success',
				'persisted_sha256'   => $candidate_hash,
				'control_plane_risk' => $target['control_plane_risk'],
			);
		} finally {
			$this->release_lock( $token );
		}
	}

	/**
	 * Explicitly restores a pending exact preimage when candidate identity still matches.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function recover( $input ) {
		$record = get_option( self::RECOVERY_OPTION, null );
		if ( ! is_array( $record ) || empty( $record['token'] ) ) {
			return array( 'outcome' => 'success', 'recovered' => false, 'preimage_sha256' => '' );
		}
		if ( ! is_array( $input ) || empty( $input['candidate_sha256'] ) || ! is_string( $input['candidate_sha256'] )
			|| ! hash_equals( (string) $record['candidate_sha256'], strtolower( $input['candidate_sha256'] ) ) ) {
			return new WP_Error( 'source_recovery_identity_required', __( 'Recovery requires the exact pending candidate hash.', 'wp-native-builder-bridge' ), array( 'outcome' => 'conflict' ) );
		}

		$lock = get_option( self::LOCK_OPTION, false );
		if ( false !== $lock && (string) $lock !== (string) $record['token'] ) {
			return new WP_Error( 'source_edit_locked', __( 'Another Bridge source edit is already in progress.', 'wp-native-builder-bridge' ) );
		}
		if ( false === $lock && ! add_option( self::LOCK_OPTION, (string) $record['token'], '', false ) ) {
			return new WP_Error( 'source_edit_locked', __( 'Another Bridge source edit is already in progress.', 'wp-native-builder-bridge' ) );
		}

		try {
			$result = $this->restore_record( $record );
			if ( true !== $result ) {
				return $result;
			}
			$this->log->record( 'wp-native-builder/source-file-recover', (string) $record['kind'], 0, true, '' );
			return array(
				'outcome'         => 'success',
				'recovered'       => true,
				'preimage_sha256' => (string) $record['preimage_sha256'],
			);
		} finally {
			$this->release_lock( (string) $record['token'] );
		}
	}

	/**
	 * Shutdown compensation for an apply that did not clear its recovery record.
	 *
	 * @param string $token Recovery token.
	 * @return void
	 */
	public function shutdown_recover( $token ) {
		$record = get_option( self::RECOVERY_OPTION, null );
		if ( ! is_array( $record ) || empty( $record['token'] ) || ! hash_equals( (string) $record['token'], (string) $token ) ) {
			return;
		}
		$this->restore_record( $record );
		$this->release_lock( $token );
	}

	/** @return bool */
	private function source_boundary_enabled() {
		return $this->permissions->allowed( Settings::GROUP_CODE_EXTENSIONS, 'read' )
			&& $this->permissions->allowed( Settings::GROUP_SOURCE_EDITING, 'read' );
	}

	/** @param string $kind Extension kind. @return string */
	private function capability_for_kind( $kind ) {
		return 'theme' === $kind ? 'edit_themes' : 'edit_plugins';
	}

	/**
	 * Resolves one exact installed source target.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_target_from_input( $input ) {
		if ( empty( $input['kind'] ) || empty( $input['extension'] ) || empty( $input['file'] )
			|| ! is_string( $input['kind'] ) || ! is_string( $input['extension'] ) || ! is_string( $input['file'] ) ) {
			return $this->invalid_input();
		}
		$kind      = $input['kind'];
		$extension = $input['extension'];
		$file      = $input['file'];
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || 0 !== validate_file( $file ) || '' === $file ) {
			return new WP_Error( 'source_target_invalid', __( 'The source target must identify one installed plugin/theme and one relative editable file.', 'wp-native-builder-bridge' ) );
		}
		return 'plugin' === $kind ? $this->resolve_plugin_target( $extension, $file ) : $this->resolve_theme_target( $extension, $file );
	}

	/**
	 * Resolves one plugin source target through Core inventory.
	 *
	 * @param string $plugin Plugin main file.
	 * @param string $file   File relative to plugin root.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_plugin_target( $plugin, $file ) {
		$this->load_editor_files();
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return new WP_Error( 'source_extension_not_found', __( 'The installed source extension was not found.', 'wp-native-builder-bridge' ) );
		}
		$root = realpath( dirname( WP_PLUGIN_DIR . '/' . $plugin ) );
		if ( false === $root ) {
			return new WP_Error( 'source_root_unavailable', __( 'The installed extension source root cannot be resolved.', 'wp-native-builder-bridge' ) );
		}
		$plugin_dir = dirname( $plugin );
		$allowed    = array();
		$types      = wp_get_plugin_file_editable_extensions( $plugin );
		foreach ( get_plugin_files( $plugin ) as $plugin_file ) {
			$relative = '.' === $plugin_dir ? basename( $plugin_file ) : substr( $plugin_file, strlen( $plugin_dir ) + 1 );
			$type     = strtolower( pathinfo( $plugin_file, PATHINFO_EXTENSION ) );
			if ( '' !== $relative && in_array( $type, $types, true ) ) {
				$allowed[ wp_normalize_path( $relative ) ] = WP_PLUGIN_DIR . '/' . $plugin_file;
			}
		}
		$file = wp_normalize_path( $file );
		if ( ! isset( $allowed[ $file ] ) ) {
			return new WP_Error( 'source_file_not_editable', __( 'That installed extension file is not in WordPress editable source inventory.', 'wp-native-builder-bridge' ) );
		}
		return $this->resolved_target(
			'plugin',
			$plugin,
			$file,
			$root,
			$allowed[ $file ],
			is_plugin_active( $plugin ),
			is_multisite() && is_plugin_active_for_network( $plugin )
		);
	}

	/**
	 * Resolves one theme source target through Core inventory.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @param string $file       File relative to theme root.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_theme_target( $stylesheet, $file ) {
		$this->load_editor_files();
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'source_extension_not_found', __( 'The installed source extension was not found.', 'wp-native-builder-bridge' ) );
		}
		$root = realpath( $theme->get_stylesheet_directory() );
		if ( false === $root ) {
			return new WP_Error( 'source_root_unavailable', __( 'The installed extension source root cannot be resolved.', 'wp-native-builder-bridge' ) );
		}
		$allowed = array();
		foreach ( wp_get_theme_file_editable_extensions( $theme ) as $type ) {
			$files = $theme->get_files( $type, -1 );
			if ( is_array( $files ) ) {
				$allowed = array_merge( $allowed, $files );
			}
		}
		$file = wp_normalize_path( $file );
		if ( ! isset( $allowed[ $file ] ) ) {
			return new WP_Error( 'source_file_not_editable', __( 'That installed extension file is not in WordPress editable source inventory.', 'wp-native-builder-bridge' ) );
		}
		$active = get_stylesheet() === $stylesheet || get_template() === $stylesheet;
		return $this->resolved_target( 'theme', $stylesheet, $file, $root, $allowed[ $file ], $active, false );
	}

	/**
	 * Builds a confined target and rejects canonical symlink escape.
	 *
	 * @param string $kind           Extension kind.
	 * @param string $extension      Installed extension identity.
	 * @param string $file           Relative file.
	 * @param string $root           Canonical extension root.
	 * @param string $path           Inventory path.
	 * @param bool   $active         Active in current site/runtime.
	 * @param bool   $network_active Network-active plugin.
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolved_target( $kind, $extension, $file, $root, $path, $active, $network_active ) {
		$canonical = realpath( $path );
		if ( false === $canonical || ! is_file( $canonical ) || ! $this->path_is_within( $canonical, $root ) ) {
			return new WP_Error( 'source_path_escape', __( 'The installed source file resolves outside its exact extension root and cannot be edited through Bridge.', 'wp-native-builder-bridge' ) );
		}
		$type = strtolower( pathinfo( $canonical, PATHINFO_EXTENSION ) );
		return array(
			'kind'               => $kind,
			'extension'          => $extension,
			'file'               => $file,
			'canonical_root'     => wp_normalize_path( $root ),
			'canonical_path'     => wp_normalize_path( $canonical ),
			'php'                => 'php' === $type,
			'active'             => (bool) $active,
			'network_active'     => (bool) $network_active,
			'writable'           => is_writable( $canonical ),
			'control_plane_risk' => (bool) ( $active || $network_active ),
		);
	}

	/**
	 * Returns source inventory data without source payloads.
	 *
	 * @param string $kind      plugin|theme.
	 * @param string $extension Optional exact extension identity.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function discover_targets( $kind, $extension ) {
		$this->load_editor_files();
		$items = array();
		if ( 'plugin' === $kind ) {
			foreach ( array_keys( get_plugins() ) as $plugin ) {
				if ( '' !== $extension && $extension !== $plugin ) {
					continue;
				}
				$plugin_dir = dirname( $plugin );
				$types      = wp_get_plugin_file_editable_extensions( $plugin );
				foreach ( get_plugin_files( $plugin ) as $plugin_file ) {
					$type = strtolower( pathinfo( $plugin_file, PATHINFO_EXTENSION ) );
					if ( ! in_array( $type, $types, true ) ) {
						continue;
					}
					$relative = '.' === $plugin_dir ? basename( $plugin_file ) : substr( $plugin_file, strlen( $plugin_dir ) + 1 );
					$target   = $this->resolve_plugin_target( $plugin, $relative );
					if ( ! is_wp_error( $target ) ) {
						$items[] = $this->public_target( $target );
					}
				}
			}
		} else {
			foreach ( wp_get_themes() as $stylesheet => $theme ) {
				if ( '' !== $extension && $extension !== $stylesheet ) {
					continue;
				}
				foreach ( wp_get_theme_file_editable_extensions( $theme ) as $type ) {
					$files = $theme->get_files( $type, -1 );
					if ( ! is_array( $files ) ) {
						continue;
					}
					foreach ( array_keys( $files ) as $relative ) {
						$target = $this->resolve_theme_target( $stylesheet, $relative );
						if ( ! is_wp_error( $target ) ) {
							$items[] = $this->public_target( $target );
						}
					}
				}
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return strcmp( $a['kind'] . "\0" . $a['extension'] . "\0" . $a['file'], $b['kind'] . "\0" . $b['extension'] . "\0" . $b['file'] );
			}
		);
		return $items;
	}

	/**
	 * Reads bounded bytes through the direct WordPress filesystem implementation.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @return string|WP_Error
	 */
	private function read_target_bytes( $target ) {
		$filesystem = $this->filesystem();
		$size       = $filesystem->size( $target['canonical_path'] );
		if ( false === $size || $size < 0 ) {
			return new WP_Error( 'source_read_failed', __( 'The installed source file could not be read.', 'wp-native-builder-bridge' ) );
		}
		if ( $size > self::MAX_SOURCE_BYTES ) {
			return new WP_Error( 'source_file_too_large', __( 'The installed source file exceeds the Bridge source-editing safety bound.', 'wp-native-builder-bridge' ) );
		}
		$bytes = $filesystem->get_contents( $target['canonical_path'] );
		if ( false === $bytes || strlen( $bytes ) !== (int) $size ) {
			return new WP_Error( 'source_read_failed', __( 'The installed source file could not be read completely.', 'wp-native-builder-bridge' ) );
		}
		return $bytes;
	}

	/**
	 * Performs candidate byte/syntax validation without executing source.
	 *
	 * @param array<string,mixed> $target    Resolved target.
	 * @param string              $candidate Candidate bytes.
	 * @return true|WP_Error
	 */
	private function validate_candidate( $target, $candidate ) {
		if ( strlen( $candidate ) > self::MAX_SOURCE_BYTES ) {
			return new WP_Error( 'source_candidate_too_large', __( 'The source candidate exceeds the Bridge source-editing safety bound.', 'wp-native-builder-bridge' ) );
		}
		if ( ! $target['php'] ) {
			return true;
		}
		try {
			token_get_all( $candidate, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return new WP_Error( 'source_php_syntax_invalid', __( 'The PHP source candidate has invalid syntax and was not written.', 'wp-native-builder-bridge' ) );
		}
		return true;
	}

	/**
	 * Writes exact bytes through WordPress direct filesystem only.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @param string              $bytes  Exact bytes.
	 * @return true|WP_Error
	 */
	private function write_exact_bytes( $target, $bytes ) {
		$filesystem = $this->filesystem();
		$mode       = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		if ( ! $filesystem->put_contents( $target['canonical_path'], $bytes, $mode ) ) {
			return new WP_Error( 'source_write_failed', __( 'WordPress direct filesystem access could not persist the source candidate.', 'wp-native-builder-bridge' ) );
		}
		wp_opcache_invalidate( $target['canonical_path'], true );
		if ( 'theme' === $target['kind'] ) {
			wp_clean_themes_cache( true );
		}
		return true;
	}

	/**
	 * Performs native edited-file scrape validation without admin session fabrication.
	 *
	 * @return true|WP_Error
	 */
	private function validate_runtime_boot() {
		$scrape_key   = substr( md5( wp_generate_uuid4() ), 0, 32 );
		$scrape_nonce = wp_generate_password( 32, false, false );
		$transient    = 'scrape_key_' . $scrape_key;
		set_transient( $transient, $scrape_nonce, 60 );
		$params = array(
			'wp_scrape_key'   => $scrape_key,
			'wp_scrape_nonce' => $scrape_nonce,
		);
		$headers = array( 'Cache-Control' => 'no-cache' );
		$urls    = array( add_query_arg( $params, admin_url( 'admin-ajax.php' ) ), add_query_arg( $params, home_url( '/' ) ) );
		try {
			foreach ( $urls as $url ) {
				$sslverify = apply_filters( 'https_local_ssl_verify', false, $url );
				$response  = wp_remote_get(
					$url,
					array(
						'headers'   => $headers,
						'timeout'   => 30,
						'sslverify' => $sslverify,
					)
				);
				if ( is_wp_error( $response ) || true !== $this->scrape_result( wp_remote_retrieve_body( $response ), $scrape_key ) ) {
					return new WP_Error( 'source_runtime_boot_failed' );
				}
			}
		} finally {
			delete_transient( $transient );
		}
		return true;
	}

	/**
	 * Parses only the Core scrape sentinel payload.
	 *
	 * @param string $body       Loopback response body.
	 * @param string $scrape_key Scrape key.
	 * @return bool
	 */
	private function scrape_result( $body, $scrape_key ) {
		$start = "###### wp_scraping_result_start:$scrape_key ######";
		$end   = "###### wp_scraping_result_end:$scrape_key ######";
		$pos   = strpos( $body, $start );
		if ( false === $pos ) {
			return false;
		}
		$payload = substr( $body, $pos + strlen( $start ) );
		$end_pos = strpos( $payload, $end );
		if ( false === $end_pos ) {
			return false;
		}
		return true === json_decode( trim( substr( $payload, 0, $end_pos ) ), true );
	}

	/** @param array<string,mixed> $target Target. @return bool */
	private function runtime_validation_required( $target ) {
		return ! empty( $target['php'] ) && ( ! empty( $target['active'] ) || ! empty( $target['network_active'] ) );
	}

	/**
	 * Attempts safe compensation after a write/persistence failure.
	 *
	 * @param array<string,mixed> $record Recovery record.
	 * @return WP_Error
	 */
	private function handle_failed_write( $record ) {
		$target = $this->resolve_record_target( $record );
		if ( is_wp_error( $target ) ) {
			return new WP_Error( 'source_write_state_uncertain', __( 'The source write outcome is uncertain and its recovery target can no longer be resolved safely.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$current = $this->read_target_bytes( $target );
		if ( is_wp_error( $current ) ) {
			return new WP_Error( 'source_write_state_uncertain', __( 'The source write outcome is uncertain and Bridge could not verify current persisted bytes.', 'wp-native-builder-bridge' ), array( 'outcome' => 'uncertain_partial_state' ) );
		}
		$current_hash = hash( 'sha256', $current );
		if ( hash_equals( (string) $record['preimage_sha256'], $current_hash ) ) {
			$this->delete_recovery_if_token( (string) $record['token'] );
			return new WP_Error( 'source_write_failed_restored', __( 'The source write failed without changing the verified previous bytes.', 'wp-native-builder-bridge' ), array( 'outcome' => 'validation_failed_restored' ) );
		}
		if ( hash_equals( (string) $record['candidate_sha256'], $current_hash ) ) {
			$restored = $this->restore_record( $record );
			if ( true === $restored ) {
				return new WP_Error( 'source_write_failed_restored', __( 'The source write failed and the exact previous source bytes were restored.', 'wp-native-builder-bridge' ), array( 'outcome' => 'validation_failed_restored' ) );
			}
			return $restored;
		}
		return new WP_Error( 'source_write_state_uncertain', __( 'The source write produced bytes that match neither the exact preimage nor the candidate. Recovery material was retained.', 'wp-native-builder-bridge' ), array( 'outcome' => 'uncertain_partial_state' ) );
	}

	/**
	 * Restores exact preimage only when current bytes are still the owned candidate.
	 *
	 * @param array<string,mixed> $record Recovery record.
	 * @return true|WP_Error
	 */
	private function restore_record( $record ) {
		$target = $this->resolve_record_target( $record );
		if ( is_wp_error( $target ) ) {
			return new WP_Error( 'source_recovery_target_changed', __( 'The pending source recovery target changed and cannot be restored automatically.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$current = $this->read_target_bytes( $target );
		if ( is_wp_error( $current ) ) {
			return new WP_Error( 'source_recovery_read_failed', __( 'Bridge could not read the pending recovery target safely.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$current_hash = hash( 'sha256', $current );
		if ( hash_equals( (string) $record['preimage_sha256'], $current_hash ) ) {
			return $this->delete_recovery_if_token( (string) $record['token'] ) ? true : new WP_Error( 'source_recovery_cleanup_failed', __( 'The previous source bytes are already restored, but Bridge could not clear its recovery record.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		if ( ! hash_equals( (string) $record['candidate_sha256'], $current_hash ) ) {
			return new WP_Error( 'source_recovery_conflict', __( 'The source file changed after the Bridge candidate. Recovery will not overwrite newer bytes.', 'wp-native-builder-bridge' ), array( 'outcome' => 'conflict' ) );
		}
		$write = $this->write_exact_bytes( $target, (string) $record['preimage'] );
		if ( is_wp_error( $write ) ) {
			return new WP_Error( 'source_recovery_failed', __( 'Bridge could not restore the exact previous source bytes. Recovery material was retained.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		$verified = $this->read_target_bytes( $target );
		if ( is_wp_error( $verified ) || ! hash_equals( (string) $record['preimage_sha256'], hash( 'sha256', (string) $verified ) ) ) {
			return new WP_Error( 'source_recovery_verification_failed', __( 'Bridge attempted recovery but could not verify the exact previous source bytes. Recovery material was retained.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		if ( ! $this->delete_recovery_if_token( (string) $record['token'] ) ) {
			return new WP_Error( 'source_recovery_cleanup_failed', __( 'The exact previous source bytes were restored, but Bridge could not clear its recovery record.', 'wp-native-builder-bridge' ), array( 'outcome' => 'recovery_required' ) );
		}
		return true;
	}

	/** @param array<string,mixed> $record Recovery record. @return array<string,mixed>|WP_Error */
	private function resolve_record_target( $record ) {
		if ( ! isset( $record['kind'], $record['extension'], $record['file'] ) ) {
			return new WP_Error( 'source_recovery_record_invalid' );
		}
		return $this->resolve_target_from_input(
			array(
				'kind'      => (string) $record['kind'],
				'extension' => (string) $record['extension'],
				'file'      => (string) $record['file'],
			)
		);
	}

	/** @param string $token Token. @return bool */
	private function delete_recovery_if_token( $token ) {
		$current = get_option( self::RECOVERY_OPTION, null );
		if ( ! is_array( $current ) || empty( $current['token'] ) || ! hash_equals( (string) $current['token'], $token ) ) {
			return false;
		}
		return delete_option( self::RECOVERY_OPTION );
	}

	/** @param string $token Token. @return void */
	private function release_lock( $token ) {
		$current = get_option( self::LOCK_OPTION, false );
		if ( false !== $current && hash_equals( (string) $current, (string) $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Gets a direct WordPress filesystem implementation without collecting credentials.
	 *
	 * @return \WP_Filesystem_Direct
	 */
	private function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		return new \WP_Filesystem_Direct( null );
	}

	/** @return void */
	private function load_editor_files() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	/**
	 * Tests canonical containment.
	 *
	 * @param string $path Canonical file path.
	 * @param string $root Canonical root path.
	 * @return bool
	 */
	private function path_is_within( $path, $root ) {
		$path = wp_normalize_path( $path );
		$root = untrailingslashit( wp_normalize_path( $root ) );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return 0 === strpos( $path, $root . '/' );
	}

	/**
	 * Creates privacy-safe public target metadata.
	 *
	 * @param array<string,mixed> $target Resolved target.
	 * @param string|null         $bytes  Optional already-read bytes.
	 * @return array<string,mixed>
	 */
	private function public_target( $target, $bytes = null ) {
		$size = null === $bytes ? $this->filesystem()->size( $target['canonical_path'] ) : strlen( $bytes );
		return array(
			'kind'                        => $target['kind'],
			'extension'                   => $target['extension'],
			'file'                        => $target['file'],
			'bytes'                       => false === $size ? 0 : (int) $size,
			'php'                         => (bool) $target['php'],
			'active'                      => (bool) $target['active'],
			'network_active'              => (bool) $target['network_active'],
			'writable'                    => (bool) $target['writable'],
			'runtime_validation_required' => $this->runtime_validation_required( $target ),
			'control_plane_risk'          => (bool) $target['control_plane_risk'],
		);
	}

	/** @param array<string,mixed> $target Target. @param string $preimage Preimage hash. @param string $candidate Candidate hash. @return string */
	private function candidate_id( $target, $preimage, $candidate ) {
		return hash( 'sha256', $target['kind'] . "\0" . $target['extension'] . "\0" . $target['file'] . "\0" . $preimage . "\0" . $candidate );
	}

	/** @return WP_Error */
	private function invalid_input() {
		return new WP_Error( 'invalid_source_editing_input', __( 'Use one exact installed plugin/theme identity and relative editable source file with the fields required by this source-editing action.', 'wp-native-builder-bridge' ) );
	}

	/** @param bool $readonly Read-only annotation. @param bool $destructive Destructive annotation. @param bool $idempotent Idempotent annotation. @return array<string,mixed> */
	private function meta( $readonly, $destructive, $idempotent ) {
		return array(
			'mcp'         => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	/** @return array<string,mixed> */
	private function target_properties() {
		return array(
			'kind'      => array( 'type' => 'string', 'enum' => array( 'plugin', 'theme' ) ),
			'extension' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 300 ),
			'file'      => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
		);
	}

	/** @return array<string,mixed> */
	private function read_input_schema() {
		$properties              = $this->target_properties();
		$properties['action']    = array( 'type' => 'string', 'enum' => array( 'list', 'read' ), 'default' => 'list' );
		$properties['page']      = array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 );
		$properties['per_page']  = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 );
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'kind' ),
			'additionalProperties' => false,
		);
	}

	/** @param bool $apply Include apply binding fields. @return array<string,mixed> */
	private function candidate_input_schema( $apply ) {
		$properties              = $this->target_properties();
		$properties['candidate'] = array( 'type' => 'string', 'maxLength' => self::MAX_SOURCE_BYTES );
		$required                = array( 'kind', 'extension', 'file', 'candidate' );
		if ( $apply ) {
			$properties['preimage_sha256']  = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
			$properties['candidate_sha256'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
			$properties['candidate_id']     = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
			$required                       = array_merge( $required, array( 'preimage_sha256', 'candidate_sha256', 'candidate_id' ) );
		}
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}

	/** @return array<string,mixed> */
	private function recover_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'candidate_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ),
			'required'             => array( 'candidate_sha256' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function target_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'                        => array( 'type' => 'string' ),
				'extension'                   => array( 'type' => 'string' ),
				'file'                        => array( 'type' => 'string' ),
				'bytes'                       => array( 'type' => 'integer' ),
				'php'                         => array( 'type' => 'boolean' ),
				'active'                      => array( 'type' => 'boolean' ),
				'network_active'              => array( 'type' => 'boolean' ),
				'writable'                    => array( 'type' => 'boolean' ),
				'runtime_validation_required' => array( 'type' => 'boolean' ),
				'control_plane_risk'          => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'kind', 'extension', 'file', 'bytes', 'php', 'active', 'network_active', 'writable', 'runtime_validation_required', 'control_plane_risk' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function read_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array( 'type' => 'string' ),
				'items'       => array( 'type' => 'array', 'items' => $this->target_output_schema() ),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
				'total'       => array( 'type' => 'integer' ),
				'total_pages' => array( 'type' => 'integer' ),
				'target'      => array( 'type' => array( 'object', 'null' ) ),
				'content'     => array( 'type' => array( 'string', 'null' ) ),
			),
			'required'             => array( 'action', 'items', 'page', 'per_page', 'total', 'total_pages', 'target', 'content' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function preview_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'                        => array( 'type' => 'string' ),
				'extension'                   => array( 'type' => 'string' ),
				'file'                        => array( 'type' => 'string' ),
				'preimage_sha256'             => array( 'type' => 'string' ),
				'candidate_sha256'            => array( 'type' => 'string' ),
				'candidate_id'                => array( 'type' => 'string' ),
				'candidate_bytes'             => array( 'type' => 'integer' ),
				'php_syntax_valid'            => array( 'type' => 'boolean' ),
				'runtime_validation_required' => array( 'type' => 'boolean' ),
				'control_plane_risk'          => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'kind', 'extension', 'file', 'preimage_sha256', 'candidate_sha256', 'candidate_id', 'candidate_bytes', 'php_syntax_valid', 'runtime_validation_required', 'control_plane_risk' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function apply_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'kind'               => array( 'type' => 'string' ),
				'extension'          => array( 'type' => 'string' ),
				'file'               => array( 'type' => 'string' ),
				'outcome'            => array( 'type' => 'string' ),
				'persisted_sha256'   => array( 'type' => 'string' ),
				'control_plane_risk' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'kind', 'extension', 'file', 'outcome', 'persisted_sha256', 'control_plane_risk' ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private function recover_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'outcome'         => array( 'type' => 'string' ),
				'recovered'       => array( 'type' => 'boolean' ),
				'preimage_sha256' => array( 'type' => 'string' ),
			),
			'required'             => array( 'outcome', 'recovered', 'preimage_sha256' ),
			'additionalProperties' => false,
		);
	}
}
