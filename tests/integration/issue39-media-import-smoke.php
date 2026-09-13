<?php
/** Real WordPress URL import lifecycle, authorization, transport and cleanup tests. */
use WP_Native_Builder_Bridge\Support\Settings;
use WP_Native_Builder_Bridge\Support\Mutation_Log;

$checks = 0;
function wpnb39_integration_assert( $condition, $message ) {
    ++$GLOBALS['checks'];
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$settings = new Settings();
$original = get_option( Settings::OPTION_NAME, $settings->defaults() );
$original_log = get_option( Mutation_Log::OPTION_NAME, array() );
$original_user = get_current_user_id();
$created = array();
$staging = array();
$destinations = array();
$calls = 0;
$mode = 'success';
$source = 'https://s.w.org/wpnb-media-import-fixture?signature=PRIVATE_MEDIA_MARKER';
$input = array( 'url' => $source, 'filename' => 'wpnb-import.png' );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=' );
$enabled = $settings->defaults();
$enabled[ Settings::GROUP_BUILDER_WRITE ] = 1;
$enabled[ Settings::GROUP_REMOTE_MEDIA ] = 1;
$mock = static function ( $pre, $args, $url ) use ( &$calls, &$mode, &$staging, $png ) {
    if ( false === strpos( $url, 'wpnb-media-import-fixture' ) ) { return $pre; }
    ++$calls;
    wpnb39_integration_assert( ! empty( $args['reject_unsafe_urls'] ) && true === $args['sslverify'], 'Native safe HTTP or TLS validation was disabled.' );
    wpnb39_integration_assert( true === $args['stream'] && false === $args['decompress'], 'Expected bounded uncompressed streaming.' );
    wpnb39_integration_assert( array() === $args['cookies'] && array( 'Accept-Encoding' => 'identity' ) === $args['headers'], 'Unexpected credential or caller-header forwarding.' );
    wpnb39_integration_assert( 30 === $args['timeout'] && 5 === $args['redirection'], 'Network budgets must remain finite.' );
    wpnb39_integration_assert( wp_max_upload_size() + 1 === $args['limit_response_size'], 'Streaming must use the current WordPress limit and overflow sentinel.' );
    $staging[] = $args['filename'];
    if ( 'http_error' === $mode ) { return new WP_Error( 'PRIVATE_MEDIA_MARKER', $url . ' ' . $args['filename'] ); }
    if ( 'missing_staging' === $mode ) { wp_delete_file( $args['filename'] ); }
    $body = 'empty' === $mode ? '' : $png;
    if ( 'oversized' === $mode ) { $body = str_repeat( 'x', $args['limit_response_size'] ); }
    if ( 'invalid_type' === $mode ) { $body = 'This is not an image.'; }
    if ( 'missing_staging' !== $mode ) { file_put_contents( $args['filename'], $body ); }
    if ( 'revoke_http' === $mode ) {
        $access = get_option( Settings::OPTION_NAME );
        $access[ Settings::GROUP_REMOTE_MEDIA ] = 0;
        update_option( Settings::OPTION_NAME, $access, false );
    }
    return array(
        'response' => array( 'code' => 'http_status' === $mode ? 500 : 200, 'message' => 'Fixture' ),
        'headers' => array( 'content-length' => 'truncated' === $mode ? '99999' : (string) strlen( $body ) ),
        'body' => '', 'cookies' => array(), 'filename' => $args['filename'],
    );
};
$observe_upload = static function ( $upload, $context ) use ( &$destinations, &$mode ) {
    if ( 'sideload' === $context && ! empty( $upload['file'] ) ) {
        $destinations[] = $upload['file'];
        if ( 'revoke_sideload' === $mode ) {
            $access = get_option( Settings::OPTION_NAME );
            $access[ Settings::GROUP_REMOTE_MEDIA ] = 0;
            update_option( Settings::OPTION_NAME, $access, false );
        }
    }
    return $upload;
};
$reject_sideload = static function ( $file ) use ( &$mode ) {
    if ( 'sideload_error' === $mode ) { $file['error'] = 'PRIVATE_MEDIA_MARKER ' . $file['tmp_name']; }
    return $file;
};
$reject_insert = static function ( $empty, $postarr ) use ( &$mode ) {
    return 'insert_error' === $mode && 'attachment' === ( $postarr['post_type'] ?? '' ) ? true : $empty;
};
$small_limit = static function () { return 128; };
$deny_upload = static function ( $caps, $cap ) { return 'upload_files' === $cap ? array( 'do_not_allow' ) : $caps; };
$parent = 0;
try {
    $ability = wp_get_ability( 'wp-native-builder/media-import-url' );
    wpnb39_integration_assert( $ability instanceof WP_Ability, 'Import Ability is not registered.' );
    add_filter( 'pre_http_request', $mock, 10, 3 );
    add_filter( 'wp_handle_sideload_prefilter', $reject_sideload );
    add_filter( 'wp_handle_upload', $observe_upload, 10, 2 );
    add_filter( 'wp_insert_post_empty_content', $reject_insert, 10, 2 );
    foreach ( array( $settings->defaults(), array( 'builder_write' => 1 ), array( 'remote_media' => 1 ) ) as $access ) {
        update_option( Settings::OPTION_NAME, $access, false );
        wpnb39_integration_assert( is_wp_error( $ability->execute( $input ) ), 'Missing or legacy opt-in permitted import.' );
    }
    wpnb39_integration_assert( 0 === $calls, 'Disabled import performed network activity.' );
    update_option( Settings::OPTION_NAME, $enabled, false );
    add_filter( 'map_meta_cap', $deny_upload, 10, 2 );
    wpnb39_integration_assert( is_wp_error( $ability->execute( $input ) ), 'Missing upload authority permitted import.' );
    remove_filter( 'map_meta_cap', $deny_upload, 10 );
    wpnb39_integration_assert( is_wp_error( $ability->execute( $input + array( 'post_id' => PHP_INT_MAX ) ) ), 'A nonexistent parent permitted import.' );
    wpnb39_integration_assert( 0 === $calls, 'Native authority must be checked before HTTP.' );
    foreach ( array( 'file:///etc/passwd', 'ftp://wordpress.org/media', 'http://127.0.0.1/media', 'http://169.254.169.254/media', 'https://user:pass@wordpress.org/media' ) as $unsafe ) {
        wpnb39_integration_assert( is_wp_error( $ability->execute( array_replace( $input, array( 'url' => $unsafe ) ) ) ), 'Unsafe URL was accepted.' );
    }
    wpnb39_integration_assert( 0 === $calls, 'Unsafe URL reached fixture transport.' );
    foreach ( array( '../image.png', 'image.php', 'image.phtml', 'package.phar' ) as $filename ) {
        wpnb39_integration_assert( is_wp_error( $ability->execute( array_replace( $input, array( 'filename' => $filename ) ) ) ), 'Invalid or executable filename was accepted.' );
    }
    foreach ( array( 'http_error', 'http_status', 'empty', 'oversized', 'truncated', 'invalid_type', 'revoke_http', 'revoke_sideload', 'sideload_error', 'insert_error', 'missing_staging' ) as $failure ) {
        $mode = $failure;
        update_option( Settings::OPTION_NAME, $enabled, false );
        add_filter( 'upload_size_limit', $small_limit );
        $staging = array(); $destinations = array();
        $result = $ability->execute( $input );
        remove_filter( 'upload_size_limit', $small_limit );
        wpnb39_integration_assert( is_wp_error( $result ), 'Import failure was not reported: ' . $failure );
        wpnb39_integration_assert( false === strpos( $result->get_error_message(), 'PRIVATE_MEDIA_MARKER' ), 'Failure leaked source/provider data.' );
        foreach ( array_merge( $staging, $destinations ) as $file ) {
            wpnb39_integration_assert( ! is_file( $file ), 'Import failure leaked a staging/destination file: ' . $failure );
        }
    }
    $mode = 'success';
    update_option( Settings::OPTION_NAME, $enabled, false );
    $parent = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'URL import test parent' ), true );
    wpnb39_integration_assert( ! is_wp_error( $parent ) && $parent > 0, 'Could not create parent fixture.' );
    foreach ( array( $source, 'https://wordpress.org/wpnb-media-import-fixture' ) as $url ) {
        $staging = array(); $destinations = array();
        $result = $ability->execute( array_replace( $input, array( 'url' => $url, 'post_id' => (int) $parent, 'title' => 'Imported title', 'description' => 'Description', 'caption' => 'Caption', 'alt_text' => '<b>Alternative</b>' ) ) );
        wpnb39_integration_assert( ! is_wp_error( $result ), 'Valid import failed native execution/schema/lifecycle.' );
        $created[] = $result['id'];
        wpnb39_integration_assert( 'attachment' === get_post_type( $result['id'] ) && is_file( get_attached_file( $result['id'] ) ), 'Import did not create a real attachment/file.' );
        wpnb39_integration_assert( (int) $parent === $result['parent_id'] && 'Imported title' === $result['title'] && 'Alternative' === $result['alt_text'], 'Normal attachment fields did not persist.' );
        wpnb39_integration_assert( 1 === $result['width'] && 1 === $result['height'], 'WordPress image metadata was not generated.' );
        wpnb39_integration_assert( false === strpos( wp_json_encode( $result ), 'PRIVATE_MEDIA_MARKER' ) && false === strpos( wp_json_encode( $result ), '/var/www/' ), 'Attachment result leaked a URL credential or server path.' );
        foreach ( $staging as $file ) { wpnb39_integration_assert( ! is_file( $file ), 'Successful import leaked staging.' ); }
    }
    // The fixture never relaxes URL/TLS policy; the real transfer uses an official public origin.
    remove_filter( 'pre_http_request', $mock, 10 );
    $result = $ability->execute( array( 'url' => 'https://s.w.org/images/wmark.png', 'filename' => 'wpnb-public-download.png' ) );
    wpnb39_integration_assert( ! is_wp_error( $result ), 'Actual public HTTPS media transfer failed.' );
    $created[] = $result['id'];
    wpnb39_integration_assert( is_file( get_attached_file( $result['id'] ) ) && $result['width'] > 0, 'Actual transfer did not persist usable media.' );
    $audit = wp_json_encode( get_option( Mutation_Log::OPTION_NAME ) );
    wpnb39_integration_assert( false === strpos( $audit, 'PRIVATE_MEDIA_MARKER' ) && false === strpos( $audit, 'wmark.png' ), 'Audit retained request URL or payload details.' );
    $adapter = wp_get_ability( 'mcp-adapter/execute-ability' );
    $disabled = $enabled; $disabled[ Settings::GROUP_REMOTE_MEDIA ] = 0;
    update_option( Settings::OPTION_NAME, $disabled, false );
    wpnb39_integration_assert( is_wp_error( $adapter->execute( array( 'ability_name' => 'wp-native-builder/media-import-url', 'parameters' => $input ) ) ), 'Adapter bypassed revoked import access.' );
    echo 'PASS: URL media import native authority, lifecycle, cleanup and HTTPS transfer (' . $GLOBALS['checks'] . " assertions).\n";
} finally {
    remove_filter( 'pre_http_request', $mock, 10 );
    remove_filter( 'wp_handle_sideload_prefilter', $reject_sideload );
    remove_filter( 'wp_handle_upload', $observe_upload, 10 );
    remove_filter( 'wp_insert_post_empty_content', $reject_insert, 10 );
    remove_filter( 'upload_size_limit', $small_limit );
    remove_filter( 'map_meta_cap', $deny_upload, 10 );
    wp_set_current_user( $original_user );
    foreach ( $created as $id ) { wp_delete_attachment( $id, true ); }
    if ( $parent && ! is_wp_error( $parent ) ) { wp_delete_post( $parent, true ); }
    foreach ( $staging as $file ) { if ( is_file( $file ) ) { wp_delete_file( $file ); } }
    update_option( Settings::OPTION_NAME, $original, false );
    update_option( Mutation_Log::OPTION_NAME, $original_log, false );
}
