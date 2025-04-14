<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Plugin_Dev
 */

// Load the composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define constants.
define( 'PLUGIN_DIR', dirname( __DIR__ ) );
define( 'PLUGIN_FILE', PLUGIN_DIR . '/on-demand-revalidation.php' );

// Initialize WP_Mock.
WP_Mock::bootstrap();
