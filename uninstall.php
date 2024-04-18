<?php
/**
 * Uninstall Script for On Demand Revalidation Plugin.
 *
 * This script is responsible for handling the uninstallation of the On Demand Revalidation plugin.
 * When executed, it removes the plugin's options from the WordPress database.
 *
 * @package OnDemandRevalidation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'on_demand_revalidation_default_settings' );
delete_option( 'on_demand_revalidation_post_update_settings' );
