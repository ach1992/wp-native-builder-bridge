<?php
/**
 * Validates the bundled Persian localization catalog.
 *
 * @package WP_Native_Builder_Bridge
 */

$root    = dirname( __DIR__ );
$catalog = $root . '/languages/wp-native-builder-bridge-fa_IR.l10n.php';

if ( ! is_file( $catalog ) ) {
	fwrite( STDERR, "ERROR: Persian localization catalog is missing.\n" );
	exit( 1 );
}

$data = require $catalog;
if ( ! is_array( $data ) || 'fa_IR' !== ( $data['language'] ?? '' ) || ! is_array( $data['messages'] ?? null ) ) {
	fwrite( STDERR, "ERROR: Persian localization catalog metadata is invalid.\n" );
	exit( 1 );
}

$messages = $data['messages'];
if ( 236 !== count( $messages ) ) {
	fwrite( STDERR, sprintf( "ERROR: expected 236 Persian messages, found %d.\n", count( $messages ) ) );
	exit( 1 );
}

foreach ( $messages as $source => $translation ) {
	if ( '' === trim( (string) $source ) || '' === trim( (string) $translation ) ) {
		fwrite( STDERR, "ERROR: Persian catalog contains an empty source or translation.\n" );
		exit( 1 );
	}
}

$required = array(
	'Direct ChatGPT App' => 'اتصال مستقیم به ChatGPT App',
	'Authorize ChatGPT'  => 'تأیید دسترسی ChatGPT',
	'Access groups'      => 'گروه‌های دسترسی',
	'Live Content'       => 'محتوای زنده',
	'Read Content'       => 'خواندن محتوا',
);
foreach ( $required as $source => $translation ) {
	if ( $translation !== ( $messages[ $source ] ?? null ) ) {
		fwrite( STDERR, 'ERROR: required Persian translation mismatch: ' . $source . "\n" );
		exit( 1 );
	}
}

echo "PASS: bundled Persian catalog contains 236 non-empty translations.\n";
