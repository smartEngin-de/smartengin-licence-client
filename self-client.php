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
 *         'server_url'  => 'https://lizenz.smartengin.de',
 *         'slug'        => 'smartengin-forms-pro',   // = product slug on the server
 *         'plugin_file' => SMARTENGIN_FORMS_PRO_FILE, // main plugin file (__FILE__)
 *         'version'     => SMARTENGIN_FORMS_PRO_VERSION,
 *         'text_domain' => 'smartengin-forms-pro',
 *     ) );
 *
 * The library is deliberately generic: it never touches product internals, so
 * the same code serves every smartEngin product (and later non-WP software via
 * the same REST API). All products should bundle the SAME library version.
 *
 * @package SmartEnginLicenceClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bump when the library changes; the highest version loaded across all plugins wins.
if ( ! defined( 'SELF_CLIENT_VERSION' ) ) {
	define( 'SELF_CLIENT_VERSION', '0.5.2' );
}

if ( ! class_exists( 'Self_Client' ) ) {
	require_once __DIR__ . '/includes/class-self-client.php';
}
