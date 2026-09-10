<?php
/**
 * Real provider smoke for the supported Code Snippets programmatic lifecycle.
 * Safe to run without Code Snippets: reports SKIP when the fallback is not registered.
 *
 * @package WP_Native_Builder_Bridge
 */

use WP_Native_Builder_Bridge\Support\Settings;

function wpnb_issue4_snippet_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function wpnb_issue4_snippet_execute( $name, array $input ) {
	$ability = wp_get_ability( $name );
	wpnb_issue4_snippet_assert( $ability instanceof WP_Ability, 'Missing ability: ' . $name );
	return $ability->execute( $input );
}

if ( ! wp_get_ability( 'wp-native-builder/snippets-read' ) ) {
	echo "SKIP: Code Snippets supported API is not available.\n";
	return;
}

$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$snippet_id = 0;
try {
	$access = $settings->defaults();
	$access[ Settings::GROUP_SITE_READ ]         = 1;
	$access[ Settings::GROUP_CODE_EXTENSIONS ]   = 1;
	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 0;
	update_option( Settings::OPTION_NAME, $access, false );

	$created = wpnb_issue4_snippet_execute(
		'wp-native-builder/snippet-upsert',
		array(
			'action'      => 'create',
			'name'        => 'WPNB Issue 4 inert PHP fixture',
			'description' => 'Temporary integration fixture.',
			'code'        => "add_filter( 'wpnb_issue4_inert', '__return_true' );",
			'scope'       => 'global',
			'priority'    => 10,
			'tags'        => array( 'wpnb', 'issue4' ),
		)
	);
	wpnb_issue4_snippet_assert( ! is_wp_error( $created ), 'Managed PHP snippet creation failed.' );
	$snippet_id = (int) $created['snippet']['id'];
	wpnb_issue4_snippet_assert( 'php' === $created['snippet']['type'], 'Provider did not derive PHP type from global scope.' );
	wpnb_issue4_snippet_assert( false === $created['snippet']['active'], 'New managed snippet unexpectedly activated itself.' );

	$activated = wpnb_issue4_snippet_execute( 'wp-native-builder/snippet-lifecycle', array( 'id'=>$snippet_id, 'action'=>'activate' ) );
	wpnb_issue4_snippet_assert( ! is_wp_error($activated) && true === $activated['snippet']['active'], 'Managed snippet activation failed.' );
	$deactivated = wpnb_issue4_snippet_execute( 'wp-native-builder/snippet-lifecycle', array( 'id'=>$snippet_id, 'action'=>'deactivate' ) );
	wpnb_issue4_snippet_assert( ! is_wp_error($deactivated) && false === $deactivated['snippet']['active'], 'Managed snippet deactivation failed.' );
	$updated = wpnb_issue4_snippet_execute(
		'wp-native-builder/snippet-upsert',
		array(
			'action'      => 'update',
			'id'          => $snippet_id,
			'name'        => 'WPNB Issue 4 updated inert PHP fixture',
			'description' => 'Updated temporary integration fixture.',
			'code'        => "add_filter( 'wpnb_issue4_inert_updated', '__return_true' );",
			'scope'       => 'global',
			'priority'    => 20,
			'tags'        => array( 'wpnb', 'updated' ),
		)
	);
	wpnb_issue4_snippet_assert( ! is_wp_error($updated) && 20 === $updated['snippet']['priority'], 'Managed snippet update failed.' );
	$read = wpnb_issue4_snippet_execute( 'wp-native-builder/snippets-read', array( 'action'=>'get', 'id'=>$snippet_id ) );
	wpnb_issue4_snippet_assert( ! is_wp_error($read) && false !== strpos($read['items'][0]['code'],'wpnb_issue4_inert_updated'), 'Managed snippet read did not return updated code.' );

	$trash = wpnb_issue4_snippet_execute( 'wp-native-builder/snippet-lifecycle', array( 'id'=>$snippet_id, 'action'=>'trash' ) );
	wpnb_issue4_snippet_assert( ! is_wp_error($trash) && true === $trash['snippet']['trashed'], 'Managed snippet trash failed.' );
	$delete_denied = wpnb_issue4_snippet_execute( 'wp-native-builder/snippet-delete', array( 'id'=>$snippet_id ) );
	wpnb_issue4_snippet_assert( is_wp_error($delete_denied), 'Permanent snippet deletion bypassed Users & Destructive.' );

	$access[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
	update_option( Settings::OPTION_NAME, $access, false );
	$deleted = wpnb_issue4_snippet_execute( 'wp-native-builder/snippet-delete', array( 'id'=>$snippet_id ) );
	wpnb_issue4_snippet_assert( ! is_wp_error($deleted) && ! empty($deleted['deleted']), 'Permanent deletion of an already-trashed managed snippet failed.' );
	$snippet_id = 0;

	echo "PASS: Issue #4 Code Snippets lifecycle smoke.\n";
} finally {
	if ( $snippet_id > 0 && function_exists( 'Code_Snippets\\delete_snippet' ) ) {
		\Code_Snippets\delete_snippet( $snippet_id, false );
	}
	update_option( Settings::OPTION_NAME, $original, false );
}
