<?php

declare( strict_types=1 );

namespace WpLatest\Updater;

use InvalidArgumentException;
use stdClass;


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
	 * The plugin ID on WPLatest.
	 *
	 * @var string
	 */
	protected string $wplatest_plugin_id;

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
	) {
		if ( empty( $file ) || empty( $api_url ) ) {
			throw new InvalidArgumentException( 'File and API URL cannot be empty.' );
		}

		$this->plugin_base        = plugin_basename( $file );
		$this->plugin_slug        = dirname( $this->plugin_base );
		$this->api_url            = trim( $api_url );
		$this->wplatest_plugin_id = trim( $wplatest_plugin_id );

		$this->init();
	}

	/**
	 * Initializes the plugin updater by adding the necessary hooks.
	 */
	private function init() {
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'update_plugins_wplatest.co', array( $this, 'check_update' ), 10, 4 );
	}

	/**
	 * Check for updates to this plugin
	 *
	 * @param array  $update   Array of update data.
	 * @param array  $plugin_data Array of plugin data.
	 * @param string $plugin_file Path to plugin file.
	 * @param string $locales    Locale code.
	 *
	 * @return array|bool Array of update data or false if no update available.
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file, $locales ) {
		if ( $this->plugin_base !== $plugin_file ) {
			return $update;
		}

		// Already completed the update.
		if ( ! empty( $update ) ) {
			return $update;
		}

		$remote = $this->request();
		if ( ! $remote || ! isset( $remote->name, $remote->slug ) ) {
			return $update;
		}

		$new_plugin_data    = $this->prepare_update_object( $remote );
		$new_version_number = $new_plugin_data->new_version;
		$has_update         = version_compare( $plugin_data['Version'], $new_version_number, '<' );

		if ( ! $has_update ) {
			return false;
		}

		return array(
			'slug'    => $new_plugin_data->slug,
			'version' => $new_version_number,
			'url'     => $new_plugin_data->author_profile,
			'package' => $new_plugin_data->package,
		);
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
	 * Retrieves the plugin information from the API.
	 */
	protected function request() {
		$response = wp_remote_get( $this->get_request_url() );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			// phpcs:ignore
			error_log( 'WPLatest API request failed: ' . wp_json_encode( $response ) );

			return false;
		}

		$remote = json_decode( wp_remote_retrieve_body( $response ) );

		if ( false === $remote ) {
			return false;
		}

		return $remote;
	}

	/**
	 * Builds the request URL for the API.
	 */
	private function get_request_url(): string {
		return add_query_arg(
			array(
				'id'        => rawurlencode( $this->wplatest_plugin_id ),
				'slug'      => rawurlencode( $this->plugin_slug ),
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
