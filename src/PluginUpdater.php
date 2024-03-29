<?php

declare( strict_types=1 );

namespace WpLatest\Updater;

use InvalidArgumentException;
use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Updater
 */
class PluginUpdater {
	/**
	 * The base name of the plugin.
	 *
	 * @var string
	 */
	protected string $plugin_base;

	/**
	 * The slug of the plugin.
	 *
	 * @var string
	 */
	protected string $plugin_slug;

	/**
	 * The URL to the API endpoint.
	 *
	 * @var string
	 */
	protected string $api_url;

	/**
	 * The cache key for the plugin.
	 *
	 * @var string
	 */
	protected string $cache_key;

	/**
	 * The version of the plugin.
	 *
	 * @var string
	 */
	protected string $version;

	/**
	 * The plugin ID on WPLatest.
	 *
	 * @var string
	 */
	protected string $wplatest_plugin_id;

	/**
	 * Whether to use the cache.
	 *
	 * @var bool
	 */
	protected bool $use_cache;

	/**
	 * Updater constructor.
	 *
	 * @param string      $file              The main plugin file.
	 * @param string      $api_url           The URL to the API endpoint.
	 * @param string      $wplatest_plugin_id The plugin ID on WPLatest.
	 * @param string|null $version           Optional. The version of the plugin.
	 * @param bool|null   $use_cache         Optional. Whether to use the cache. Default is true.
	 *
	 * @throws InvalidArgumentException If the file or API URL is empty.
	 */
	public function __construct(
		string $file,
		string $api_url,
		string $wplatest_plugin_id,
		?string $version = null,
		?bool $use_cache = false
	) {
		if ( empty( $file ) || empty( $api_url ) ) {
			throw new InvalidArgumentException( 'File and API URL cannot be empty.' );
		}

		$this->plugin_base        = plugin_basename( $file );
		$this->plugin_slug        = dirname( $this->plugin_base );
		$this->api_url            = trim( $api_url );
		$this->wplatest_plugin_id = trim( $wplatest_plugin_id );
		$this->version            = $version ?? $this->get_plugin_version( $file );
		$this->cache_key          = $this->create_cache_key( $this->plugin_slug );
		$this->use_cache          = $use_cache;

		$this->init();
	}

	/**
	 * Retrieves the version of the plugin from the plugin file.
	 */
	public function get_plugin_version( string $plugin_file ): ?string {

		// Check if the get_plugin_data function doesn't exist and include the plugin.php file if necessary.
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get the plugin data from the specified file.
		$plugin_data = get_plugin_data( $plugin_file );

		// Check if the version information exists in the plugin data.
		if ( ! empty( $plugin_data ) && isset( $plugin_data['Version'] ) ) {
			return trim( $plugin_data['Version'] );
		}

		return null;
	}

	/**
	 * Converts a string to a slug.
	 *
	 * @param string $name The string to convert to a slug.
	 */
	public function create_cache_key( string $name ): string {
		return sanitize_title( $name . '-wplatest' );
	}

	/**
	 * Initializes the plugin updater by adding the necessary hooks.
	 */
	private function init() {
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
	}

	/**
	 * Retrieves the plugin information from the API.
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$remote = $this->request();
		if ( ! $remote || ! isset( $remote->name, $remote->slug ) ) {
			return $result;
		}

		$result = $remote;

		// Convert the sections and banners to arrays instead of objects.
		$result->sections = (array) $result->sections;
		$result->banners  = (array) $result->banners;

		return $result;
	}

	/**
	 * Checks for plugin updates.
	 */
	public function update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->request();

		if ( $remote && ! empty( $remote->version ) && version_compare( $this->version, $remote->version, '<' ) ) {
			$transient->response[ $this->plugin_base ] = $this->prepare_update_object( $remote );
		} else {
			$transient->no_update[ $this->plugin_base ] = $this->prepare_update_object( $remote, false );
		}

		return $transient;
	}

	/**
	 * Purges the cache when a plugin is updated.
	 */
	public function purge( $upgrader, $options ) {
		if ( $this->use_cache && 'update' === $options['action'] && 'plugin' === $options['type'] && in_array( $this->plugin_base, $options['plugins'], true ) ) {
			delete_transient( $this->cache_key );
		}
	}

	/**
	 * Retrieves the plugin information from the API.
	 */
	protected function request() {
		$remote = get_transient( $this->cache_key );
		if ( false !== $remote && $this->use_cache ) {
			return json_decode( $remote ) ?? false;
		}

		$remote = $this->get_remote_update_info();
		if ( false === $remote ) {
			return false;
		}

		set_transient( $this->cache_key, wp_json_encode( $remote ), DAY_IN_SECONDS );

		return $remote;
	}

	/**
	 * Fetches the update information from the remote server.
	 */
	private function get_remote_update_info() {
		$response = wp_remote_get( $this->get_request_url() );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			// phpcs:ignore
			error_log( 'WPLatest API request failed: ' . wp_json_encode( $response ) );

			return false;
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Builds the request URL for the API.
	 */
	private function get_request_url(): string {
		return add_query_arg(
			array(
				'id'        => rawurlencode( $this->wplatest_plugin_id ),
				'slug'      => rawurlencode( $this->plugin_slug ),
				'version'   => rawurlencode( $this->version ),
				'referrer'  => rawurlencode( wp_guess_url() ),
				'telemetry' => rawurlencode( wp_json_encode( $this->get_wp_anonymous_telemetry() ) ),
				'meta'      => rawurlencode( wp_json_encode( array( 'foo' => 'bar' ) ) ),
			),
			$this->api_url
		);
	}

	/**
	 * Retrieves the system information (anonymous data)
	 */
	private function get_wp_anonymous_telemetry() {
		$system_info = array(
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => phpversion(),
			'wp_max_upload'       => wp_max_upload_size(),
			'wp_default_timezone' => get_option( 'timezone_string' ),
			'wp_lang'             => get_locale(),
		);

		return $system_info;
	}

	/**
	 * Prepares the update object.
	 */
	private function prepare_update_object( $remote, $has_update = true ): stdClass {
		$update_obj                 = new stdClass();
		$update_obj->name           = $remote->name;
		$update_obj->slug           = $remote->slug;
		$update_obj->version        = $remote->version;
		$update_obj->tested         = $remote->tested;
		$update_obj->requires       = $remote->requires;
		$update_obj->author         = $remote->author;
		$update_obj->author_profile = $remote->author_profile;
		$update_obj->download_link  = $remote->download_url;
		$update_obj->trunk          = $remote->download_url;
		$update_obj->requires_php   = $remote->requires_php;
		$update_obj->last_updated   = $remote->last_updated;

		if ( isset( $remote->sections ) ) {
			$update_obj->sections = (array) $remote->sections;
		} else {
			$update_obj->sections = array();
		}

		if ( isset( $remote->banners ) ) {
			$update_obj->banners = (array) $remote->banners;
		} else {
			$update_obj->banners = array();
		}

		if ( $has_update ) {
			$update_obj->new_version = $remote->version;
			$update_obj->package     = $remote->download_url;
		}

		return $update_obj;
	}
}
