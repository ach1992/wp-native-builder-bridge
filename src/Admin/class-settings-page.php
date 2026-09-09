<?php
/**
 * Admin settings page.
 *
 * @package WP_Native_Builder_Bridge
 */

namespace WP_Native_Builder_Bridge\Admin;

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
	 * Creates the settings page.
	 *
	 * @param Environment $environment Runtime dependency inspector.
	 * @param Settings    $settings    Bridge settings service.
	 */
	public function __construct( Environment $environment, Settings $settings ) {
		$this->environment = $environment;
		$this->settings    = $settings;
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

		$values = $this->settings->all();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Native Builder', 'wp-native-builder-bridge' ); ?></h1>

			<h2><?php echo esc_html__( 'Connection status', 'wp-native-builder-bridge' ); ?></h2>
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
							if ( $this->environment->mcp_adapter_available() ) {
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
					<?php if ( $this->environment->mcp_adapter_available() ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Default MCP endpoint', 'wp-native-builder-bridge' ); ?></th>
							<td><code><?php echo esc_html( $this->environment->default_mcp_endpoint() ); ?></code></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Access groups', 'wp-native-builder-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'An enabled group only makes matching bridge abilities technically available. WordPress user capabilities are still required.', 'wp-native-builder-bridge' ); ?></p>

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
