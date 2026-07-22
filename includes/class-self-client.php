<?php
/**
 * Self_Client – smartEngin licence client for one product.
 *
 * Talks to the smartEngin licence server (REST sels/v1): activate, deactivate,
 * periodic validate, and the WordPress plugin-update integration. Fault
 * tolerant by design – if the server is unreachable the last known status is
 * kept and updates simply do not appear (Kein-Bricking, G7). Never blocks the
 * host plugin.
 *
 * @package SmartEnginLicenceClient
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One instance per licensed product.
 */
class Self_Client {

	/** Fixed text domain for the library's own UI strings. */
	const TD = 'smartengin-licence-client';

	/** @var string Licence server base URL (no trailing slash needed). */
	private $server_url;

	/** @var string Product slug – must match the server product registry. */
	private $slug;

	/** @var string Absolute path to the host plugin's main file. */
	private $plugin_file;

	/** @var string plugin_basename() of the host plugin. */
	private $basename;

	/** @var string Installed version of the host plugin/theme. */
	private $version;

	/** @var string Product type: 'plugin' (default) or 'theme'. */
	private $type = 'plugin';

	/** @var string Theme stylesheet (directory) slug when type is 'theme'. */
	private $stylesheet = '';

	/** @var string wp_options key holding the licence state. */
	private $option_name;

	/** @var string Daily cron hook name. */
	private $cron_hook;

	/** @var string Legacy transient key (kept only to clean up old caches). */
	private $update_cache_key;

	/** @var array|null Per-request memo of the /update result (not persisted). */
	private $update_memo = null;

	/**
	 * @param array $config server_url, slug, plugin_file, version[, option_name].
	 */
	public function __construct( array $config ) {
		$this->server_url = isset( $config['server_url'] ) ? untrailingslashit( (string) $config['server_url'] ) : '';
		$this->slug       = isset( $config['slug'] ) ? sanitize_key( $config['slug'] ) : '';
		$this->version    = isset( $config['version'] ) ? (string) $config['version'] : '';
		$this->type       = ( isset( $config['type'] ) && 'theme' === $config['type'] ) ? 'theme' : 'plugin';

		if ( 'theme' === $this->type ) {
			// A theme identifies itself by its stylesheet (directory) slug.
			$this->stylesheet = ( isset( $config['stylesheet'] ) && '' !== (string) $config['stylesheet'] )
				? (string) $config['stylesheet']
				: ( function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '' );
			$this->basename = $this->stylesheet; // Identity key used throughout.
		} else {
			$this->plugin_file = isset( $config['plugin_file'] ) ? (string) $config['plugin_file'] : '';
			$this->basename    = $this->plugin_file ? plugin_basename( $this->plugin_file ) : '';
		}

		$base                   = str_replace( '-', '_', $this->slug );
		$this->option_name      = isset( $config['option_name'] ) ? (string) $config['option_name'] : $base . '_license';
		$this->cron_hook        = $base . '_self_validate';
		$this->update_cache_key = $base . '_self_update';

		if ( '' === $this->slug || '' === $this->server_url ) {
			return; // Misconfigured – stay inert rather than error.
		}
		if ( 'theme' === $this->type && '' === $this->stylesheet ) {
			return; // Theme misconfigured (no stylesheet) – stay inert.
		}

		// AJAX (backend buttons).
		add_action( 'wp_ajax_' . $this->slug . '_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_' . $this->slug . '_deactivate', array( $this, 'ajax_deactivate' ) );

		// Daily re-validation via WP-Cron.
		add_action( $this->cron_hook, array( $this, 'cron_validate' ) );
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $this->cron_hook );
		}

		// WordPress update integration (C2) – plugin OR theme update channel.
		if ( 'theme' === $this->type ) {
			add_filter( 'pre_set_site_transient_update_themes', array( $this, 'filter_update_themes' ) );
			add_filter( 'themes_api', array( $this, 'themes_api' ), 10, 3 );
			// Themes have no deactivation hook; clean cron when the theme is switched away.
			add_action( 'switch_theme', array( $this, 'on_plugin_deactivate' ) );
		} else {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
			add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
			// Self-cleanup when the host plugin is deactivated.
			if ( $this->basename ) {
				register_deactivation_hook( $this->plugin_file, array( $this, 'on_plugin_deactivate' ) );
			}
		}
		add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_update_cache' ), 10, 0 );
	}

	/* ======================================================================
	 * State (wp_options)
	 * ==================================================================== */

	/**
	 * Current licence state with defaults.
	 *
	 * @return array
	 */
	public function get_state() {
		$defaults = array(
			'key'              => '',
			'activated'        => false, // Did this site register successfully?
			'status'           => '',    // active|expired|refunded|disabled|unknown|''
			'valid'            => false, // Entitled to updates (last known).
			'valid_until'      => null,
			'activations_left' => null,
			'last_check'       => 0,
		);
		return wp_parse_args( (array) get_option( $this->option_name, array() ), $defaults );
	}

	/**
	 * Persist state (non-autoloaded).
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function set_state( array $state ) {
		update_option( $this->option_name, $state, false );
	}

	/* ======================================================================
	 * Identity + transport
	 * ==================================================================== */

	/**
	 * Normalized activation identifier for this site (domain, matching the
	 * server-side normalization: no scheme, no www, lower-case host).
	 *
	 * @return string
	 */
	public function instance() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '#^www\.#i', '', (string) $host );
		return strtolower( $host );
	}

	/**
	 * Call a licence-server endpoint.
	 *
	 * @param string $endpoint activate|deactivate|validate|update.
	 * @param array  $params   Extra parameters (key, version, …).
	 * @param string $method   POST|GET.
	 * @return array|WP_Error [ 'code' => int, 'data' => array ] or transport WP_Error.
	 */
	private function call( $endpoint, array $params, $method = 'POST' ) {
		$url  = $this->server_url . '/wp-json/sels/v1/' . $endpoint;
		$body = array_merge(
			array(
				'product'       => $this->slug,
				'instance'      => $this->instance(),
				'instance_type' => 'domain',
			),
			$params
		);

		$args = array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array( 'Accept' => 'application/json' ),
		);

		if ( 'GET' === $method ) {
			$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $body ), $url ), $args );
		} else {
			$args['body'] = $body;
			$response     = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		// Strip a leading UTF-8 BOM before decoding. A stray BOM in any PHP file
		// on the server (e.g. a plugin main file saved as UTF-8 *with* BOM) is
		// echoed before the JSON body; without this, json_decode() fails, the
		// response silently degrades to empty data and updates would never appear
		// (the update check would always read "no update").
		if ( 0 === strncmp( $raw, "\xEF\xBB\xBF", 3 ) ) {
			$raw = substr( $raw, 3 );
		}
		$data = json_decode( trim( $raw ), true );
		return array( 'code' => $code, 'data' => is_array( $data ) ? $data : array() );
	}

	/* ======================================================================
	 * Activate / deactivate / validate
	 * ==================================================================== */

	/**
	 * Derive update entitlement from status + expiry (mirrors server B6).
	 *
	 * @param string      $status      Licence status.
	 * @param string|null $valid_until MySQL datetime or null (=lifetime).
	 * @return bool
	 */
	private function derive_valid( $status, $valid_until ) {
		if ( 'active' !== $status ) {
			return false;
		}
		if ( empty( $valid_until ) ) {
			return true;
		}
		return strtotime( $valid_until ) > time();
	}

	/**
	 * Activate this site with the given key.
	 *
	 * @param string $key Licence key.
	 * @return true|WP_Error
	 */
	public function activate( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return new WP_Error( 'no_key', __( 'Please enter a licence key.', 'smartengin-licence-client' ) );
		}

		$res = $this->call( 'activate', array( 'key' => $key ) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'server_unreachable', __( 'The licence server could not be reached. Please try again.', 'smartengin-licence-client' ) );
		}

		$data  = $res['data'];
		$state = $this->get_state();
		$state['key'] = $key;

		if ( 200 === $res['code'] && ! empty( $data['success'] ) ) {
			$state['activated']        = true;
			$state['status']           = isset( $data['status'] ) ? (string) $data['status'] : 'active';
			$state['valid_until']      = isset( $data['valid_until'] ) ? $data['valid_until'] : null;
			$state['activations_left'] = isset( $data['activations_left'] ) ? $data['activations_left'] : null;
			$state['valid']            = $this->derive_valid( $state['status'], $state['valid_until'] );
			$state['last_check']       = time();
			$this->set_state( $state );
			$this->flush_update_cache();
			return true;
		}

		// Keep the entered key but mark inactive; surface the server message.
		$state['activated'] = false;
		$state['valid']     = false;
		$this->set_state( $state );

		$slug = isset( $data['error'] ) ? (string) $data['error'] : 'error';
		$msg  = isset( $data['message'] ) ? (string) $data['message'] : __( 'Activation failed.', 'smartengin-licence-client' );
		return new WP_Error( $slug, $msg );
	}

	/**
	 * Deactivate this site (frees the activation slot on the server).
	 *
	 * @return true|WP_Error
	 */
	public function deactivate() {
		$state = $this->get_state();
		$key   = (string) $state['key'];

		if ( '' !== $key ) {
			$res = $this->call( 'deactivate', array( 'key' => $key ) );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'server_unreachable', __( 'The licence server could not be reached. Please try again.', 'smartengin-licence-client' ) );
			}
		}

		$state['activated']        = false;
		$state['valid']            = false;
		$state['status']           = '';
		$state['valid_until']      = null;
		$state['activations_left'] = null;
		$this->set_state( $state );
		$this->flush_update_cache();
		return true;
	}

	/**
	 * Re-validate against the server. Fault tolerant: on any transport error or
	 * non-200 the stored status is left untouched (Kein-Bricking).
	 *
	 * @return void
	 */
	public function cron_validate() {
		$state = $this->get_state();
		$key   = (string) $state['key'];
		if ( '' === $key || empty( $state['activated'] ) ) {
			return;
		}

		$res = $this->call( 'validate', array( 'key' => $key ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return; // Keep last known good state.
		}

		$data = $res['data'];
		$state['status']           = isset( $data['status'] ) ? (string) $data['status'] : $state['status'];
		$state['valid']            = ! empty( $data['valid'] );
		$state['valid_until']      = array_key_exists( 'valid_until', $data ) ? $data['valid_until'] : $state['valid_until'];
		$state['activations_left'] = array_key_exists( 'activations_left', $data ) ? $data['activations_left'] : $state['activations_left'];
		$state['last_check']       = time();
		$this->set_state( $state );
	}

	/* ======================================================================
	 * WordPress update integration (C2)
	 * ==================================================================== */

	/**
	 * Ask the server whether an update is available. Returns a normalized array
	 * with at least [ 'update' => bool ].
	 *
	 * The result is memoized only for the current request (not persisted), so it
	 * is always fresh whenever WordPress runs its update check — matching how
	 * WordPress re-checks plugins (≈ every minute on the Updates screen, hourly
	 * on the Plugins screen, ~twice daily via cron). A stale long-lived cache
	 * would otherwise hide a new release for hours even after "Check again".
	 *
	 * @param bool $force Ignore the per-request memo and re-ask the server.
	 * @return array
	 */
	public function check_update( $force = false ) {
		if ( ! $force && is_array( $this->update_memo ) ) {
			return $this->update_memo;
		}

		$state = $this->get_state();
		$key   = (string) $state['key'];
		if ( '' === $key || empty( $state['activated'] ) ) {
			$this->update_memo = array( 'update' => false );
			return $this->update_memo;
		}

		$res = $this->call( 'update', array( 'key' => $key, 'version' => $this->version ), 'GET' );
		if ( is_wp_error( $res ) || 200 !== $res['code'] || empty( $res['data']['update'] ) ) {
			// No-bricking: on any error just report "no update" (the plugin keeps
			// running); the next check picks it up once the server is reachable.
			$this->update_memo = array( 'update' => false );
			return $this->update_memo;
		}

		$this->update_memo = $res['data'];
		return $this->update_memo;
	}

	/**
	 * Reset the per-request memo (and clean up any legacy persisted cache).
	 *
	 * @return void
	 */
	public function flush_update_cache() {
		$this->update_memo = null;
		delete_transient( $this->update_cache_key );
	}

	/**
	 * Inject our update into the plugin update transient.
	 *
	 * @param mixed $transient Update transient (object) or empty.
	 * @return mixed
	 */
	public function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$info = $this->check_update();

		// The version WordPress currently sees installed (from its freshly rebuilt
		// "checked" list on disk). Prefer it over $this->version: right after an
		// update, the version constant captured at the start of the request can
		// still hold the OLD number, which would make us falsely re-offer the very
		// update we just applied until the next page load.
		$installed = ! empty( $transient->checked[ $this->basename ] )
			? (string) $transient->checked[ $this->basename ]
			: $this->version;

		$item = new stdClass();
		$item->slug        = $this->slug;
		$item->plugin      = $this->basename;
		$item->new_version = $installed;
		$item->url         = '';
		$item->package     = '';

		if ( ! empty( $info['update'] ) && version_compare( $installed, (string) $info['new_version'], '<' ) ) {
			$item->new_version  = (string) $info['new_version'];
			$item->url          = isset( $info['homepage_url'] ) ? (string) $info['homepage_url'] : '';
			$item->package      = isset( $info['package'] ) ? (string) $info['package'] : '';
			$item->tested       = isset( $info['tested'] ) ? (string) $info['tested'] : '';
			$item->requires     = isset( $info['requires'] ) ? (string) $info['requires'] : '';
			$item->requires_php = isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '';
			$item->icons        = array();
			$item->banners      = array();
			$transient->response[ $this->basename ] = $item;
		} else {
			// Signals "up to date" so WordPress shows no false update.
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Provide the "View details" popup data.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Query args.
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$info    = $this->check_update();
		$version = ! empty( $info['update'] ) ? (string) $info['new_version'] : $this->version;

		$data = get_plugin_data( $this->plugin_file, false, false );

		$obj               = new stdClass();
		$obj->name         = isset( $data['Name'] ) ? $data['Name'] : $this->slug;
		$obj->slug         = $this->slug;
		$obj->version      = $version;
		$obj->author       = isset( $data['Author'] ) ? $data['Author'] : '';
		$obj->homepage     = isset( $info['homepage_url'] ) ? (string) $info['homepage_url'] : ( isset( $data['PluginURI'] ) ? $data['PluginURI'] : '' );
		$obj->requires     = isset( $info['requires'] ) ? (string) $info['requires'] : '';
		$obj->tested       = isset( $info['tested'] ) ? (string) $info['tested'] : '';
		$obj->requires_php = isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '';
		$obj->download_link = isset( $info['package'] ) ? (string) $info['package'] : '';

		$changelog = isset( $info['changelog_url'] ) && $info['changelog_url']
			? sprintf(
				/* translators: %s: changelog URL */
				__( 'See the full changelog: %s', 'smartengin-licence-client' ),
				'<a href="' . esc_url( $info['changelog_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $info['changelog_url'] ) . '</a>'
			)
			: __( 'No changelog available.', 'smartengin-licence-client' );

		$obj->sections = array(
			'description' => isset( $data['Description'] ) ? $data['Description'] : '',
			'changelog'   => $changelog,
		);

		return $obj;
	}

	/**
	 * Inject our update into the THEME update transient. Theme entries are arrays
	 * (not objects) keyed by the stylesheet slug — this is the theme equivalent of
	 * filter_update_plugins().
	 *
	 * @param mixed $transient Update transient (object) or empty.
	 * @return mixed
	 */
	public function filter_update_themes( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$info = $this->check_update();

		// Version WordPress currently sees installed (prefer it over the captured
		// constant, which can still hold the OLD number right after an update).
		$installed = ! empty( $transient->checked[ $this->stylesheet ] )
			? (string) $transient->checked[ $this->stylesheet ]
			: $this->version;

		if ( ! empty( $info['update'] ) && version_compare( $installed, (string) $info['new_version'], '<' ) ) {
			$transient->response[ $this->stylesheet ] = array(
				'theme'        => $this->stylesheet,
				'new_version'  => (string) $info['new_version'],
				'url'          => isset( $info['homepage_url'] ) ? (string) $info['homepage_url'] : '',
				'package'      => isset( $info['package'] ) ? (string) $info['package'] : '',
				'requires'     => isset( $info['requires'] ) ? (string) $info['requires'] : '',
				'requires_php' => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
			);
			unset( $transient->no_update[ $this->stylesheet ] );
		} else {
			// Signals "up to date" so WordPress shows no false update.
			$transient->no_update[ $this->stylesheet ] = array(
				'theme'       => $this->stylesheet,
				'new_version' => $installed,
				'url'         => '',
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Provide the theme "View version details" popup data (theme_information).
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Query args.
	 * @return mixed
	 */
	public function themes_api( $result, $action, $args ) {
		if ( 'theme_information' !== $action || empty( $args->slug ) || $args->slug !== $this->stylesheet ) {
			return $result;
		}

		$info    = $this->check_update();
		$version = ! empty( $info['update'] ) ? (string) $info['new_version'] : $this->version;
		$theme   = function_exists( 'wp_get_theme' ) ? wp_get_theme( $this->stylesheet ) : null;
		$exists  = ( $theme && $theme->exists() );

		$changelog = isset( $info['changelog_url'] ) && $info['changelog_url']
			? sprintf(
				/* translators: %s: changelog URL */
				__( 'See the full changelog: %s', 'smartengin-licence-client' ),
				'<a href="' . esc_url( $info['changelog_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $info['changelog_url'] ) . '</a>'
			)
			: __( 'No changelog available.', 'smartengin-licence-client' );

		$obj                = new stdClass();
		$obj->name          = $exists ? $theme->get( 'Name' ) : $this->slug;
		$obj->slug          = $this->stylesheet;
		$obj->version       = $version;
		$obj->author        = $exists ? wp_strip_all_tags( $theme->get( 'Author' ) ) : '';
		$obj->requires      = isset( $info['requires'] ) ? (string) $info['requires'] : '';
		$obj->requires_php  = isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '';
		$obj->download_link = isset( $info['package'] ) ? (string) $info['package'] : '';
		$obj->sections      = array(
			'description' => $exists ? $theme->get( 'Description' ) : '',
			'changelog'   => $changelog,
		);

		return $obj;
	}

	/**
	 * Verify the downloaded update against the server-provided SHA-256 (E9).
	 *
	 * Hooked on `upgrader_pre_download`. Only acts on this product's own update
	 * and only when the server supplied a checksum. Downloads the package,
	 * compares the hash, and hands WordPress a verified local file – or aborts
	 * with an error if the checksum does not match (protects against corrupted
	 * or tampered packages). If no checksum is available it stays out of the way
	 * and lets WordPress download normally.
	 *
	 * @param mixed  $reply      Default false (let WP download).
	 * @param string $package    Package URL.
	 * @param object $upgrader   WP_Upgrader instance.
	 * @param array  $hook_extra Extra data (contains 'plugin' for plugin updates).
	 * @return mixed False, a local file path, or WP_Error.
	 */
	public function verify_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		// Only act on THIS product's own update (plugin key or theme key).
		$mine = ( 'theme' === $this->type )
			? ( ! empty( $hook_extra['theme'] ) && $hook_extra['theme'] === $this->stylesheet )
			: ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->basename );
		if ( ! $mine ) {
			return $reply;
		}

		// Fetch a FRESH signed link at the moment of installing. The package URL
		// WordPress cached in its update list can be older than the short token
		// lifetime; re-asking the server guarantees a valid, unexpired link.
		$info = $this->check_update( true );

		// Prefer the freshly signed URL; fall back to the one WordPress passed.
		$download_from = ! empty( $info['package'] ) ? (string) $info['package'] : (string) $package;

		$expected = isset( $info['sha256'] ) ? strtolower( trim( (string) $info['sha256'] ) ) : '';
		if ( '' === $expected ) {
			return $reply; // No checksum provided – optional check, download normally.
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $download_from );
		if ( is_wp_error( $file ) ) {
			return $this->explain_download_error( $file );
		}

		$actual = strtolower( (string) hash_file( 'sha256', $file ) );
		if ( ! hash_equals( $expected, $actual ) ) {
			wp_delete_file( $file );
			return new WP_Error(
				'sels_checksum',
				__( 'The downloaded update failed its integrity check (SHA-256 mismatch). The update was aborted to protect your site.', 'smartengin-licence-client' )
			);
		}

		return $file; // Verified – WordPress installs from this local file.
	}

	/**
	 * Turn a raw download failure into a clearer message. In particular, when the
	 * licence server runs on the SAME site as this plugin, WordPress has to fetch
	 * the package from the site to itself during the update; many hosts block that
	 * self-request (typically a "503 Service Unavailable"), so the automatic
	 * install cannot complete even though the new version is ready. In that case we
	 * explain it and point to a manual install, instead of the cryptic default.
	 *
	 * @param WP_Error $error The download error.
	 * @return WP_Error
	 */
	private function explain_download_error( $error ) {
		$server_host = strtolower( (string) wp_parse_url( $this->server_url, PHP_URL_HOST ) );
		$site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' !== $server_host && $server_host === $site_host ) {
			return new WP_Error(
				'sels_samesite_download',
				sprintf(
					/* translators: 1: site host, 2: underlying error message. */
					__( 'The update could not be installed automatically because the licence server runs on this same site (%1$s): during an update WordPress has to download the package from the site to itself, and your host blocked that self-request (“503 Service Unavailable”). The new version is available — please install it manually via Plugins → Add New → Upload Plugin, or run the licence server on a separate domain. (Technical detail: %2$s)', 'smartengin-licence-client' ),
					$site_host,
					$error->get_error_message()
				)
			);
		}

		return $error;
	}

	/* ======================================================================
	 * AJAX (backend buttons)
	 * ==================================================================== */

	/**
	 * Shared AJAX guard: capability + nonce.
	 *
	 * @return void Dies with JSON on failure.
	 */
	private function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'smartengin-licence-client' ) ), 403 );
		}
		check_ajax_referer( $this->slug . '_license', 'nonce' );
	}

	/**
	 * AJAX: activate.
	 *
	 * @return void
	 */
	public function ajax_activate() {
		$this->verify_ajax();
		$key    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$result = $this->activate( $key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'html' => $this->status_html() ) );
	}

	/**
	 * AJAX: deactivate.
	 *
	 * @return void
	 */
	public function ajax_deactivate() {
		$this->verify_ajax();
		$result = $this->deactivate();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'html' => $this->status_html() ) );
	}

	/* ======================================================================
	 * Backend panel (host plugin renders this wherever it wants)
	 * ==================================================================== */

	/**
	 * Print the small panel stylesheet once per request. Colours the status so
	 * an active licence stands out at a glance (green), with amber for expired
	 * and red for inactive/unknown.
	 *
	 * @return void
	 */
	private function print_panel_styles() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			.self-license-panel .self-status { display:inline-block; padding:3px 12px; border-radius:14px; font-weight:600; line-height:1.6; }
			.self-license-panel .self-status--active   { background:#e6f4ea; color:#137333; }
			.self-license-panel .self-status--expired  { background:#fef7e0; color:#8a5a00; }
			.self-license-panel .self-status--inactive { background:#fce8e6; color:#b3261e; }
		</style>
		<?php
	}

	/**
	 * Human-readable status line HTML.
	 *
	 * @return string
	 */
	public function status_html() {
		$state = $this->get_state();

		if ( empty( $state['activated'] ) ) {
			return '<span class="self-status self-status--inactive">' . esc_html__( 'Not activated.', 'smartengin-licence-client' ) . '</span>';
		}

		switch ( (string) $state['status'] ) {
			case 'active':
				if ( empty( $state['valid_until'] ) ) {
					$txt = __( 'Active — lifetime licence.', 'smartengin-licence-client' );
				} else {
					$txt = sprintf(
						/* translators: %s: expiry date */
						__( 'Active — valid until %s.', 'smartengin-licence-client' ),
						date_i18n( get_option( 'date_format' ), strtotime( $state['valid_until'] ) )
					);
				}
				return '<span class="self-status self-status--active">' . esc_html( $txt ) . '</span>';

			case 'expired':
				return '<span class="self-status self-status--expired">' . esc_html__( 'Expired — the plugin keeps working, but updates are paused. Please renew.', 'smartengin-licence-client' ) . '</span>';

			case 'refunded':
			case 'disabled':
				return '<span class="self-status self-status--inactive">' . esc_html__( 'This licence is no longer active.', 'smartengin-licence-client' ) . '</span>';

			case 'unknown':
				return '<span class="self-status self-status--inactive">' . esc_html__( 'This licence key was not recognised.', 'smartengin-licence-client' ) . '</span>';

			default:
				return '<span class="self-status">' . esc_html__( 'Status unknown.', 'smartengin-licence-client' ) . '</span>';
		}
	}

	/**
	 * Render the licence panel (key field + activate/deactivate + status).
	 * Call this from anywhere in the host plugin's admin UI.
	 *
	 * @param array $args Optional: 'title'.
	 * @return void Echoes markup.
	 */
	public function render_panel( array $args = array() ) {
		$state     = $this->get_state();
		$activated = ! empty( $state['activated'] );
		$title     = isset( $args['title'] ) ? (string) $args['title'] : __( 'Licence', 'smartengin-licence-client' );
		$nonce     = wp_create_nonce( $this->slug . '_license' );
		$this->print_panel_styles();
		?>
		<div class="self-license-panel" data-slug="<?php echo esc_attr( $this->slug ); ?>">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p>
				<label for="self-key-<?php echo esc_attr( $this->slug ); ?>"><?php esc_html_e( 'Licence key', 'smartengin-licence-client' ); ?></label><br>
				<input type="text" id="self-key-<?php echo esc_attr( $this->slug ); ?>" class="regular-text self-key"
					value="<?php echo esc_attr( $state['key'] ); ?>" <?php disabled( $activated ); ?>
					placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" autocomplete="off">
			</p>
			<p class="self-status-line"><?php echo wp_kses_post( $this->status_html() ); ?></p>
			<p>
				<button type="button" class="button button-primary self-activate" <?php echo $activated ? 'style="display:none"' : ''; ?>>
					<?php esc_html_e( 'Activate', 'smartengin-licence-client' ); ?>
				</button>
				<button type="button" class="button self-deactivate" <?php echo $activated ? '' : 'style="display:none"'; ?>>
					<?php esc_html_e( 'Deactivate', 'smartengin-licence-client' ); ?>
				</button>
				<span class="spinner self-spinner" style="float:none;"></span>
			</p>
			<p class="self-error" style="color:#b32d2e;margin:0;"></p>
		</div>
		<script>
		( function () {
			var root = document.currentScript.previousElementSibling;
			while ( root && ! root.classList.contains( 'self-license-panel' ) ) { root = root.previousElementSibling; }
			if ( ! root ) { return; }
			var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var slug    = <?php echo wp_json_encode( $this->slug ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var keyEl   = root.querySelector( '.self-key' );
			var actBtn  = root.querySelector( '.self-activate' );
			var deacBtn = root.querySelector( '.self-deactivate' );
			var statusEl= root.querySelector( '.self-status-line' );
			var errEl   = root.querySelector( '.self-error' );
			var spin    = root.querySelector( '.self-spinner' );

			function call( action, extra, done ) {
				errEl.textContent = '';
				spin.classList.add( 'is-active' );
				var body = new URLSearchParams();
				body.append( 'action', slug + '_' + action );
				body.append( 'nonce', nonce );
				Object.keys( extra || {} ).forEach( function ( k ) { body.append( k, extra[ k ] ); } );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						spin.classList.remove( 'is-active' );
						if ( res && res.success ) { done( res.data ); }
						else { errEl.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Error.'; }
					} )
					.catch( function () { spin.classList.remove( 'is-active' ); errEl.textContent = 'Error.'; } );
			}

			actBtn.addEventListener( 'click', function () {
				call( 'activate', { key: keyEl.value }, function ( data ) {
					statusEl.innerHTML = data.html;
					actBtn.style.display = 'none';
					deacBtn.style.display = '';
					keyEl.disabled = true;
				} );
			} );
			deacBtn.addEventListener( 'click', function () {
				call( 'deactivate', {}, function ( data ) {
					statusEl.innerHTML = data.html;
					deacBtn.style.display = 'none';
					actBtn.style.display = '';
					keyEl.disabled = false;
				} );
			} );
		} )();
		</script>
		<?php
	}

	/* ======================================================================
	 * Lifecycle
	 * ==================================================================== */

	/**
	 * Clear cron + caches when the host plugin is deactivated.
	 *
	 * @return void
	 */
	public function on_plugin_deactivate() {
		$ts = wp_next_scheduled( $this->cron_hook );
		if ( $ts ) {
			wp_unschedule_event( $ts, $this->cron_hook );
		}
		$this->flush_update_cache();
	}
}
