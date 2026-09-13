<?php
/** Real WordPress source-editing lifecycle coverage for Issue #46. */

use WP_Native_Builder_Bridge\Abilities\Source_Editing_Abilities;
use WP_Native_Builder_Bridge\Support\Mutation_Log;
use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue46_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue46_error_code( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
}

$settings            = new Settings();
$original_settings   = get_option( Settings::OPTION_NAME, array() );
$original_siteurl    = get_option( 'siteurl' );
$original_home       = get_option( 'home' );
$original_theme      = get_stylesheet();
$plugin              = 'wpnb-source-fixture/wpnb-source-fixture.php';
$plugin_dir          = WP_PLUGIN_DIR . '/wpnb-source-fixture';
$plugin_file         = $plugin_dir . '/wpnb-source-fixture.php';
$plugin_helper       = $plugin_dir . '/helper.php';
$outside_file        = WP_CONTENT_DIR . '/wpnb-source-outside.php';
$symlink_file        = $plugin_dir . '/escape.php';
$outside_plugin_dir  = WP_CONTENT_DIR . '/wpnb-source-outside-plugin';
$outside_plugin_file = $outside_plugin_dir . '/wpnb-source-linked.php';
$linked_plugin_dir   = WP_PLUGIN_DIR . '/wpnb-source-linked';
$linked_plugin       = 'wpnb-source-linked/wpnb-source-linked.php';
$theme_slug          = 'wpnb-source-theme';
$theme_dir           = get_theme_root() . '/' . $theme_slug;
$theme_style         = $theme_dir . '/style.css';
$theme_functions     = $theme_dir . '/functions.php';
$plugin_original     = "<?php\n/*\nPlugin Name: WPNB Source Fixture\n*/\nfunction wpnb_source_fixture_value() { return 'original'; }\n";
$helper_original     = "<?php\nfunction wpnb_source_fixture_helper() { return 'helper'; }\n";
$theme_original      = "<?php\nfunction wpnb_source_theme_value() { return 'theme-original'; }\n";

try {
	wp_mkdir_p( $plugin_dir );
	wp_mkdir_p( $theme_dir );
	file_put_contents( $plugin_file, $plugin_original );
	file_put_contents( $plugin_helper, $helper_original );
	file_put_contents( $outside_file, "<?php\n// outside fixture\n" );
	wp_mkdir_p( $outside_plugin_dir );
	file_put_contents( $outside_plugin_file, "<?php\n/* Plugin Name: WPNB Linked Outside Source */\n" );
	@unlink( $linked_plugin_dir );
	symlink( $outside_plugin_dir, $linked_plugin_dir );
	file_put_contents( $theme_style, "/*\nTheme Name: WPNB Source Theme\nVersion: 1.0.0\n*/\n" );
	file_put_contents( $theme_functions, $theme_original );
	@unlink( $symlink_file );
	symlink( $outside_file, $symlink_file );
	chmod( $plugin_file, 0666 );
	chmod( $plugin_helper, 0666 );
	chmod( $outside_plugin_file, 0666 );
	chmod( $theme_style, 0666 );
	chmod( $theme_functions, 0666 );
	wp_clean_plugins_cache( true );
	wp_clean_themes_cache( true );

	$defaults = $settings->defaults();
	wpnb_issue46_assert( 0 === $defaults[ Settings::GROUP_SOURCE_EDITING ], 'Source Editing must default off.' );
	$legacy                                    = $defaults;
	$legacy[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	unset( $legacy[ Settings::GROUP_SOURCE_EDITING ] );
	update_option( Settings::OPTION_NAME, $legacy, false );
	wpnb_issue46_assert( 0 === $settings->all()[ Settings::GROUP_SOURCE_EDITING ], 'Legacy Code & Extensions consent silently enabled Source Editing.' );

	$read    = wp_get_ability( 'wp-native-builder/source-files-read' );
	$preview = wp_get_ability( 'wp-native-builder/source-file-preview' );
	$apply   = wp_get_ability( 'wp-native-builder/source-file-apply' );
	$recover = wp_get_ability( 'wp-native-builder/source-file-recover' );
	wpnb_issue46_assert( $read instanceof WP_Ability && $preview instanceof WP_Ability && $apply instanceof WP_Ability && $recover instanceof WP_Ability, 'Issue #46 native Abilities were not registered.' );

	$target = array(
		'kind'      => 'plugin',
		'extension' => $plugin,
		'file'      => 'wpnb-source-fixture.php',
	);
	wpnb_issue46_assert( is_wp_error( $read->execute( array_merge( array( 'action' => 'read' ), $target ) ) ), 'Code & Extensions alone exposed source content.' );

	$enabled                                    = $settings->defaults();
	$enabled[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
	$enabled[ Settings::GROUP_SOURCE_EDITING ]  = 1;
	update_option( Settings::OPTION_NAME, $enabled, false );

	$list = $read->execute(
		array(
			'action'    => 'list',
			'kind'      => 'plugin',
			'extension' => $plugin,
			'per_page'  => 100,
		)
	);
	wpnb_issue46_assert( ! is_wp_error( $list ) && $list['total'] >= 2, 'Generic plugin source inventory did not expose fixture files.' );
	wpnb_issue46_assert( false === strpos( wp_json_encode( $list ), "return 'original'" ), 'Source inventory leaked source payloads.' );

	$read_result = $read->execute( array_merge( array( 'action' => 'read' ), $target ) );
	wpnb_issue46_assert( ! is_wp_error( $read_result ) && $plugin_original === $read_result['content'], 'Elevated exact source read did not return current bytes.' );

	$adapter_info = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute( array( 'ability_name' => 'wp-native-builder/source-files-read' ) );
	wpnb_issue46_assert( ! is_wp_error( $adapter_info ) && 'wp-native-builder/source-files-read' === $adapter_info['name'], 'MCP Adapter could not inspect the source-reading Ability.' );
	$adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
	$wrapped = $adapter->execute(
		array(
			'ability_name' => 'wp-native-builder/source-files-read',
			'parameters'   => array(
				'action'    => 'list',
				'kind'      => 'plugin',
				'extension' => $plugin,
			),
		)
	);
	wpnb_issue46_assert( ! is_wp_error( $wrapped ) && true === $wrapped['success'] && $wrapped['data']['total'] >= 2, 'MCP Adapter did not preserve elevated source inventory execution.' );

	$syntax_bad = $preview->execute( array_merge( $target, array( 'candidate' => "<?php\nfunction broken( {\n" ) ) );
	wpnb_issue46_assert( is_wp_error( $syntax_bad ) && 'source_php_syntax_invalid' === $syntax_bad->get_error_code(), 'Invalid PHP candidate bypassed TOKEN_PARSE preflight.' );
	wpnb_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Syntax rejection changed source bytes.' );

	$traversal = $read->execute(
		array(
			'action'    => 'read',
			'kind'      => 'plugin',
			'extension' => $plugin,
			'file'      => '../wp-config.php',
		)
	);
	wpnb_issue46_assert( is_wp_error( $traversal ), 'Traversal source target was accepted.' );
	$symlink = $read->execute(
		array(
			'action'    => 'read',
			'kind'      => 'plugin',
			'extension' => $plugin,
			'file'      => 'escape.php',
		)
	);
	wpnb_issue46_assert( is_wp_error( $symlink ) && 'source_path_escape' === $symlink->get_error_code(), 'Symlink escape was not rejected by canonical containment.' );
	wpnb_issue46_assert( isset( get_plugins()[ $linked_plugin ] ), 'Symlinked plugin-directory fixture was not discovered by WordPress.' );
	$linked_root_escape = $read->execute(
		array(
			'action'    => 'read',
			'kind'      => 'plugin',
			'extension' => $linked_plugin,
			'file'      => 'wpnb-source-linked.php',
		)
	);
	wpnb_issue46_assert( is_wp_error( $linked_root_escape ) && 'source_path_escape' === $linked_root_escape->get_error_code(), 'Symlinked plugin root escaped the canonical plugins directory.' );

	$candidate_a = str_replace( "'original'", "'candidate-a'", $plugin_original );
	$preview_a   = $preview->execute( array_merge( $target, array( 'candidate' => $candidate_a ) ) );
	wpnb_issue46_assert( ! is_wp_error( $preview_a ), 'Valid inactive-plugin preview failed.' );
	$concurrent = str_replace( "'original'", "'concurrent'", $plugin_original );
	file_put_contents( $plugin_file, $concurrent );
	$stale = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $candidate_a,
				'preimage_sha256'  => $preview_a['preimage_sha256'],
				'candidate_sha256' => $preview_a['candidate_sha256'],
				'candidate_id'     => $preview_a['candidate_id'],
			)
		)
	);
	wpnb_issue46_assert( is_wp_error( $stale ) && 'source_preimage_stale' === $stale->get_error_code(), 'Stale preview overwrote a concurrent edit.' );
	wpnb_issue46_assert( $concurrent === file_get_contents( $plugin_file ), 'Stale apply changed the concurrent source bytes.' );
	file_put_contents( $plugin_file, $plugin_original );

	$candidate_b = str_replace( "'original'", "'candidate-b-private-marker'", $plugin_original );
	$preview_b   = $preview->execute( array_merge( $target, array( 'candidate' => $candidate_b ) ) );
	$applied_b   = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $candidate_b,
				'preimage_sha256'  => $preview_b['preimage_sha256'],
				'candidate_sha256' => $preview_b['candidate_sha256'],
				'candidate_id'     => $preview_b['candidate_id'],
			)
		)
	);
	wpnb_issue46_assert( ! is_wp_error( $applied_b ) && 'success' === $applied_b['outcome'], 'Inactive plugin source apply failed.' );
	wpnb_issue46_assert( hash( 'sha256', $candidate_b ) === hash_file( 'sha256', $plugin_file ), 'Successful source apply did not persist exact candidate bytes.' );
	wpnb_issue46_assert( 0666 === ( fileperms( $plugin_file ) & 0777 ), 'Successful source apply changed the existing file mode.' );
	$log_json = wp_json_encode( get_option( Mutation_Log::OPTION_NAME, array() ) );
	wpnb_issue46_assert( false === strpos( $log_json, 'candidate-b-private-marker' ) && false === strpos( $log_json, $candidate_b ), 'Mutation log retained source payload or diff material.' );

	file_put_contents( $plugin_file, $plugin_original );
	activate_plugin( $plugin );
	wpnb_issue46_assert( is_plugin_active( $plugin ), 'Fixture plugin could not be activated.' );
	update_option( 'siteurl', 'http://wordpress', false );
	update_option( 'home', 'http://wordpress', false );

	$active_candidate = str_replace( "'original'", "'active-valid'", $plugin_original );
	$active_preview   = $preview->execute( array_merge( $target, array( 'candidate' => $active_candidate ) ) );
	wpnb_issue46_assert( ! is_wp_error( $active_preview ) && true === $active_preview['runtime_validation_required'], 'Active PHP preview did not require runtime validation.' );
	$active_apply = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $active_candidate,
				'preimage_sha256'  => $active_preview['preimage_sha256'],
				'candidate_sha256' => $active_preview['candidate_sha256'],
				'candidate_id'     => $active_preview['candidate_id'],
			)
		)
	);
	wpnb_issue46_assert( ! is_wp_error( $active_apply ) && 'success' === $active_apply['outcome'] && true === $active_apply['control_plane_risk'], 'Valid active plugin source edit did not pass runtime validation.' );

	$fatal_candidate = "<?php\n/* Plugin Name: WPNB Source Fixture */\nthrow new RuntimeException('wpnb issue46 runtime fatal');\n";
	$fatal_preview   = $preview->execute( array_merge( $target, array( 'candidate' => $fatal_candidate ) ) );
	wpnb_issue46_assert( ! is_wp_error( $fatal_preview ), 'Parse-valid runtime-fatal candidate failed preview unexpectedly.' );
	$before_fatal = file_get_contents( $plugin_file );
	$fatal_apply  = $apply->execute(
		array_merge(
			$target,
			array(
				'candidate'        => $fatal_candidate,
				'preimage_sha256'  => $fatal_preview['preimage_sha256'],
				'candidate_sha256' => $fatal_preview['candidate_sha256'],
				'candidate_id'     => $fatal_preview['candidate_id'],
			)
		)
	);
	wpnb_issue46_assert( is_wp_error( $fatal_apply ) && 'source_runtime_validation_failed' === $fatal_apply->get_error_code(), 'Runtime-fatal active plugin edit was not rejected.' );
	wpnb_issue46_assert( $before_fatal === file_get_contents( $plugin_file ), 'Runtime validation failure did not restore exact active-plugin preimage.' );
	wpnb_issue46_assert( false === get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ), 'Verified runtime restoration left recovery material behind.' );

	deactivate_plugins( $plugin, true );
	file_put_contents( $plugin_file, $plugin_original );
	update_option( 'siteurl', $original_siteurl, false );
	update_option( 'home', $original_home, false );

	$theme_target    = array(
		'kind'      => 'theme',
		'extension' => $theme_slug,
		'file'      => 'functions.php',
	);
	$theme_candidate = str_replace( "'theme-original'", "'theme-edited'", $theme_original );
	$theme_preview   = $preview->execute( array_merge( $theme_target, array( 'candidate' => $theme_candidate ) ) );
	wpnb_issue46_assert( ! is_wp_error( $theme_preview ), 'Theme source preview failed.' );
	$theme_apply = $apply->execute(
		array_merge(
			$theme_target,
			array(
				'candidate'        => $theme_candidate,
				'preimage_sha256'  => $theme_preview['preimage_sha256'],
				'candidate_sha256' => $theme_preview['candidate_sha256'],
				'candidate_id'     => $theme_preview['candidate_id'],
			)
		)
	);
	wpnb_issue46_assert( ! is_wp_error( $theme_apply ) && 'success' === $theme_apply['outcome'], 'Inactive theme source apply failed.' );
	wpnb_issue46_assert( $theme_candidate === file_get_contents( $theme_functions ), 'Theme source apply did not persist exact candidate bytes.' );

	// Recovery must never overwrite bytes newer than the candidate.
	file_put_contents( $plugin_file, $candidate_b );
	$recovery_record = array(
		'version'          => 1,
		'token'            => wp_generate_uuid4(),
		'kind'             => 'plugin',
		'extension'        => $plugin,
		'file'             => 'wpnb-source-fixture.php',
		'preimage_sha256'  => hash( 'sha256', $plugin_original ),
		'candidate_sha256' => hash( 'sha256', $candidate_b ),
		'preimage'         => $plugin_original,
		'created_gmt'      => gmdate( 'c' ),
	);
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $recovery_record, false );
	$newer = str_replace( "'original'", "'newer-legitimate-edit'", $plugin_original );
	file_put_contents( $plugin_file, $newer );
	$conflict = $recover->execute( array( 'candidate_sha256' => $recovery_record['candidate_sha256'] ) );
	wpnb_issue46_assert( is_wp_error( $conflict ) && 'source_recovery_conflict' === $conflict->get_error_code(), 'Recovery overwrote or accepted newer legitimate bytes.' );
	wpnb_issue46_assert( $newer === file_get_contents( $plugin_file ), 'Recovery conflict changed newer legitimate bytes.' );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );

	// Exact owned candidate recovery is idempotent and restores only the bound preimage.
	file_put_contents( $plugin_file, $candidate_b );
	update_option( Source_Editing_Abilities::RECOVERY_OPTION, $recovery_record, false );
	$recovered = $recover->execute( array( 'candidate_sha256' => $recovery_record['candidate_sha256'] ) );
	wpnb_issue46_assert( ! is_wp_error( $recovered ) && true === $recovered['recovered'], 'Exact candidate recovery failed.' );
	wpnb_issue46_assert( $plugin_original === file_get_contents( $plugin_file ), 'Exact recovery did not restore the preimage.' );
	wpnb_issue46_assert( false === get_option( Source_Editing_Abilities::RECOVERY_OPTION, false ), 'Successful recovery left private recovery material behind.' );

	echo "PASS: Issue #46 source editing gates, confinement, concurrency, persistence, runtime validation, recovery, MCP exposure, theme handling, and log privacy.\n";
} finally {
	deactivate_plugins( $plugin, true );
	if ( $original_theme && get_stylesheet() !== $original_theme ) {
		switch_theme( $original_theme );
	}
	update_option( 'siteurl', $original_siteurl, false );
	update_option( 'home', $original_home, false );
	update_option( Settings::OPTION_NAME, $original_settings, false );
	delete_option( Source_Editing_Abilities::RECOVERY_OPTION );
	delete_option( Source_Editing_Abilities::LOCK_OPTION );
	@unlink( $symlink_file );
	@unlink( $linked_plugin_dir );
	@unlink( $outside_plugin_file );
	@rmdir( $outside_plugin_dir );
	@unlink( $plugin_helper );
	@unlink( $plugin_file );
	@rmdir( $plugin_dir );
	@unlink( $outside_file );
	@unlink( $theme_functions );
	@unlink( $theme_style );
	@rmdir( $theme_dir );
	wp_clean_plugins_cache( true );
	wp_clean_themes_cache( true );
}
