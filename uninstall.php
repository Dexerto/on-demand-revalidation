<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'on_demand_revalidation_default_settings' );
delete_option( 'on_demand_revalidation_post_update_settings' );
