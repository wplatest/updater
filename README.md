
## WPLatest.co Plugin Updater

This is a simple plugin updater for WordPress plugins. It allows you to check for updates from the WPLatest.co API and display a notice in the WordPress admin area when an update is available.

> [!IMPORTANT]
> WPLatest.co is in beta, and the API is subject to change. This plugin updater is designed to work with plugins hosted on WPLatest.co. It will not work with plugins hosted on the WordPress.org plugin repository or other your own custom update server. If you need to update plugins from other sources, you should use the [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)

## Minimum requirements

- PHP: 8.0 or later
- WordPress: 6.0 or later

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

To use the updater, you need to create an instance of the `PluginUpdater` class. The constructor of the class accepts four parameters:

1. The path to the main plugin file.
2. The endpoint URL to check for updates. This is the URL of the WPLatest.co API.
3. Your plugin's ID (get this from the WPLatest.co dashboard).
4. The current version of the plugin. This is optional and defaults to the plugin's version.

Your plugin slug is automatically detected from the plugin file path (parameter 1). The plugin slug is used to check for updates. Make sure it matches the slug you used when creating the plugin on WPLatest.co.

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

use WPLatest\Updater\PluginUpdater;

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
		new Updater(
			__FILE__,
			'https://wplatest.co/api/v1/plugin/update', // The endpoint URL to check for updates. This is the URL of the WPLatest.co API.
			'plugin_xxxx', // Your plugin's ID (get this from the WPLatest.co dashboard)
			null, // Optional. Defaults to plugin's version.
		);
	}
}

$wp_latest_test_plugin = new ExamplePlugin();
```