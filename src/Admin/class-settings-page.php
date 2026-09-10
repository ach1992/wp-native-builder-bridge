<?php
/**
 * Admin settings page.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Admin;

use WP_Native_Builder_Bridge\Auth\OAuth_Server;
use WP_Native_Builder_Bridge\Support\Environment;
use WP_Native_Builder_Bridge\Support\Settings;

/**
 * Renders bridge status and grouped access controls.
 */
final class Settings_Page {
	const PAGE_SLUG = 'wp-native-builder';

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
	 * Direct ChatGPT OAuth/MCP service.
	 *
	 * @var OAuth_Server
	 */
	private $oauth_server;

	/**
	 * Creates the settings page.
	 *
	 * @param Environment       $environment  Runtime dependency inspector.
	 * @param Settings          $settings     Bridge settings service.
	 * @param OAuth_Server|null $oauth_server Optional direct ChatGPT OAuth/MCP service.
	 */
	public function __construct( Environment $environment, Settings $settings, ?OAuth_Server $oauth_server = null ) {
		$this->environment  = $environment;
		$this->settings     = $settings;
		$this->oauth_server = $oauth_server ? $oauth_server : new OAuth_Server();
	}

	/**
	 * Registers the settings page under Settings.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'WP Native Builder', 'wp-native-builder-bridge' ),
			__( 'WP Native Builder', 'wp-native-builder-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP Native Builder Bridge settings.', 'wp-native-builder-bridge' ) );
		}

		$values          = $this->settings->all();
		$mcp_available   = $this->environment->mcp_adapter_available();
		$https_ready     = $this->oauth_server->is_https_ready();
		$direct_endpoint = $this->oauth_server->mcp_endpoint_url();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Native Builder', 'wp-native-builder-bridge' ); ?></h1>

			<h2><?php echo esc_html__( 'Direct ChatGPT App', 'wp-native-builder-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'Use the direct HTTPS MCP endpoint below when creating a custom App in a ChatGPT workspace with Developer Mode enabled. ChatGPT will open this WordPress site for OAuth login and consent; no tunnel or separate proxy service is required.', 'wp-native-builder-bridge' ); ?></p>
			<table class="widefat striped" style="max-width: 900px">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'WordPress', 'wp-native-builder-bridge' ); ?></th>
						<td><?php echo esc_html( $this->environment->wordpress_version() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Abilities API', 'wp-native-builder-bridge' ); ?></th>
						<td><?php echo $this->environment->abilities_api_available() ? esc_html__( 'Available', 'wp-native-builder-bridge' ) : esc_html__( 'Unavailable', 'wp-native-builder-bridge' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'MCP Adapter', 'wp-native-builder-bridge' ); ?></th>
						<td>
							<?php
							if ( $mcp_available ) {
								$version = $this->environment->mcp_adapter_version();
								echo esc_html(
									$version ? sprintf(
										/* translators: %s: MCP Adapter version. */
										__( 'Available (%s)', 'wp-native-builder-bridge' ),
										$version
									) : __( 'Available', 'wp-native-builder-bridge' )
								);
							} else {
								echo esc_html__( 'Unavailable', 'wp-native-builder-bridge' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Public HTTPS', 'wp-native-builder-bridge' ); ?></th>
						<td><?php echo $https_ready ? esc_html__( 'Ready', 'wp-native-builder-bridge' ) : esc_html__( 'Not ready — endpoint is not HTTPS', 'wp-native-builder-bridge' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'App MCP endpoint', 'wp-native-builder-bridge' ); ?></th>
						<td><code><?php echo esc_html( $direct_endpoint ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'OAuth protected-resource metadata', 'wp-native-builder-bridge' ); ?></th>
						<td><code><?php echo esc_html( $this->oauth_server->protected_resource_metadata_url() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'OAuth authorization-server metadata', 'wp-native-builder-bridge' ); ?></th>
						<td><code><?php echo esc_html( $this->oauth_server->authorization_server_metadata_url() ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $mcp_available && $https_ready ) : ?>
				<p><strong><?php echo esc_html__( 'ChatGPT setup:', 'wp-native-builder-bridge' ); ?></strong> <?php echo esc_html__( 'In Workspace settings, open Apps, create a custom App, enter the App MCP endpoint above, choose OAuth, and run Scan Tools. Sign in to WordPress in the browser window and approve the connection.', 'wp-native-builder-bridge' ); ?></p>
			<?php else : ?>
				<p><strong><?php echo esc_html__( 'Connection is not ready yet.', 'wp-native-builder-bridge' ); ?></strong> <?php echo esc_html__( 'The official MCP Adapter must be active and the WordPress REST URL must be publicly reachable over HTTPS before ChatGPT can create this App.', 'wp-native-builder-bridge' ); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Access groups', 'wp-native-builder-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'An enabled group only makes matching bridge abilities technically available. The connected WordPress user must also have the required WordPress capabilities. OAuth authorization does not bypass these checks.', 'wp-native-builder-bridge' ); ?></p>

			<form action="options.php" method="post">
				<?php settings_fields( Settings::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $this->settings->groups() as $key => $group ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $group['label'] ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $values[ $key ] ) ); ?>>
									<?php echo esc_html__( 'Enabled', 'wp-native-builder-bridge' ); ?>
								</label>
								<p class="description"><?php echo esc_html( $group['description'] ); ?></p>
								<?php if ( ! empty( $group['warning'] ) ) : ?>
									<p class="description"><strong><?php echo esc_html__( 'Warning:', 'wp-native-builder-bridge' ); ?></strong> <?php echo esc_html__( 'Enable only when you intend to expose this class of changes to authenticated MCP clients.', 'wp-native-builder-bridge' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
