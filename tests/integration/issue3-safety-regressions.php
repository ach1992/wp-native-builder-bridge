<?php
/**
 * Real WordPress negative regression coverage for Issue #3 safety boundaries.
 *
 * Run with:
 * wp eval-file tests/integration/issue3-safety-regressions.php --user=<administrator>
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue3_safety_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wpnb_issue3_safety_code( $value ) {
	return is_wp_error( $value ) ? $value->get_error_code() : '';
}

function wpnb_issue3_safety_execute( $name, array $input ) {
	$ability = wp_get_ability( $name );
	wpnb_issue3_safety_assert( $ability instanceof WP_Ability, 'Missing registered ability: ' . $name );
	return $ability->execute( $input );
}

function wpnb_issue3_safety_read_item( $post_id ) {
	$result = wpnb_issue3_safety_execute(
		'wp-native-builder/content-read',
		array(
			'action' => 'get',
			'id'     => (int) $post_id,
		)
	);
	wpnb_issue3_safety_assert( ! is_wp_error( $result ) && 1 === count( $result['items'] ), 'Could not read current content identity.' );
	return $result['items'][0];
}

function wpnb_issue3_safety_freeze_modified( $post_id, $modified_local, $modified_gmt ) {
	return static function ( $data, $postarr ) use ( $post_id, $modified_local, $modified_gmt ) {
		if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === (int) $post_id ) {
			$data['post_modified']     = $modified_local;
			$data['post_modified_gmt'] = $modified_gmt;
		}
		return $data;
	};
}

$settings = new Settings();
$access   = $settings->defaults();
$access[ Settings::GROUP_SITE_READ ]         = 1;
$access[ Settings::GROUP_BUILDER_WRITE ]     = 1;
$access[ Settings::GROUP_LIVE_CONTENT ]      = 0;
$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
update_option( Settings::OPTION_NAME, $access, false );

$created = array();
register_post_type(
	'wpnb_record',
	array(
		'label'           => 'WPNB Administrative Record',
		'public'          => false,
		'publicly_queryable' => false,
		'show_ui'         => true,
		'show_in_rest'    => false,
		'supports'        => array( 'title' ),
		'capability_type' => 'post',
		'map_meta_cap'    => true,
	)
);
register_post_type(
	'wpnb_article',
	array(
		'label'           => 'WPNB Public Articles',
		'public'          => true,
		'show_ui'         => true,
		'show_in_rest'    => true,
		'supports'        => array( 'title', 'editor', 'excerpt', 'revisions', 'thumbnail' ),
		'capability_type' => 'post',
		'map_meta_cap'    => true,
	)
);

try {
	$admin_id = wp_insert_post(
		array(
			'post_type'    => 'wpnb_record',
			'post_status'  => 'draft',
			'post_title'   => 'Administrative record',
			'post_content' => 'Provider state that must stay outside generic Builder content.',
		),
		true
	);
	wpnb_issue3_safety_assert( ! is_wp_error( $admin_id ), 'Could not create the administrative CPT fixture.' );
	$admin_id  = (int) $admin_id;
	$created[] = $admin_id;

	$admin_read = wpnb_issue3_safety_execute( 'wp-native-builder/content-read', array( 'action' => 'get', 'id' => $admin_id ) );
	wpnb_issue3_safety_assert( is_wp_error( $admin_read ), 'show_ui-only administrative CPT leaked into generic content-read.' );
	$admin_blocks = wpnb_issue3_safety_execute( 'wp-native-builder/blocks-read', array( 'post_id' => $admin_id ) );
	wpnb_issue3_safety_assert( is_wp_error( $admin_blocks ), 'Non-editor administrative CPT leaked into Gutenberg block-read.' );
	$admin_create = wpnb_issue3_safety_execute(
		'wp-native-builder/content-upsert',
		array(
			'action'    => 'create',
			'post_type' => 'wpnb_record',
			'title'     => 'Must not exist',
			'status'    => 'draft',
		)
	);
	wpnb_issue3_safety_assert( is_wp_error( $admin_create ), 'show_ui-only administrative CPT accepted a generic Builder write.' );

	$article = wpnb_issue3_safety_execute(
		'wp-native-builder/content-upsert',
		array(
			'action'    => 'create',
			'post_type' => 'wpnb_article',
			'title'     => 'Issue 3 safety fixture',
			'content'   => '<!-- wp:paragraph --><p>Stable body</p><!-- /wp:paragraph -->',
			'excerpt'   => 'Initial excerpt',
			'status'    => 'draft',
		)
	);
	wpnb_issue3_safety_assert( ! is_wp_error( $article ), 'Content-facing editor CPT was incorrectly rejected: ' . wpnb_issue3_safety_code( $article ) );
	$article_id = (int) $article['id'];
	$created[]  = $article_id;
	wpnb_issue3_safety_assert( 64 === strlen( (string) $article['state_hash'] ), 'Content-facing CPT did not expose state_hash.' );
	$article_blocks = wpnb_issue3_safety_execute( 'wp-native-builder/blocks-read', array( 'post_id' => $article_id ) );
	wpnb_issue3_safety_assert( ! is_wp_error( $article_blocks ), 'Content-facing editor CPT was incorrectly rejected by Gutenberg block-read.' );

	// Full-content stale-state protection must catch a non-body write even if modified_gmt and body stay unchanged.
	$observed = wpnb_issue3_safety_read_item( $article_id );
	$before   = get_post( $article_id );
	$freeze   = wpnb_issue3_safety_freeze_modified( $article_id, (string) $before->post_modified, (string) $before->post_modified_gmt );
	add_filter( 'wp_insert_post_data', $freeze, 999, 2 );
	$direct = wp_update_post( array( 'ID' => $article_id, 'post_title' => 'Concurrent title' ), true );
	remove_filter( 'wp_insert_post_data', $freeze, 999 );
	wpnb_issue3_safety_assert( ! is_wp_error( $direct ), 'Could not create the concurrent non-body write fixture.' );
	$after_direct = get_post( $article_id );
	wpnb_issue3_safety_assert( (string) $after_direct->post_modified_gmt === (string) $observed['modified_gmt'], 'Concurrent fixture did not preserve modified_gmt.' );
	wpnb_issue3_safety_assert( hash( 'sha256', (string) $after_direct->post_content ) === (string) $observed['content_hash'], 'Concurrent fixture unexpectedly changed the body.' );

	$stale = wpnb_issue3_safety_execute(
		'wp-native-builder/content-upsert',
		array(
			'action'                => 'update',
			'id'                    => $article_id,
			'excerpt'               => 'AI update based on stale observation',
			'expected_modified_gmt' => $observed['modified_gmt'],
			'expected_state_hash'   => $observed['state_hash'],
		)
	);
	wpnb_issue3_safety_assert( is_wp_error( $stale ) && 'stale_content_conflict' === $stale->get_error_code(), 'Full-content stale non-body write was not rejected.' );
	wpnb_issue3_safety_assert( 'Concurrent title' === get_post( $article_id )->post_title, 'Stale full-content update overwrote the concurrent title.' );

	// Revision restore must reject an equally stale current-state identity.
	$revision_source = wp_update_post(
		array(
			'ID'           => $article_id,
			'post_title'   => 'Revision source',
			'post_content' => '<!-- wp:paragraph --><p>Revision source body</p><!-- /wp:paragraph -->',
		),
		true
	);
	wpnb_issue3_safety_assert( ! is_wp_error( $revision_source ), 'Could not create a revision source.' );
	$revisions = wpnb_issue3_safety_execute( 'wp-native-builder/revisions-read', array( 'post_id' => $article_id, 'limit' => 20 ) );
	wpnb_issue3_safety_assert( ! is_wp_error( $revisions ) && count( $revisions ) > 0, 'Expected at least one revision for stale-restore testing.' );
	$restore_observed = wpnb_issue3_safety_read_item( $article_id );
	$restore_before   = get_post( $article_id );
	$restore_freeze   = wpnb_issue3_safety_freeze_modified( $article_id, (string) $restore_before->post_modified, (string) $restore_before->post_modified_gmt );
	add_filter( 'wp_insert_post_data', $restore_freeze, 999, 2 );
	$restore_direct = wp_update_post( array( 'ID' => $article_id, 'post_title' => 'Concurrent restore guard title' ), true );
	remove_filter( 'wp_insert_post_data', $restore_freeze, 999 );
	wpnb_issue3_safety_assert( ! is_wp_error( $restore_direct ), 'Could not create the stale revision-restore fixture.' );
	$restore = wpnb_issue3_safety_execute(
		'wp-native-builder/revision-restore',
		array(
			'post_id'               => $article_id,
			'revision_id'           => (int) $revisions[0]['id'],
			'expected_modified_gmt' => $restore_observed['modified_gmt'],
			'expected_state_hash'   => $restore_observed['state_hash'],
		)
	);
	wpnb_issue3_safety_assert( is_wp_error( $restore ) && 'stale_content_conflict' === $restore->get_error_code(), 'Stale revision restore was not rejected.' );
	wpnb_issue3_safety_assert( 'Concurrent restore guard title' === get_post( $article_id )->post_title, 'Stale revision restore overwrote concurrent state.' );

	// Destructive/internal statuses must never be ordinary content-upsert transitions.
	foreach ( array( 'trash', 'auto-draft', 'inherit' ) as $invalid_status ) {
		$fresh   = wpnb_issue3_safety_read_item( $article_id );
		$blocked = wpnb_issue3_safety_execute(
			'wp-native-builder/content-upsert',
			array(
				'action'                => 'update',
				'id'                    => $article_id,
				'status'                => $invalid_status,
				'expected_modified_gmt' => $fresh['modified_gmt'],
				'expected_state_hash'   => $fresh['state_hash'],
			)
		);
		wpnb_issue3_safety_assert( is_wp_error( $blocked ), 'Ordinary content-upsert accepted internal/destructive status: ' . $invalid_status );
		wpnb_issue3_safety_assert( $invalid_status !== get_post( $article_id )->post_status, 'Blocked status transition still changed content to: ' . $invalid_status );
	}
	$delete_denied = wpnb_issue3_safety_execute( 'wp-native-builder/content-delete', array( 'id' => $article_id, 'force' => false ) );
	wpnb_issue3_safety_assert( is_wp_error( $delete_denied ), 'Dedicated destructive content delete bypassed Users & Destructive.' );

	// wp_navigation remains generically readable/editable as blocks, but published mutation must honor Live Content.
	if ( post_type_exists( 'wp_navigation' ) ) {
		$nav_id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'WPNB published navigation safety fixture',
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
			),
			true
		);
		wpnb_issue3_safety_assert( ! is_wp_error( $nav_id ), 'Could not create wp_navigation safety fixture.' );
		$nav_id    = (int) $nav_id;
		$created[] = $nav_id;
		$nav_read  = wpnb_issue3_safety_read_item( $nav_id );
		$nav_tree  = wpnb_issue3_safety_execute( 'wp-native-builder/blocks-read', array( 'post_id' => $nav_id ) );
		wpnb_issue3_safety_assert( ! is_wp_error( $nav_tree ), 'Editor-capable wp_navigation should remain readable through generic block inspection.' );

		$nav_upsert = wpnb_issue3_safety_execute(
			'wp-native-builder/content-upsert',
			array(
				'action'                => 'update',
				'id'                    => $nav_id,
				'title'                 => 'Must not apply while Live Content is off',
				'expected_modified_gmt' => $nav_read['modified_gmt'],
				'expected_state_hash'   => $nav_read['state_hash'],
			)
		);
		wpnb_issue3_safety_assert( is_wp_error( $nav_upsert ), 'Published wp_navigation bypassed Live Content through generic content-upsert.' );

		$nav_block = wpnb_issue3_safety_execute(
			'wp-native-builder/blocks-mutate',
			array(
				'post_id'               => $nav_id,
				'action'                => 'append',
				'block_markup'          => '<!-- wp:navigation-link {"label":"Denied","url":"/denied/"} /-->',
				'expected_modified_gmt' => $nav_tree['modified_gmt'],
				'expected_content_hash' => $nav_tree['content_hash'],
			)
		);
		wpnb_issue3_safety_assert( is_wp_error( $nav_block ), 'Published wp_navigation bypassed Live Content through generic block mutation.' );
		wpnb_issue3_safety_assert( false === strpos( get_post( $nav_id )->post_content, '/denied/' ), 'Denied published navigation block mutation changed content.' );
	}

	echo "PASS: Issue #3 safety regressions.\n";
} finally {
	foreach ( array_reverse( array_unique( array_map( 'intval', $created ) ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
	}
	unregister_post_type( 'wpnb_article' );
	unregister_post_type( 'wpnb_record' );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
}
