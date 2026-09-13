<?php
/**
 * Deterministic primary-write identity regressions for the isolated WordPress suite.
 * The query filter changes only a fixture term, immediately before the pending SQL.
 * It never rewrites SQL, registers database triggers, or targets a live site.
 */
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Support\Mutation_Log;

$checks = 0;
$failures = array();
$ok = static function ( $condition, $message ) use ( &$checks, &$failures ) { ++$checks; if ( ! $condition ) { $failures[] = $message; } };
$require = static function ( $result, $context ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $context . ': ' . $result->get_error_code() ); } return $result; };
$original_user = get_current_user_id();
$original_settings = get_option( Settings::OPTION_NAME, null );
$original_log = get_option( Mutation_Log::OPTION_NAME, null );
$taxonomy = 'wpnb36_primary';
$other_taxonomy = 'wpnb36_primary_other';
$actor_id = 0;
$query_hook = null;
$term_id = 0;
$original_tt = 0;
$raw_rows = static function ( $id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare( 'SELECT meta_id, term_id, meta_key, meta_value FROM %i WHERE term_id = %d ORDER BY meta_id', $wpdb->termmeta, $id ), ARRAY_A );
};
try {
    register_taxonomy( $taxonomy, array( 'post' ), array( 'public' => false, 'capabilities' => array( 'manage_terms' => 'wpnb36_primary_edit', 'edit_terms' => 'wpnb36_primary_edit', 'delete_terms' => 'wpnb36_primary_edit', 'assign_terms' => 'wpnb36_primary_edit' ) ) );
    register_taxonomy( $other_taxonomy, array( 'post' ), array( 'public' => false, 'capabilities' => array( 'manage_terms' => 'wpnb36_other_edit', 'edit_terms' => 'wpnb36_other_edit', 'delete_terms' => 'wpnb36_other_edit', 'assign_terms' => 'wpnb36_other_edit' ) ) );
    $actor_id = (int) $require( wp_insert_user( array( 'user_login' => 'wpnb36_primary_' . strtolower( wp_generate_password( 8, false ) ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ), 'Create isolated actor' );
    $actor = get_user_by( 'id', $actor_id );
    $actor->add_cap( 'wpnb36_primary_edit' );
    $settings = ( new Settings() )->defaults();
    $settings[ Settings::GROUP_ADVANCED_METADATA ] = 1;
    $settings[ Settings::GROUP_USERS_DESTRUCTIVE ] = 1;
    update_option( Settings::OPTION_NAME, $settings, false );
    wp_set_current_user( $actor_id );
    $read = wp_get_ability( 'wp-native-builder/term-meta-read' );
    $update = wp_get_ability( 'wp-native-builder/term-meta-update' );
    $delete = wp_get_ability( 'wp-native-builder/term-meta-delete' );
    foreach ( array( 'create', 'update', 'delete' ) as $operation ) {
        foreach ( array( 'taxonomy', 'term_taxonomy_id' ) as $drift ) {
            $fixture = $require( wp_insert_term( 'Primary identity ' . $operation . ' ' . $drift, $taxonomy ), 'Create isolated term' );
            $term_id = (int) $fixture['term_id'];
            $original_tt = (int) $fixture['term_taxonomy_id'];
            $replacement_tt = (int) $wpdb->get_var( "SELECT MAX(term_taxonomy_id) FROM {$wpdb->term_taxonomy}" ) + 100;
            $key = '_primary_identity';
            try {
                if ( 'create' !== $operation ) { $require( add_term_meta( $term_id, $key, 'original', true ), 'Initialize isolated metadata' ); }
                $target = array( 'term_id' => $term_id, 'taxonomy' => $taxonomy );
                $state = $require( $read->execute( $target + array( 'key' => $key ) ), 'Read isolated state' );
                $before = $raw_rows( $term_id );
                $interceptions = 0;
                $query_hook = static function ( $sql ) use ( &$query_hook, &$interceptions, $term_id, $original_tt, $replacement_tt, $taxonomy, $other_taxonomy, $drift, $key ) {
                    global $wpdb;
                    if ( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql ) || false === strpos( $sql, $wpdb->termmeta ) || false === strpos( $sql, $key ) ) { return $sql; }
                    remove_filter( 'query', $query_hook, PHP_INT_MAX );
                    ++$interceptions;
                    $changes = 'taxonomy' === $drift ? array( 'taxonomy' => $other_taxonomy ) : array( 'term_taxonomy_id' => $replacement_tt );
                    if ( 1 !== $wpdb->update( $wpdb->term_taxonomy, $changes, array( 'term_taxonomy_id' => $original_tt ) ) ) { throw new RuntimeException( 'The isolated transfer fixture failed.' ); }
                    clean_term_cache( $term_id, $taxonomy );
                    clean_term_cache( $term_id, $other_taxonomy );
                    return $sql;
                };
                add_filter( 'query', $query_hook, PHP_INT_MAX );
                $input = $target + array( 'key' => $key, 'expected_state_hash' => $state['items'][0]['state_hash'] );
                $result = 'delete' === $operation ? $delete->execute( $input ) : $update->execute( $input + array( 'value_json' => '"bridge"' ) );
                remove_filter( 'query', $query_hook, PHP_INT_MAX );
                $query_hook = null;
                $current = get_term( $term_id );
                $case = $operation . '/' . $drift;
                $ok( 1 === $interceptions, 'Primary write boundary was not exercised: ' . $case );
                $ok( $current && ! is_wp_error( $current ) && ( 'taxonomy' === $drift ? $other_taxonomy === $current->taxonomy : $replacement_tt === (int) $current->term_taxonomy_id ), 'Fixture did not transfer the exact current identity: ' . $case );
                $ok( is_wp_error( $result ), 'Primary mutation reported success for a changed target: ' . $case );
                $ok( $before === $raw_rows( $term_id ), 'Primary mutation changed metadata on the transferred target: ' . $case );
                if ( 'taxonomy' === $drift ) { $ok( ! current_user_can( 'edit_term', $term_id ), 'Transferred target must be outside the fixture actor authority.' ); }
            } finally {
                if ( null !== $query_hook ) { remove_filter( 'query', $query_hook, PHP_INT_MAX ); $query_hook = null; }
                $wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $taxonomy, 'term_taxonomy_id' => $original_tt ), array( 'term_id' => $term_id ) );
                clean_term_cache( $term_id, $taxonomy ); clean_term_cache( $term_id, $other_taxonomy );
                wp_delete_term( $term_id, $taxonomy );
                $term_id = 0;
            }
        }
    }
} catch ( Throwable $exception ) {
    $failures[] = 'Unexpected primary identity fixture error: ' . $exception->getMessage();
} finally {
    if ( null !== $query_hook ) { remove_filter( 'query', $query_hook, PHP_INT_MAX ); }
    wp_set_current_user( $original_user );
    if ( $term_id > 0 ) { wp_delete_term( $term_id, $taxonomy ); }
    unregister_taxonomy( $taxonomy ); unregister_taxonomy( $other_taxonomy );
    if ( $actor_id > 0 ) {
        if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
        wp_delete_user( $actor_id );
    }
    foreach ( array( Settings::OPTION_NAME => $original_settings, Mutation_Log::OPTION_NAME => $original_log ) as $name => $value ) {
        if ( null === $value ) { delete_option( $name ); } else { update_option( $name, $value, false ); }
    }
}
if ( $failures ) { foreach ( $failures as $failure ) { fwrite( STDERR, 'FAIL: ' . $failure . "\n" ); } exit( 1 ); }
echo "PASS: {$checks} primary term identity assertions (six mutation-boundary transfers).\n";
