<?php
error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/wp/' );
define( 'WP_NATIVE_BUILDER_BRIDGE_VERSION', '0.1.0-dev' );

$GLOBALS['wp_version'] = '7.1';
$GLOBALS['wpnb_test']  = array(
	'options'              => array(),
	'capabilities'         => array( 'read' => true, 'manage_options' => true ),
	'user_id'              => 1,
	'registered_settings'  => array(),
	'registered_categories'=> array(),
	'registered_abilities' => array(),
	'actions'              => array(),
	'options_pages'        => array(),
	'settings_fields'      => array(),
);

function wpnb_test_reset_state() {
	$GLOBALS['wpnb_test']['options']               = array();
	$GLOBALS['wpnb_test']['capabilities']          = array( 'read' => true, 'manage_options' => true );
	$GLOBALS['wpnb_test']['registered_settings']   = array();
	$GLOBALS['wpnb_test']['registered_categories'] = array();
	$GLOBALS['wpnb_test']['registered_abilities']  = array();
	$GLOBALS['wpnb_test']['actions']               = array();
	$GLOBALS['wpnb_test']['options_pages']         = array();
	$GLOBALS['wpnb_test']['settings_fields']       = array();
}

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return (string) $url; }
function get_bloginfo( $show = '' ) { return '7.1'; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function current_user_can( $capability ) { return ! empty( $GLOBALS['wpnb_test']['capabilities'][ $capability ] ); }
function get_current_user_id() { return (int) $GLOBALS['wpnb_test']['user_id']; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['wpnb_test']['options'] ) ? $GLOBALS['wpnb_test']['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['wpnb_test']['options'][ $name ] = $value; return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['wpnb_test']['actions'][ $hook ][] = $callback; return true; }
function register_setting( $group, $name, $args = array() ) { $GLOBALS['wpnb_test']['registered_settings'][ $name ] = array( 'group' => $group, 'args' => $args ); }
function add_options_page( $page_title, $menu_title, $capability, $slug, $callback ) { $GLOBALS['wpnb_test']['options_pages'][ $slug ] = compact( 'page_title', 'menu_title', 'capability', 'slug', 'callback' ); return $slug; }
function settings_fields( $group ) { $GLOBALS['wpnb_test']['settings_fields'][] = $group; echo '<input type="hidden" name="_wpnonce" value="test">'; }
function checked( $checked, $current = true, $echo = true ) { $result = $checked == $current ? 'checked="checked"' : ''; if ( $echo ) { echo $result; } return $result; }
function submit_button() { echo '<button type="submit">Save Changes</button>'; }
function wp_die( $message ) { throw new RuntimeException( (string) $message ); }
function wp_register_ability_category( $slug, $args ) { $GLOBALS['wpnb_test']['registered_categories'][ $slug ] = $args; return (object) array( 'slug' => $slug ); }
function wp_register_ability( $name, $args ) { $GLOBALS['wpnb_test']['registered_abilities'][ $name ] = $args; return (object) array( 'name' => $name ); }

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WP_Native_Builder_Bridge\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$relative   = substr( $class, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_file = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$directory  = $parts ? implode( '/', $parts ) . '/' : '';
		$path       = dirname( __DIR__ ) . '/src/' . $directory . $class_file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
