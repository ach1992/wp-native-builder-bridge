<?php
/**
 * Real WordPress runtime smoke for the bundled Persian translation catalog.
 *
 * @package WP_Native_Builder_Bridge
 */

function wpnb_issue6_i18n_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$domain        = 'wp-native-builder-bridge';
$language_file = dirname( WP_NATIVE_BUILDER_BRIDGE_FILE ) . '/languages/wp-native-builder-bridge-fa_IR.l10n.php';

wpnb_issue6_i18n_assert( file_exists( $language_file ), 'Bundled fa_IR runtime catalog is missing.' );

unload_textdomain( $domain );
$loaded = load_textdomain( $domain, $language_file, 'fa_IR' );
wpnb_issue6_i18n_assert( true === $loaded, 'WordPress could not load the bundled fa_IR runtime catalog.' );

wpnb_issue6_i18n_assert(
	'اتصال مستقیم به ChatGPT App' === __( 'Direct ChatGPT App', $domain ),
	'Persian admin connection heading did not load at runtime.'
);
wpnb_issue6_i18n_assert(
	'گروه‌های دسترسی' === __( 'Access groups', $domain ),
	'Persian access-group heading did not load at runtime.'
);
wpnb_issue6_i18n_assert(
	'محتوای زنده' === __( 'Live Content', $domain ),
	'Persian access-group label did not load at runtime.'
);
wpnb_issue6_i18n_assert(
	'تأیید دسترسی ChatGPT' === __( 'Authorize ChatGPT', $domain ),
	'Persian OAuth consent action did not load at runtime.'
);
wpnb_issue6_i18n_assert(
	'خطای احراز مجوز OAuth' === __( 'OAuth authorization error', $domain ),
	'Persian OAuth error heading did not load at runtime.'
);

unload_textdomain( $domain );

echo "PASS: Issue #6 bundled fa_IR runtime translations.\n";
