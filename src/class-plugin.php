<?php
/**
 * Main plugin bootstrap.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge;

use WP_Native_Builder_Bridge\Abilities\Registrar;
use WP_Native_Builder_Bridge\Admin\Settings_Page;
use WP_Native_Builder_Bridge\Support\Environment;
use WP_Native_Builder_Bridge\Support\Permissions;
use WP_Native_Builder_Bridge\Support\Settings;

/**
 * Wires the bridge services into WordPress.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Runtime dependency inspector.
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Bridge settings service.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Ability permission service.
	 *
	 * @var Permissions
	 */
	private $permissions;

	/**
	 * Ability registrar.
	 *
	 * @var Registrar
	 */
	private $registrar;

	/**
	 * Admin settings page.
	 *
	 * @var Settings_Page
	 */
	private $settings_page;

	/**
	 * Gets the plugin singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevents direct construction.
	 */
	private function __construct() {}

	/**
	 * Registers the plugin services and WordPress hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted        = true;
		$this->environment   = new Environment();
		$this->settings      = new Settings();
		$this->permissions   = new Permissions( $this->settings );
		$this->registrar     = new Registrar( $this->environment, $this->settings, $this->permissions );
		$this->settings_page = new Settings_Page( $this->environment, $this->settings );

		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this->settings_page, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_dependency_notices' ) );
		add_action( 'wp_abilities_api_categories_init', array( $this->registrar, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->registrar, 'register_abilities' ) );
	}

	/**
	 * Renders dependency notices for administrators.
	 *
	 * @return void
	 */
	public function render_dependency_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->environment->abilities_api_available() ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WP Native Builder Bridge requires WordPress 6.9 or newer with the Abilities API available.', 'wp-native-builder-bridge' )
			);
			return;
		}

		if ( ! $this->environment->mcp_adapter_available() ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p></div>',
				esc_html__( 'WP Native Builder Bridge is active, but the official WordPress MCP Adapter is not available.', 'wp-native-builder-bridge' ),
				esc_url( 'https://github.com/WordPress/mcp-adapter/releases/latest' ),
				esc_html__( 'Install or activate MCP Adapter', 'wp-native-builder-bridge' )
			);
		}
	}
}
