<?php
/**
 * smartEngin Licence Client (`self_`) – loader.
 *
 * Reusable, build-free client library for the smartEngin licence server
 * (REST namespace sels/v1). Drop this folder into any product plugin and
 * instantiate Self_Client once with a small config array:
 *
 *     require_once __DIR__ . '/lib/smartengin-licence-client/self-client.php';
 *     new Self_Client( array(
 *         'server_url'  => 'https://your-licence-server.example', // your Licence & buy server
 *         'slug'        => 'your-product-slug',   // = product slug on the server
 *         'plugin_file' => __FILE__,              // your plugin's main file
 *         'version'     => '1.0.0',               // your installed version
 *         'text_domain' => 'your-product-slug',
 *     ) );
 *
 * For a THEME, add 'type' => 'theme' and pass the theme's stylesheet + version
 * instead of a plugin file (call this from the theme's functions.php):
 *
 *     new Self_Client( array(
 *         'server_url' => 'https://your-licence-server.example',
 *         'slug'       => 'your-theme-slug',      // = product slug on the server
 *         'type'       => 'theme',
 *         'stylesheet' => get_stylesheet(),       // theme directory slug
 *         'version'    => wp_get_theme()->get( 'Version' ),
 *     ) );
 *
 * Gate premium features with $client->is_licensed() (since 0.8.0). It keeps an
 * expired ONE-OFF purchase working (only updates stop) and switches an expired
 * SUBSCRIPTION off after the grace days the server sends (3 on smartengin.de).
 * Do not read 'status' yourself for that: an 'active' licence whose date has
 * passed is expired, too.
 *
 * The library is deliberately generic: it never touches product internals, so
 * the same code serves every smartEngin product (plugin OR theme; and non-WP
 * software via the same REST API). All products should bundle the SAME library
 * version.
 *
 * @package SmartEnginLicenceClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bump when the library changes.
//
// Careful: when several products bundle this library on the same site, the copy
// that is loaded FIRST wins – there is no version comparison (class_exists()
// below simply skips every later copy, whatever its version). So all products
// must ship the SAME library version; an old copy in any one plugin silently
// downgrades the library for all of them.
if ( ! defined( 'SELF_CLIENT_VERSION' ) ) {
	define( 'SELF_CLIENT_VERSION', '0.8.3' );
}

if ( ! class_exists( 'Self_Client' ) ) {
	require_once __DIR__ . '/includes/class-self-client.php';
}

// Translations of the library's own texts (0.8.1). Before, no product shipped
// any for this text domain, so a German backend showed the library's messages
// in English. Registering the folder (as load_plugin_textdomain() does since
// WP 6.1) lets WordPress load the file just in time and follow
// switch_to_locale(). Deliberately OUTSIDE the class_exists() guard: the copy
// whose class wins may be an older one without a languages folder.
if ( is_dir( __DIR__ . '/languages' ) && isset( $GLOBALS['wp_textdomain_registry'] )
	&& is_object( $GLOBALS['wp_textdomain_registry'] )
	&& method_exists( $GLOBALS['wp_textdomain_registry'], 'set_custom_path' ) ) {
	$GLOBALS['wp_textdomain_registry']->set_custom_path( 'smartengin-licence-client', __DIR__ . '/languages' );
}
