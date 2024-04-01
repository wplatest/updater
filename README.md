
## WPLatest.co Plugin Updater

This is a simple plugin updater for WordPress plugins. It allows you to check for updates from the WPLatest.co API and display a notice in the WordPress admin area when an update is available.

> [!IMPORTANT]
> This plugin updater is designed to work with plugins hosted on WPLatest.co. However you can modify it to work with any other API.

## Minimum requirements

- PHP: 8.0 or later
- WordPress: 6.0 or later
- Access to your plugin's source code.
- Optional: Composer for managing PHP dependencies

## Installation

To integrate this updater into your plugin, you need to require it via Composer or copy the files into your plugin.

### Via Composer

```bash
composer require wplatest/updater
```

### Manual

Copy the file in `src` directory into your plugin. Then require the file in your plugin.

```php
require_once 'path/to/updater.php';
```

## Usage

Instantiate the `PluginUpdater` class within your plugin, providing it with the necessary configuration options. Here's a basic example you can adjust according to your needs:

```php
<?php

/**
 * Plugin Name:       Example Plugin
 *
 * @package ExamplePlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include the Composer autoloader in your plugin.
require_once 'vendor/autoload.php';

use WpLatest\Updater\PluginUpdater;

/**
 * Include the updater class.
 */
class ExamplePlugin {

	/**
	 * Base constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'initialise' ) );
	}

	/**
	 * Initialize the updater.
	 */
	public function initialise() {
		$options = array(
			'file'      => __FILE__,
			'id'        => 'your-plugin-id-from-wplatest-co',
			// Optional configuration, you don't need to set these if you're using the WPLatest.co API.
			'api_url'   => 'https://wplatest.co/api/v1/plugin/update',
			'hostname'  => 'wplatest.co',
			'telemetry' => true,
		);

    	new PluginUpdater( $options );
	}
}

$wp_latest_test_plugin = new ExamplePlugin();
```

Replace `'your-plugin-id-from-wplatest-co'` with the actual ID provided to you by WPLatest.co for your plugin.