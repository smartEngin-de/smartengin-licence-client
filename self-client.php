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

// Bump when the library changes; the highest version loaded across all plugins wins.
if ( ! defined( 'SELF_CLIENT_VERSION' ) ) {
	define( 'SELF_CLIENT_VERSION', '0.6.0' );
}

if ( ! class_exists( 'Self_Client' ) ) {
	require_once __DIR__ . '/includes/class-self-client.php';
}
