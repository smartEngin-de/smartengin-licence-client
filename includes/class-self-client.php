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

	/** Seconds a call to the licence server may take (also named in the error text). */
	const TIMEOUT = 15;

	/** @var string Licence server base URL (no trailing slash needed). */
	private $server_url;

	/** @var string Server URL as configured in code – the anchor every relocation is checked against. */
	private $configured_url = '';

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

	/** @var string Optional help article for connection problems (0.8.2), '' = no link. */
	private $help_url = '';

	/** @var int Duration of the last call() in milliseconds (0.8.2). */
	private $last_call_ms = 0;

	/**
	 * The comparison address for the connection test (0.8.2): the same one
	 * WordPress itself asks for core updates (wp-includes/update.php,
	 * wp_version_check(), upgraded to https when the server supports SSL).
	 */
	const WPORG_URL = 'https://api.wordpress.org/core/version-check/1.7/';

	/** Seconds in which a repeated click shows the last result instead of testing again. */
	const TEST_BRAKE = 10;

	/**
	 * @param array $config server_url, slug, plugin_file, version[, option_name][, help_url].
	 */
	public function __construct( array $config ) {
		// Optional and empty by default (0.8.2): the same library ships inside
		// products of other vendors, where no link to anybody else may appear.
		if ( isset( $config['help_url'] ) && preg_match( '#^https?://#i', (string) $config['help_url'] ) ) {
			$this->help_url = esc_url_raw( (string) $config['help_url'] );
		}

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

		// Relocation channel: if a validated "server moved" address is on file,
		// talk to that address from now on (see remember_moved_server()).
		$this->configured_url = $this->server_url;
		$this->adopt_moved_server();

		// AJAX (backend buttons).
		add_action( 'wp_ajax_' . $this->slug . '_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_' . $this->slug . '_deactivate', array( $this, 'ajax_deactivate' ) );
		add_action( 'wp_ajax_' . $this->slug . '_conn_test', array( $this, 'ajax_connection_test' ) );

		// Tell the customer when the server refuses updates for this website.
		add_action( 'admin_notices', array( $this, 'blocked_notice' ) );

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
			'blocked'          => '',    // Server refused THIS site: limit_reached|trial_used_on_site|''
			'blocked_message'  => '',    // Reason in plain words, as sent by the server.
			'server_moved_to'  => '',    // Validated "the server moved here" address, or ''.
			'subscription'     => false, // Sold as a subscription (server >= L&b 1.6.43; older: false).
			'grace_days'       => 0,     // Days a subscription keeps premium features after its end date.
			'last_error'       => '',    // Last "server could not be reached" message (0.8.1), '' once a call got through.
			'last_error_time'  => 0,     // When that happened (Unix time).
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
			'timeout'   => self::TIMEOUT,
			'sslverify' => true,
			'headers'   => array( 'Accept' => 'application/json' ),
		);

		$start = microtime( true );
		if ( 'GET' === $method ) {
			$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $body ), $url ), $args );
		} else {
			$args['body'] = $body;
			$response     = wp_remote_post( $url, $args );
		}
		$this->last_call_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

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
		$data = is_array( $data ) ? $data : array();

		// Relocation channel: the server may quietly append "I have moved to X"
		// to any answer. Validated and remembered here, in ONE place, so every
		// endpoint benefits.
		if ( ! empty( $data['server_moved'] ) ) {
			$this->remember_moved_server( (string) $data['server_moved'] );
		}

		return array( 'code' => $code, 'data' => $data );
	}

	/* ======================================================================
	 * Relocation channel ("the licence server moved")
	 * ==================================================================== */

	/**
	 * Is $url an address this product may follow to?
	 *
	 * The whole safety of the relocation channel lives in this check: without
	 * it, anyone who can make the client talk to their machine once could
	 * permanently redirect the licence traffic ("moved to my-server.example").
	 * A new address is therefore only accepted when it is HTTPS and stays in
	 * the FAMILY of the address compiled into the product: the same host, a
	 * subdomain of it, or its parent (smartengin.de <-> mein.smartengin.de).
	 * The comparison anchor is always the CONFIGURED address, never a
	 * previously adopted one – so even a chain of moves can never leave the
	 * family.
	 *
	 * @param string $url Candidate server URL.
	 * @return bool
	 */
	private function allowed_new_server( $url ) {
		if ( 0 !== stripos( $url, 'https://' ) ) {
			return false; // HTTPS only – a licence server never moves to plain HTTP.
		}
		$new = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$old = strtolower( (string) wp_parse_url( $this->configured_url, PHP_URL_HOST ) );
		if ( '' === $new || '' === $old ) {
			return false;
		}
		$new = preg_replace( '#^www\.#', '', $new );
		$old = preg_replace( '#^www\.#', '', $old );
		return $new === $old
			|| substr( $new, -strlen( '.' . $old ) ) === '.' . $old
			|| substr( $old, -strlen( '.' . $new ) ) === '.' . $new;
	}

	/**
	 * Use a stored relocation address, if there is a valid one.
	 *
	 * Runs once per request (from the constructor). The stored value is
	 * re-validated on every load: should the rule above ever tighten, a
	 * previously stored address that no longer passes simply stops being used.
	 *
	 * @return void
	 */
	private function adopt_moved_server() {
		$state = $this->get_state();
		$moved = isset( $state['server_moved_to'] ) ? (string) $state['server_moved_to'] : '';
		if ( '' !== $moved && $this->allowed_new_server( $moved ) ) {
			$this->server_url = untrailingslashit( $moved );
		}
	}

	/**
	 * Remember a "server moved" hint – and switch over right away.
	 *
	 * An address outside the family is ignored WITHOUT touching the stored
	 * value: a rogue answer must not be able to erase a legitimate move.
	 * The server announcing the configured address again (a move back) clears
	 * the stored value instead of storing the obvious.
	 *
	 * @param string $url Announced new server URL.
	 * @return void
	 */
	private function remember_moved_server( $url ) {
		$url = untrailingslashit( trim( $url ) );
		if ( '' === $url || ! $this->allowed_new_server( $url ) ) {
			return;
		}

		$stored = ( $url === $this->configured_url ) ? '' : $url;

		$state = $this->get_state();
		if ( (string) $state['server_moved_to'] !== $stored ) {
			$state['server_moved_to'] = $stored;
			$this->set_state( $state );
		}

		$this->server_url = $url; // Effective immediately, not only next request.
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

	/* ======================================================================
	 * "Server could not be reached" - say WHY (0.8.1)
	 * ==================================================================== */

	/**
	 * Which kind of connection failure a transport WP_Error is.
	 *
	 * Decided on the error CODE and the cURL NUMBER, never on translated
	 * wording: WordPress translates "User has blocked requests through HTTP"
	 * into the site language, so a text match would silently miss on every
	 * non-English site. The cURL text itself comes from the Requests library
	 * ("cURL error 28: ...", wp-includes/Requests/src/Transport/Curl.php) and
	 * is never translated; the fsockopen fallback throws plain English, too.
	 *
	 * @param WP_Error $error Error from wp_remote_post()/wp_remote_get().
	 * @return string timeout|dns|connect|certificate|blocked|other
	 */
	public static function connection_reason( WP_Error $error ) {
		if ( 'http_request_not_executed' === $error->get_error_code() ) {
			return 'blocked'; // WP_HTTP_BLOCK_EXTERNAL (class-wp-http.php, block_request()).
		}

		$text = trim( (string) $error->get_error_message() );
		$curl = preg_match( '/^cURL error (\d+):/', $text, $m ) ? (int) $m[1] : 0;

		if ( 28 === $curl || 'fsocket timed out' === $text ) {
			return 'timeout';         // CURLE_OPERATION_TIMEDOUT.
		}
		if ( 6 === $curl ) {
			return 'dns';             // CURLE_COULDNT_RESOLVE_HOST.
		}
		if ( 7 === $curl ) {
			return 'connect';         // CURLE_COULDNT_CONNECT.
		}
		// TLS handshake / certificate: 35 SSL_CONNECT_ERROR, 51 PEER_FAILED_VERIFICATION
		// (older libcurl), 58 SSL_CERTPROBLEM, 59 SSL_CIPHER, 60 SSL_CACERT,
		// 77 SSL_CACERT_BADFILE, 82 SSL_CRL_BADFILE, 83 SSL_ISSUER_ERROR,
		// 90 SSL_PINNEDPUBKEYNOTMATCH, 91 SSL_INVALIDCERTSTATUS.
		if ( in_array( $curl, array( 35, 51, 58, 59, 60, 77, 82, 83, 90, 91 ), true )
			|| 0 === strpos( $text, 'SSL certificate did not match' ) ) {
			return 'certificate';
		}
		return 'other';
	}

	/**
	 * Turn a transport failure into the message the customer sends to support.
	 *
	 * Keeps the old first sentence, adds one plain sentence naming the case,
	 * the address the host has to allow, and the original technical text in
	 * brackets. Before 0.8.1 the WP_Error from wp_remote_post() was thrown
	 * away, and "could not be reached" alone never told anybody whether the
	 * customer's host, her website or the licence server was at fault.
	 *
	 * The licence key is cut out of the technical text (it is not in a POST
	 * URL, but a GET or a plugin's own error could carry it). The message is
	 * also remembered with its time, so "check now" can show it later.
	 *
	 * @param WP_Error $error Transport error.
	 * @param string   $key   Licence key to keep out of the message.
	 * @return WP_Error Code 'server_unreachable', data [ 'reason' => ... ].
	 */
	private function unreachable( WP_Error $error, $key = '' ) {
		$reason = self::connection_reason( $error );

		$scheme  = strtolower( (string) wp_parse_url( $this->server_url, PHP_URL_SCHEME ) );
		$host    = strtolower( (string) wp_parse_url( $this->server_url, PHP_URL_HOST ) );
		$port    = (int) wp_parse_url( $this->server_url, PHP_URL_PORT );
		$port    = $port > 0 ? $port : ( 'http' === $scheme ? 80 : 443 );
		$address = ( '' !== $scheme ? $scheme : 'https' ) . '://' . $host;

		$parts = array( __( 'The licence server could not be reached. Please try again.', 'smartengin-licence-client' ) );

		switch ( $reason ) {
			case 'timeout':
				// The MEASURED wait, not TIMEOUT (0.8.2): WordPress hands only the
				// total limit (15 s) to the Requests library, which keeps its own
				// connect limit of 10 s (Requests::$default_options
				// 'connect_timeout'). A blocked connection therefore gives up after
				// about 10 s — the customer saw "after 10004 ms", while 0.8.1 said 15.
				$parts[] = sprintf(
					/* translators: %d: number of seconds the website waited. */
					__( 'Your website’s server waited %d seconds for an answer and got none. Usually your host or a firewall blocks outgoing connections.', 'smartengin-licence-client' ),
					max( 1, (int) round( $this->last_call_ms / 1000 ) )
				);
				break;
			case 'dns':
				$parts[] = __( 'Your website’s server could not look up the address of the licence server (name resolution failed). That is a matter for your host (DNS).', 'smartengin-licence-client' );
				break;
			case 'connect':
				$parts[] = __( 'Your website’s server could not open a connection to the licence server. Usually your host or a firewall blocks outgoing connections.', 'smartengin-licence-client' );
				break;
			case 'certificate':
				$parts[] = __( 'The secure connection failed at the certificate check. Usually the list of trusted certificates on your website’s server is out of date – your host can update it.', 'smartengin-licence-client' );
				break;
			case 'blocked':
				$parts[] = self::blocked_sentence( $host );
				break;
		}

		if ( in_array( $reason, array( 'timeout', 'dns', 'connect' ), true ) ) {
			$parts[] = sprintf(
				/* translators: 1: address of the licence server, e.g. https://smartengin.de, 2: port number. */
				__( 'Your host needs to allow outgoing connections to %1$s (port %2$d).', 'smartengin-licence-client' ),
				$address,
				$port
			);
		} elseif ( 'blocked' !== $reason ) {
			$parts[] = sprintf(
				/* translators: 1: address of the licence server, e.g. https://smartengin.de, 2: port number. */
				__( 'Licence server: %1$s (port %2$d).', 'smartengin-licence-client' ),
				$address,
				$port
			);
		}

		$detail = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $error->get_error_message() ) ) );
		$key    = trim( (string) $key );
		if ( '' !== $key ) {
			$detail = str_replace( array( $key, rawurlencode( $key ), urlencode( $key ) ), '…', $detail );
		}
		if ( mb_strlen( $detail ) > 300 ) {
			$detail = mb_substr( $detail, 0, 300 ) . '…';
		}
		if ( '' !== $detail ) {
			$parts[] = sprintf(
				/* translators: %s: the original technical error text, e.g. "cURL error 28: Operation timed out ...". */
				__( '(Technical detail: %s)', 'smartengin-licence-client' ),
				$detail
			);
		}

		$message = implode( ' ', $parts );

		$state                    = $this->get_state();
		$state['last_error']      = $message;
		$state['last_error_time'] = time();
		$this->set_state( $state );

		return new WP_Error( 'server_unreachable', $message, array( 'reason' => $reason ) );
	}

	/**
	 * The last "could not be reached" message, while no call has got through
	 * since (0.8.1).
	 *
	 * @return array|null [ 'message' => string, 'time' => int ] or null.
	 */
	public function last_error() {
		$state = $this->get_state();
		if ( '' === (string) $state['last_error'] ) {
			return null;
		}
		return array(
			'message' => (string) $state['last_error'],
			'time'    => (int) $state['last_error_time'],
		);
	}

	/* ======================================================================
	 * Connection test (0.8.2) — what support used to do by hand
	 * ==================================================================== */

	/**
	 * Address of the help article for connection problems, or '' when the
	 * host product set none.
	 *
	 * @return string
	 */
	public function help_url() {
		return $this->help_url;
	}

	/**
	 * Test from THIS server whether the licence server and, for comparison,
	 * WordPress.org can be reached.
	 *
	 * "Reached" means any HTTP answer, whatever its status (even 403/404): only
	 * a transport error (WP_Error) means "not reached". So the test needs no
	 * route of its own on the licence server, and a firewall page in front of
	 * it is told apart from a blocked connection.
	 *
	 * The licence key is never sent, and nothing goes anywhere but these two
	 * addresses. A repeated click within TEST_BRAKE seconds returns the last
	 * result instead of testing again — a first click is never refused (unlike
	 * a lockout that keeps the customer from even trying).
	 *
	 * @return array See probe() for the two parts; plus time, site, verdict.
	 */
	public function connection_test() {
		$last = $this->last_connection_test();
		if ( $last && ( time() - (int) $last['time'] ) < self::TEST_BRAKE ) {
			$last['repeated'] = true;
			return $last;
		}

		$result = array(
			'time'     => time(),
			'site'     => $this->instance(),
			'licence'  => $this->probe( $this->server_url . '/wp-json/sels/v1/' ),
			'wporg'    => $this->probe( self::WPORG_URL ),
			'repeated' => false,
		);
		$result['verdict'] = self::connection_verdict( $result );

		set_transient( str_replace( '-', '_', $this->slug ) . '_conn_test', $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * The last connection test (kept for an hour), or null.
	 *
	 * @return array|null
	 */
	public function last_connection_test() {
		$last = get_transient( str_replace( '-', '_', $this->slug ) . '_conn_test' );
		return ( is_array( $last ) && isset( $last['verdict'], $last['licence'], $last['wporg'] ) ) ? $last : null;
	}

	/**
	 * One GET to $url, measured.
	 *
	 * @param string $url Address to fetch.
	 * @return array url, host, port, ips (list, empty = not resolvable),
	 *               reached, code, reason, detail, ms.
	 */
	private function probe( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port   = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port   = $port > 0 ? $port : ( 'http' === $scheme ? 80 : 443 );

		// The address THIS server resolves the name to (PHP's own resolver,
		// IPv4). Shows DNS trouble at once, and whether the request even goes
		// to the right machine.
		$ips = ( '' !== $host ) ? gethostbynamel( $host ) : false;
		$ips = is_array( $ips ) ? array_values( array_unique( $ips ) ) : array();

		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,    // Any answer counts — no need to follow.
				'limit_response_size' => 16384,
				'sslverify'           => true,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		$out = array(
			'url'     => ( '' !== $scheme ? $scheme : 'https' ) . '://' . $host,
			'host'    => $host,
			'port'    => $port,
			'ips'     => $ips,
			'reached' => false,
			'code'    => 0,
			'reason'  => '',
			'detail'  => '',
			'ms'      => $ms,
		);

		if ( is_wp_error( $response ) ) {
			$out['reason'] = self::connection_reason( $response );
			$detail        = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $response->get_error_message() ) ) );
			$out['detail'] = ( mb_strlen( $detail ) > 300 ) ? mb_substr( $detail, 0, 300 ) . '…' : $detail;
			return $out;
		}

		$out['reached'] = true;
		$out['code']    = (int) wp_remote_retrieve_response_code( $response );
		return $out;
	}

	/**
	 * In one word: what does the test mean?
	 *
	 * @param array $r Result of connection_test().
	 * @return string ok | ok_wporg_down | refused | licence_unreachable | no_outbound | wp_blocked
	 */
	public static function connection_verdict( array $r ) {
		$lic = $r['licence'];
		$org = $r['wporg'];

		if ( ! $lic['reached'] && 'blocked' === $lic['reason'] ) {
			return 'wp_blocked';
		}
		if ( $lic['reached'] && in_array( (int) $lic['code'], array( 403, 429, 503 ), true ) ) {
			return 'refused';
		}
		if ( $lic['reached'] ) {
			return $org['reached'] ? 'ok' : 'ok_wporg_down';
		}
		return $org['reached'] ? 'licence_unreachable' : 'no_outbound';
	}

	/**
	 * Short plain words for a connection_reason() value.
	 *
	 * @param string $reason timeout|dns|connect|certificate|blocked|other.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		switch ( $reason ) {
			case 'timeout':
				return __( 'no answer in time (time-out)', 'smartengin-licence-client' );
			case 'dns':
				return __( 'the name could not be resolved (DNS)', 'smartengin-licence-client' );
			case 'connect':
				return __( 'no connection could be opened', 'smartengin-licence-client' );
			case 'certificate':
				return __( 'the certificate check failed', 'smartengin-licence-client' );
			case 'blocked':
				return __( 'blocked by WordPress itself', 'smartengin-licence-client' );
		}
		return __( 'another error', 'smartengin-licence-client' );
	}

	/**
	 * The verdict as a sentence for the customer.
	 *
	 * @param array $r Result of connection_test().
	 * @return string
	 */
	public static function connection_message( array $r ) {
		$lic = $r['licence'];
		switch ( $r['verdict'] ) {
			case 'ok':
				return __( 'The connection works. Please activate your licence again now.', 'smartengin-licence-client' );
			case 'ok_wporg_down':
				return __( 'The licence server can be reached, so activating should work now. WordPress.org could not be reached from your server — that does not affect your licence, but it may affect updates of WordPress itself.', 'smartengin-licence-client' );
			case 'refused':
				return sprintf(
					/* translators: 1: licence server address, 2: HTTP status code. */
					__( 'Your server reached %1$s, but the answer was a refusal (HTTP status %2$d). It may come from the licence server itself or from a protection in front of it — this is usually not caused by your host. Please send the text below to the support of this plugin.', 'smartengin-licence-client' ),
					$lic['url'],
					(int) $lic['code']
				);
			case 'licence_unreachable':
				return sprintf(
					/* translators: %s: licence server address. */
					__( 'Your server can reach the internet, but not %s. That is not caused by your key and not by your website. Please send the text below to your host.', 'smartengin-licence-client' ),
					$lic['url']
				);
			case 'no_outbound':
				return __( 'Your server does not seem to be allowed to open any connections to the outside. Please send the text below to your host.', 'smartengin-licence-client' );
			case 'wp_blocked':
				return self::blocked_sentence( $lic['host'] );
		}
		return '';
	}

	/**
	 * The ready-made text for the host, with the measured values.
	 *
	 * No licence key and no customer data: only the website's domain, the time,
	 * the two addresses and what happened.
	 *
	 * @param array $r Result of connection_test().
	 * @return string Plain text with line breaks.
	 */
	public static function connection_host_text( array $r ) {
		$lic   = $r['licence'];
		$org   = $r['wporg'];
		$nicht = __( 'could not be resolved', 'smartengin-licence-client' );

		$ergebnis = function ( array $p ) {
			if ( $p['reached'] ) {
				return sprintf(
					/* translators: 1: HTTP status code, 2: duration in milliseconds. */
					__( 'reached (HTTP status %1$d, %2$d ms)', 'smartengin-licence-client' ),
					(int) $p['code'],
					(int) $p['ms']
				);
			}
			return sprintf(
				/* translators: 1: short reason, e.g. "no answer in time (time-out)", 2: duration in milliseconds. */
				__( 'NOT reached: %1$s (after %2$d ms)', 'smartengin-licence-client' ),
				self::reason_label( $p['reason'] ),
				(int) $p['ms']
			);
		};

		$zeilen   = array();
		$zeilen[] = sprintf(
			/* translators: 1: domain of the website, 2: date and time of the test. */
			__( 'Connection test from the website %1$s on %2$s.', 'smartengin-licence-client' ),
			$r['site'],
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', (int) $r['time'] )
		);
		$zeilen[] = __( 'A plugin on this website cannot reach its licence server.', 'smartengin-licence-client' );
		$zeilen[] = '';
		$zeilen[] = sprintf(
			/* translators: 1: address, 2: port number. */
			__( 'Target: %1$s (port %2$d)', 'smartengin-licence-client' ),
			$lic['url'],
			(int) $lic['port']
		);
		$zeilen[] = sprintf(
			/* translators: %s: IP addresses, or "could not be resolved". */
			__( 'IP address resolved by this server: %s', 'smartengin-licence-client' ),
			$lic['ips'] ? implode( ', ', $lic['ips'] ) : $nicht
		);
		$zeilen[] = sprintf(
			/* translators: %s: result, e.g. "NOT reached: no answer in time (time-out) (after 10004 ms)". */
			__( 'Result: %s', 'smartengin-licence-client' ),
			$ergebnis( $lic )
		);
		if ( '' !== $lic['detail'] ) {
			$zeilen[] = sprintf(
				/* translators: %s: the original technical error text. */
				__( 'Technical detail: %s', 'smartengin-licence-client' ),
				$lic['detail']
			);
		}
		$zeilen[] = '';
		$zeilen[] = sprintf(
			/* translators: 1: address, 2: result, e.g. "reached (HTTP status 200, 120 ms)". */
			__( 'For comparison, %1$s: %2$s', 'smartengin-licence-client' ),
			$org['url'],
			$ergebnis( $org )
		);
		$zeilen[] = '';
		$zeilen[] = sprintf(
			/* translators: %s: address of the licence server. */
			__( 'Please check whether outgoing connections from this web space to %s are blocked or routed wrongly.', 'smartengin-licence-client' ),
			$lic['url']
		);
		$zeilen[] = __( 'Please also tell me the outgoing IP address of this web space (the address its requests leave from — not the address of the website itself).', 'smartengin-licence-client' );
		$zeilen[] = sprintf(
			/* translators: %s: host name of the licence server. */
			__( 'And please send me the result of a traceroute from this web space to %s.', 'smartengin-licence-client' ),
			$lic['host']
		);

		return implode( "\n", $zeilen );
	}

	/**
	 * The sentence for "WordPress blocks the request itself" (shared by the
	 * activation message and the connection test).
	 *
	 * @param string $host Host name of the licence server.
	 * @return string
	 */
	private static function blocked_sentence( $host ) {
		return sprintf(
			/* translators: %s: host name of the licence server, e.g. smartengin.de. */
			__( 'WordPress on your website blocked the request itself – through the setting WP_HTTP_BLOCK_EXTERNAL in wp-config.php or through a plugin. To allow it, add %s to WP_ACCESSIBLE_HOSTS.', 'smartengin-licence-client' ),
			$host
		);
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
			return $this->unreachable( $res, $key );
		}

		$data  = $res['data'];
		$state = $this->get_state();
		$state['key'] = $key;

		// The call got through, so an earlier connection problem is over.
		$state['last_error']      = '';
		$state['last_error_time'] = 0;

		// The server has just re-judged this website, so any earlier block is
		// stale either way – it is re-established on the next update check if
		// it still applies.
		$state['blocked']         = '';
		$state['blocked_message'] = '';

		if ( 200 === $res['code'] && ! empty( $data['success'] ) ) {
			$state['activated']        = true;
			$state['status']           = isset( $data['status'] ) ? (string) $data['status'] : 'active';
			$state['valid_until']      = isset( $data['valid_until'] ) ? $data['valid_until'] : null;
			$state['activations_left'] = isset( $data['activations_left'] ) ? $data['activations_left'] : null;
			$state['valid']            = $this->derive_valid( $state['status'], $state['valid_until'] );
			$state                     = $this->store_lock_terms( $state, $data );
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
		if ( isset( $data['message'] ) && '' !== (string) $data['message'] ) {
			$msg = (string) $data['message'];
		} elseif ( 200 !== (int) $res['code'] ) {
			// No answer from the licence server itself (e.g. a firewall in
			// front of it): the status code is the only clue support gets.
			$msg = sprintf(
				/* translators: %d: HTTP status code, e.g. 403 or 503. */
				__( 'Activation failed. (The licence server answered with HTTP status %d.)', 'smartengin-licence-client' ),
				(int) $res['code']
			);
		} else {
			$msg = __( 'Activation failed.', 'smartengin-licence-client' );
		}
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
				return $this->unreachable( $res, $key );
			}
		}

		// Re-read: call() itself may have stored a relocation address, which a
		// write based on the copy from before the call would silently undo.
		$state        = $this->get_state();
		$state['key'] = $key;

		$state['activated']        = false;
		$state['valid']            = false;
		$state['status']           = '';
		$state['valid_until']      = null;
		$state['activations_left'] = null;
		$state['blocked']          = '';
		$state['blocked_message']  = '';
		$state['subscription']     = false;
		$state['grace_days']       = 0;
		$state['last_error']       = '';
		$state['last_error_time']  = 0;
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
		if ( is_wp_error( $res ) ) {
			// Keep last known good state - only remember WHY, so a manual
			// "check now" can show it (0.8.1).
			$this->unreachable( $res, $key );
			return;
		}
		if ( 200 !== $res['code'] ) {
			return; // Keep last known good state.
		}

		// Re-read: call() itself may have stored a relocation address, which a
		// write based on the copy from before the call would silently undo.
		$state = $this->get_state();

		$state['last_error']      = '';
		$state['last_error_time'] = 0;

		$data = $res['data'];
		$state['status']           = isset( $data['status'] ) ? (string) $data['status'] : $state['status'];
		$state['valid']            = ! empty( $data['valid'] );
		$state['valid_until']      = array_key_exists( 'valid_until', $data ) ? $data['valid_until'] : $state['valid_until'];
		$state['activations_left'] = array_key_exists( 'activations_left', $data ) ? $data['activations_left'] : $state['activations_left'];
		$state                     = $this->store_lock_terms( $state, $data );
		$state['last_check']       = time();
		$this->set_state( $state );
	}

	/**
	 * Take over the lock terms from a server answer (0.8.0).
	 *
	 * Only when the server sends them: a server older than L&b 1.6.43 knows
	 * nothing about them, and the stored values then stay as they are (a fresh
	 * install keeps the defaults: no subscription, i.e. never locked).
	 *
	 * @param array $state State.
	 * @param array $data  Decoded server answer.
	 * @return array
	 */
	private function store_lock_terms( array $state, array $data ) {
		if ( array_key_exists( 'subscription', $data ) ) {
			$state['subscription'] = ! empty( $data['subscription'] );
		}
		if ( array_key_exists( 'grace_days', $data ) ) {
			$state['grace_days'] = max( 0, (int) $data['grace_days'] );
		}
		return $state;
	}

	/* ======================================================================
	 * Entitlement (0.8.0) - gate premium features with is_licensed()
	 * ==================================================================== */

	/**
	 * The status as it IS: 'active' whose end date has passed reads 'expired'.
	 *
	 * @return string active|expired|refunded|disabled|unknown|''
	 */
	public function effective_status() {
		$state  = $this->get_state();
		$status = (string) $state['status'];
		if ( 'active' === $status && ! empty( $state['valid_until'] ) && strtotime( (string) $state['valid_until'] ) <= time() ) {
			return 'expired';
		}
		return $status;
	}

	/**
	 * When the premium features of an expired SUBSCRIPTION switch off (Unix
	 * time), or 0 when they never do (one-off purchase, lifetime, no date).
	 *
	 * @return int
	 */
	public function lock_time() {
		$state = $this->get_state();
		if ( empty( $state['subscription'] ) || empty( $state['valid_until'] ) ) {
			return 0;
		}
		$end = strtotime( (string) $state['valid_until'] );
		return $end ? $end + max( 0, (int) $state['grace_days'] ) * DAY_IN_SECONDS : 0;
	}

	/**
	 * licensed | grace | locked | unlicensed.
	 *
	 * - licensed:   valid key, or an expired ONE-OFF purchase (it keeps working,
	 *               only updates stop - nobody's site breaks over a renewal).
	 * - grace:      an expired SUBSCRIPTION within its grace days.
	 * - locked:     an expired subscription past its grace days, or a licence
	 *               that was refunded / disabled / not recognised.
	 * - unlicensed: no key, or this site is not activated.
	 *
	 * Decided from the STORED state only, never with a server call: the lock
	 * happens on time even when the server is out of reach, and a server outage
	 * can never switch a paying customer off early (the date comes from the last
	 * good answer, and every renewal moves it forward).
	 *
	 * @return string
	 */
	public function mode() {
		$state = $this->get_state();
		if ( '' === (string) $state['key'] || empty( $state['activated'] ) ) {
			return 'unlicensed';
		}
		$status = $this->effective_status();
		if ( 'active' === $status ) {
			return 'licensed';
		}
		if ( 'expired' !== $status ) {
			return 'locked';
		}
		if ( empty( $state['subscription'] ) ) {
			return 'licensed';
		}
		$lock = $this->lock_time();
		return ( $lock > 0 && time() < $lock ) ? 'grace' : 'locked';
	}

	/**
	 * THE check for premium features. Use this instead of reading 'status'.
	 *
	 * @return bool
	 */
	public function is_licensed() {
		return in_array( $this->mode(), array( 'licensed', 'grace' ), true );
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
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			// No-bricking: on any error just report "no update" (the plugin keeps
			// running); the next check picks it up once the server is reachable.
			// A stored block is deliberately left untouched here – an unreachable
			// server must never invent one and never clear one.
			$this->update_memo = array( 'update' => false );
			return $this->update_memo;
		}

		$data = is_array( $res['data'] ) ? $res['data'] : array();

		// The server answered properly, so its verdict on THIS installation is
		// current – remember it, or clear it once the site is registered again.
		$this->remember_block( $data );

		if ( empty( $data['update'] ) ) {
			$data['update'] = false;
		}

		$this->update_memo = $data;
		return $this->update_memo;
	}

	/**
	 * Remember – or clear – a server-side block for this installation.
	 *
	 * The server answers "no update" both when there simply is none and when it
	 * refuses THIS website (licence full, trial already used here). Only the
	 * second case carries a 'blocked' code. Without storing it the customer
	 * would see nothing at all and never learn why updates stopped, which is
	 * exactly the silent failure the server-side check was built to avoid.
	 *
	 * Called only after a proper HTTP 200, so an unreachable server can neither
	 * invent nor clear a block. Writes only on a real change: the update check
	 * runs often and a needless option write each time would be pure load.
	 *
	 * @param array $data Decoded server answer.
	 * @return void
	 */
	private function remember_block( array $data ) {
		$code    = isset( $data['blocked'] ) ? (string) $data['blocked'] : '';
		$message = ( '' !== $code && isset( $data['message'] ) ) ? (string) $data['message'] : '';

		$state = $this->get_state();
		if ( (string) $state['blocked'] === $code && (string) $state['blocked_message'] === $message ) {
			return;
		}

		$state['blocked']         = $code;
		$state['blocked_message'] = $message;
		$this->set_state( $state );
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
		$key    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_ajax() eine Zeile darueber prueft Rechte UND Nonce (check_ajax_referer).
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

	/**
	 * AJAX: connection test (0.8.3). Answers with the finished result HTML.
	 *
	 * @return void
	 */
	public function ajax_connection_test() {
		$this->verify_ajax();
		wp_send_json_success( array( 'html' => $this->connection_result_html( $this->connection_test(), false ) ) );
	}

	/**
	 * The result of a connection test as HTML (0.8.3): verdict, a small table,
	 * and — only when something needs clearing up — the text for the host with
	 * a copy button, plus the help link when the host product set one.
	 *
	 * Public so that a product with its own licence page can show the very same
	 * result. Colours and the copy button need the panel stylesheet and script,
	 * which render_panel() prints.
	 *
	 * @param array $r         Result of connection_test() or last_connection_test().
	 * @param bool  $with_help Show the help link (render_panel() has its own).
	 * @return string
	 */
	public function connection_result_html( array $r, $with_help = true ) {
		if ( ! isset( $r['verdict'], $r['licence'], $r['wporg'] ) ) {
			return '';
		}
		$verdict = (string) $r['verdict'];
		$ok      = in_array( $verdict, array( 'ok', 'ok_wporg_down' ), true );
		$tone    = $ok ? 'active' : ( 'refused' === $verdict ? 'expired' : 'inactive' );

		$html = '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: date and time of the last check. */
				__( 'Last check: %s', 'smartengin-licence-client' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $r['time'] )
			)
		) . '</p>';

		if ( ! empty( $r['repeated'] ) ) {
			$html .= '<p class="description">' . esc_html__( 'You clicked again within a few seconds, so this is the result of the last check.', 'smartengin-licence-client' ) . '</p>';
		}

		$html .= '<p class="self-verdict self-verdict--' . esc_attr( $tone ) . '">' . esc_html( self::connection_message( $r ) ) . '</p>';

		$html .= '<table class="widefat striped self-conn-table"><thead><tr>'
			. '<th>' . esc_html__( 'Target', 'smartengin-licence-client' ) . '</th>'
			. '<th>' . esc_html__( 'IP address', 'smartengin-licence-client' ) . '</th>'
			. '<th>' . esc_html__( 'Result', 'smartengin-licence-client' ) . '</th>'
			. '<th>' . esc_html__( 'Duration', 'smartengin-licence-client' ) . '</th>'
			. '</tr></thead><tbody>';

		$targets = array(
			'licence' => __( 'Licence server', 'smartengin-licence-client' ),
			'wporg'   => __( 'WordPress.org (for comparison)', 'smartengin-licence-client' ),
		);
		foreach ( $targets as $part => $name ) {
			$p = $r[ $part ];
			if ( $p['reached'] ) {
				$cell_tone = ( 'licence' === $part && 'refused' === $verdict ) ? 'expired' : 'active';
				$cell      = '<span class="self-status self-status--' . $cell_tone . '">' . esc_html(
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'reached (HTTP %d)', 'smartengin-licence-client' ),
						(int) $p['code']
					)
				) . '</span>';
			} else {
				$cell = '<span class="self-status self-status--inactive">' . esc_html__( 'not reached', 'smartengin-licence-client' ) . '</span> '
					. '<span class="description">' . esc_html( self::reason_label( $p['reason'] ) ) . '</span>';
			}
			$html .= '<tr><td><strong>' . esc_html( $name ) . '</strong><br><code>' . esc_html( $p['url'] ) . '</code> · '
				. esc_html(
					sprintf(
						/* translators: %d: port number. */
						__( 'port %d', 'smartengin-licence-client' ),
						(int) $p['port']
					)
				) . '</td>'
				. '<td>' . ( $p['ips'] ? esc_html( implode( ', ', $p['ips'] ) ) : esc_html__( 'could not be resolved', 'smartengin-licence-client' ) ) . '</td>'
				. '<td>' . $cell . '</td>'
				. '<td>' . esc_html(
					sprintf(
						/* translators: %d: duration in milliseconds. */
						__( '%d ms', 'smartengin-licence-client' ),
						(int) $p['ms']
					)
				) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		// The text for the host — only when there is something to clear up.
		// A read-only field (works even if the browser refuses to copy) plus a
		// copy button for convenience.
		if ( ! $ok ) {
			$html .= '<h3>' . esc_html__( 'Text for your host', 'smartengin-licence-client' ) . '</h3>';
			$html .= '<p>' . esc_html__( 'Send this text to your host’s support. It contains the measured values, and no licence key.', 'smartengin-licence-client' ) . '</p>';
			$html .= '<p><textarea class="large-text code self-host-text" rows="14" readonly aria-label="' . esc_attr__( 'Text for your host', 'smartengin-licence-client' ) . '">'
				. esc_textarea( self::connection_host_text( $r ) ) . '</textarea></p>';
			$html .= '<p><button type="button" class="button self-copy" data-self-copied="' . esc_attr__( 'Copied', 'smartengin-licence-client' ) . '">'
				. esc_html__( 'Copy text', 'smartengin-licence-client' ) . '</button></p>';
		}

		if ( $with_help && '' !== $this->help_url ) {
			$html .= '<p class="self-help"><a href="' . esc_url( $this->help_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Help with connection problems', 'smartengin-licence-client' ) . '</a></p>';
		}

		return $html;
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
			.self-license-panel .self-conn { margin-top:24px; padding-top:8px; border-top:1px solid #dcdcde; }
			.self-license-panel .self-verdict { padding:10px 14px; border-left:4px solid; border-radius:2px; max-width:900px; }
			.self-license-panel .self-verdict--active   { background:#e6f4ea; border-color:#137333; color:#0d5226; }
			.self-license-panel .self-verdict--expired  { background:#fef7e0; border-color:#b06f00; color:#6b4500; }
			.self-license-panel .self-verdict--inactive { background:#fce8e6; border-color:#b3261e; color:#8c1d18; }
			.self-license-panel .self-conn-table { max-width:900px; margin:8px 0 12px; }
			.self-license-panel .self-host-text { max-width:900px; font-size:12px; }
		</style>
		<?php
	}

	/**
	 * Plain-words reason for a server-side block.
	 *
	 * Prefers the server's own wording: it knows exactly which rule was hit and
	 * speaks the vendor's language. Falls back to a generic line when only the
	 * code arrived (e.g. an older server).
	 *
	 * @param array $state Licence state.
	 * @return string
	 */
	private function blocked_text( array $state ) {
		$message = isset( $state['blocked_message'] ) ? (string) $state['blocked_message'] : '';
		if ( '' !== $message ) {
			return $message;
		}
		if ( 'trial_used_on_site' === (string) $state['blocked'] ) {
			return __( 'This website has already used its free trial, so it receives no updates.', 'smartengin-licence-client' );
		}
		return __( 'This website is not registered for this licence, so it receives no updates.', 'smartengin-licence-client' );
	}

	/**
	 * Display name of the host product, for messages the customer reads.
	 *
	 * @return string
	 */
	private function product_name() {
		if ( 'theme' === $this->type ) {
			$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme( $this->stylesheet ) : null;
			return ( $theme && $theme->exists() ) ? (string) $theme->get( 'Name' ) : $this->slug;
		}
		if ( $this->plugin_file && function_exists( 'get_plugin_data' ) && is_file( $this->plugin_file ) ) {
			$data = get_plugin_data( $this->plugin_file, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return (string) $data['Name'];
			}
		}
		return $this->slug;
	}

	/**
	 * Warn on the plugins / updates / themes screens when the licence server
	 * refuses updates for THIS website.
	 *
	 * Without this the customer only ever sees "no update available" and never
	 * learns the reason – the silent failure the server-side activation check
	 * would otherwise introduce. It never disables anything: the product keeps
	 * running, only the update channel is paused.
	 *
	 * @return void
	 */
	public function blocked_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'update-core', 'themes' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'theme' === $this->type ? 'update_themes' : 'update_plugins' ) ) {
			return;
		}

		$state = $this->get_state();
		if ( '' === (string) $state['blocked'] ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html( $this->product_name() . ':' ),
			esc_html( $this->blocked_text( $state ) )
		);
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

		// A block outranks the licence status: the key itself can be perfectly
		// fine while THIS website still receives no updates.
		if ( '' !== (string) $state['blocked'] ) {
			return '<span class="self-status self-status--expired">' . esc_html( $this->blocked_text( $state ) ) . '</span>';
		}

		switch ( $this->effective_status() ) {
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
				if ( ! empty( $state['subscription'] ) ) {
					if ( 'grace' === $this->mode() ) {
						return '<span class="self-status self-status--expired">' . esc_html(
							sprintf(
								/* translators: %s: date the premium features switch off */
								__( 'Expired — the subscription has ended. Premium features stay on until %s, then they switch off. Please renew.', 'smartengin-licence-client' ),
								date_i18n( get_option( 'date_format' ), $this->lock_time() )
							)
						) . '</span>';
					}
					return '<span class="self-status self-status--inactive">' . esc_html__( 'Expired — the subscription has ended and premium features are switched off. Renew it to switch them back on.', 'smartengin-licence-client' ) . '</span>';
				}
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
	 * @param array $args Optional: 'title'; 'connection_test' => false hides the
	 *                    "Check connection" section (0.8.3, default true) for a
	 *                    product that shows the test on its own page.
	 * @return void Echoes markup.
	 */
	public function render_panel( array $args = array() ) {
		$state     = $this->get_state();
		$activated = ! empty( $state['activated'] );
		$title     = isset( $args['title'] ) ? (string) $args['title'] : __( 'Licence', 'smartengin-licence-client' );
		$conn      = ! isset( $args['connection_test'] ) || false !== $args['connection_test'];
		$last_test = $conn ? $this->last_connection_test() : null;
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
			<?php if ( $conn ) : ?>
				<div class="self-conn">
					<h3><?php esc_html_e( 'Connection to the licence server', 'smartengin-licence-client' ); ?></h3>
					<p><?php esc_html_e( 'If activating does not work, this check finds out where the connection breaks. It asks the licence server and, for comparison, WordPress.org — from the server your website runs on. Your licence key is not sent.', 'smartengin-licence-client' ); ?></p>
					<p>
						<button type="button" class="button self-conn-test"><?php esc_html_e( 'Check connection', 'smartengin-licence-client' ); ?></button>
						<span class="spinner self-conn-spinner" style="float:none;"></span>
					</p>
					<p class="self-conn-error" style="color:#b32d2e;margin:0;"></p>
					<div class="self-conn-result"><?php echo $last_test ? wp_kses_post( $this->connection_result_html( $last_test, false ) ) : ''; ?></div>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $this->help_url ) : ?>
				<p class="self-help"><a href="<?php echo esc_url( $this->help_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Help with connection problems', 'smartengin-licence-client' ); ?></a></p>
			<?php endif; ?>
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

			// Connection test (0.8.3).
			var connBtn  = root.querySelector( '.self-conn-test' );
			var connRes  = root.querySelector( '.self-conn-result' );
			var connErr  = root.querySelector( '.self-conn-error' );
			var connSpin = root.querySelector( '.self-conn-spinner' );
			if ( connBtn ) {
				connBtn.addEventListener( 'click', function () {
					connErr.textContent = '';
					connBtn.disabled = true;
					connSpin.classList.add( 'is-active' );
					var body = new URLSearchParams();
					body.append( 'action', slug + '_conn_test' );
					body.append( 'nonce', nonce );
					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							connSpin.classList.remove( 'is-active' );
							connBtn.disabled = false;
							if ( res && res.success ) { connRes.innerHTML = res.data.html; }
							else { connErr.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Error.'; }
						} )
						.catch( function () { connSpin.classList.remove( 'is-active' ); connBtn.disabled = false; connErr.textContent = 'Error.'; } );
				} );
			}

			// "Copy text": clipboard API, else select the field + execCommand.
			root.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest ? e.target.closest( '.self-copy' ) : null;
				if ( ! btn ) { return; }
				var field = root.querySelector( '.self-host-text' );
				if ( ! field ) { return; }
				var label = btn.textContent;
				function copied() {
					btn.textContent = btn.getAttribute( 'data-self-copied' ) || label;
					setTimeout( function () { btn.textContent = label; }, 2000 );
				}
				function fallback() {
					field.focus();
					field.select();
					try { if ( document.execCommand( 'copy' ) ) { copied(); } } catch ( err ) {}
				}
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( field.value ).then( copied, fallback );
				} else {
					fallback();
				}
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
