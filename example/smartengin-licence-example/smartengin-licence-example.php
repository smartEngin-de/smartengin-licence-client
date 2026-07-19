<?php
/**
 * Plugin Name:       smartEngin Licence Example
 * Description:       Minimal, working reference for licensing a plugin with smartEngin Licence & buy. Fork it or read it next to the integration guide.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            smartEngin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       smartengin-licence-example
 *
 * This example shows the WHOLE integration:
 *   1. Load the client library and create ONE Self_Client instance (§4).
 *   2. Render the licence panel in the admin (§5).
 *   3. Gate a premium feature FAIL-OPEN via get_state() — policy B + C (§6).
 *   4. Updates flow automatically through the library's hooks (§7) — nothing to do.
 *
 * @package SmartEnginLicenceExample
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SLE_FILE', __FILE__ );
define( 'SLE_VERSION', '1.0.0' );

/**
 * The ONE place the licence-server address lives. When the server later moves to a
 * dedicated domain, change this single line — the whole plugin follows.
 */
if ( ! defined( 'SLE_LICENCE_SERVER' ) ) {
	define( 'SLE_LICENCE_SERVER', 'https://smartengin.de' );
}

/**
 * The product slug — must match the product registered on the licence server.
 */
if ( ! defined( 'SLE_SLUG' ) ) {
	define( 'SLE_SLUG', 'smartengin-licence-example' );
}

/*
 * 1) Load the library and create the single licence client instance.
 *    This registers the daily re-validation cron, the activate/deactivate AJAX
 *    handlers, and the WordPress update-integration filters — all automatically.
 */
require_once __DIR__ . '/lib/smartengin-licence-client/self-client.php';

/**
 * Return the shared licence client (created once).
 *
 * @return Self_Client
 */
function sle_licence() {
	static $client = null;
	if ( null === $client ) {
		$client = new Self_Client( array(
			'server_url'  => SLE_LICENCE_SERVER,
			'slug'        => SLE_SLUG,
			'plugin_file' => SLE_FILE,
			'version'     => SLE_VERSION,
			'text_domain' => 'smartengin-licence-example',
		) );
	}
	return $client;
}
sle_licence(); // Instantiate on load so all hooks are registered.

/**
 * 3) Is this site licensed enough to run premium features?
 *
 * FAIL-OPEN by design: reads only the cached state, which keeps the LAST KNOWN status
 * when the server is unreachable. 'expired' still counts as licensed — only updates and
 * support end, the software keeps working (policy C). A server outage must never switch
 * off every customer's site at once.
 *
 * @return bool
 */
function sle_is_licensed() {
	$state = sle_licence()->get_state();
	return in_array( (string) $state['status'], array( 'active', 'expired' ), true );
}

/*
 * A tiny "premium feature": an admin notice that only appears when licensed. In a real
 * plugin this is where you'd register your Pro blocks, endpoints, crons, etc. The free
 * core (everything outside this gate) always runs; nothing is deleted when a licence
 * lapses.
 */
add_action( 'admin_notices', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'settings_page_smartengin-licence-example' !== $screen->id ) {
		return;
	}
	if ( sle_is_licensed() ) {
		echo '<div class="notice notice-success"><p>'
			. esc_html__( 'Premium feature ACTIVE — this line only shows with a valid licence.', 'smartengin-licence-example' )
			. '</p></div>';
	} else {
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Premium feature OFF — activate a licence below to enable it. (The free core keeps working.)', 'smartengin-licence-example' )
			. '</p></div>';
	}
} );

/*
 * 2) The admin page with the licence panel.
 */
add_action( 'admin_menu', function () {
	add_options_page(
		__( 'smartEngin Licence Example', 'smartengin-licence-example' ),
		__( 'Licence Example', 'smartengin-licence-example' ),
		'manage_options',
		'smartengin-licence-example',
		'sle_render_admin_page'
	);
} );

/**
 * Render the settings page: status summary + the built-in licence panel.
 *
 * @return void
 */
function sle_render_admin_page() {
	$state    = sle_licence()->get_state();
	$licensed = sle_is_licensed() ? __( 'yes', 'smartengin-licence-example' ) : __( 'no', 'smartengin-licence-example' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'smartEngin Licence Example', 'smartengin-licence-example' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: 1: status slug, 2: yes/no */
				esc_html__( 'Current status: %1$s — premium features on: %2$s', 'smartengin-licence-example' ),
				'<code>' . esc_html( $state['status'] ? $state['status'] : 'none' ) . '</code>',
				'<strong>' . esc_html( $licensed ) . '</strong>'
			);
			?>
		</p>

		<?php
		// The whole licence UI — key field, Activate/Deactivate, colour-coded status.
		sle_licence()->render_panel( array( 'title' => __( 'Licence', 'smartengin-licence-example' ) ) );
		?>
	</div>
	<?php
}
