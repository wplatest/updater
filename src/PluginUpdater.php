<?php

declare( strict_types=1 );

namespace WpLatest\Updater;

use InvalidArgumentException;
use stdClass;

/**
 * A class that makes it easy to automatically update WordPress plugins from a remote server.
 * This class is designed to work with the WPLatest.co API, however, it can be modified to work with any API.
 * As the API will be called with the plugin slug and unique ID, the API should return the plugin information
 * in the format as shown below in the `prepare_update_object` method.
 *
 * @author Chris Jayden
 * @link   https://wplatest.co
 * @license GPL-2.0-or-later
 * @package WpLatest\Updater
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
	 * Options to modify the behavior of the updater.
	 *
	 * @property array $options {
	 *     An array of options for the updater.
	 *
	 *     @type file   $file The path to the plugin file.
	 *     @type string $id   The unique ID for the plugin. If you're using WPLatest.co, you can find this ID in the dashboard.
	 *     @type string $hostname The hostname of the site. IMPORTANT: this must match the `Update URI` in the plugin header.
	 *     @type string $api_url The URL to the API endpoint. It sents along the plugin slug and your unique ID.
	 *     @type string $secret The secret key for the API. This is used to verify the request.
	 *     @type bool $telemetry Whether to send anonymous data to the API. Default is true.
	 * }
	 */
	protected array $options;

	/**
	 * The default options for the updater.
	 *
	 * @var array
	 */
	protected array $default_options = array(
		'hostname'  => 'wplatest.co',
		'api_url'   => 'https://wplatest.co/api/v1/plugin/update',
		'telemetry' => true,
	);

	/**
	 * The required options for the updater.
	 *
	 * @var array
	 */
	protected array $required_options = array( 'file', 'id', 'api_url', 'hostname' );

	/**
	 * Updater constructor.
	 *
	 * @param array $options - See the `$options` property for a list of available options.
	 *
	 * @throws InvalidArgumentException If any of the required options are missing.
	 */
	public function __construct( array $options ) {
		$this->options = wp_parse_args( $options, $this->default_options );

		foreach ( $this->required_options as $option ) {
			if ( empty( $this->options[ $option ] ) ) {
				throw new InvalidArgumentException( "The {$option} option is required." );
			}
		}

		$this->plugin_base = plugin_basename( $this->options['file'] );
		$this->plugin_slug = dirname( $this->plugin_base );

		$this->init();
	}

	/**
	 * Initializes the plugin updater by adding the necessary hooks.
	 */
	private function init() {
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( "update_plugins_{$this->options['hostname']}", array( $this, 'check_update' ), 10, 4 );
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
		if ( ! $remote || ! isset( $remote->name ) ) {
			return $update;
		}

		$new_plugin_data    = $this->prepare_update_object( $remote );
		$new_version_number = $new_plugin_data->new_version;
		$has_update         = version_compare( $plugin_data['Version'], $new_version_number, '<' );

		if ( ! $has_update ) {
			return false;
		}

		return array(
			'slug'    => $this->plugin_slug,
			'version' => $new_version_number,
			'url'     => $new_plugin_data->author_profile,
			'package' => $new_plugin_data->package,
			'icons'   => $new_plugin_data->icons,
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
		if ( ! $remote || ! isset( $remote->name ) ) {
			return $result;
		}

		$result = $remote;

		// Convert the sections and banners to arrays instead of objects.
		$result->sections = (array) $result->sections;
		$result->banners  = (array) $result->banners;
		$result->icons    = (array) $result->icons;

		$result->slug = $this->plugin_slug;

		return $result;
	}

	/**
	 * Retrieves the plugin information from the API.
	 */
	protected function request() {
		$headers = array(
			'Accept' => 'application/json',
		);

		if (!empty($this->options['secret'])) {
			$headers['Authorization'] = 'Bearer ' . $this->options['secret'];
		}

		$response = wp_remote_get( $this->get_request_url(), array( 'headers' => $headers ) );

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
				'id'        => rawurlencode( $this->options['id'] ),
				'slug'      => rawurlencode( $this->plugin_slug ),
				'referrer'  => rawurlencode( wp_guess_url() ),
				'telemetry' => rawurlencode( wp_json_encode( $this->get_telemetry_info() ) ),
				'meta'      => rawurlencode( wp_json_encode( array( 'foo' => 'bar' ) ) ),
			),
			$this->options['api_url']
		);
	}

	/**
	 * Retrieves the system information (anonymous data)
	 */
	private function get_telemetry_info() {
		$system_info = array(
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => phpversion(),
			'wp_max_upload'       => wp_max_upload_size(),
			'wp_default_timezone' => get_option( 'timezone_string' ),
			'wp_lang'             => get_locale(),
		);

		return $this->options['telemetry'] ? $system_info : array();
	}

	/**
	 * Prepares the update object.
	 */
	private function prepare_update_object( $remote, $has_update = true ): stdClass {
		$update_obj                 = new stdClass();
		$update_obj->name           = $remote->name;
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

		if ( isset( $remote->icons ) ) {
			$update_obj->icons = (array) $remote->icons;
		} else {
			$update_obj->icons = array();
		}

		if ( $has_update ) {
			$update_obj->new_version = $remote->version;
			$update_obj->package     = $remote->download_url;
		}

		return $update_obj;
	}
}
