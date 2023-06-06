<?php

namespace OnDemandRevalidation\Admin;

use OnDemandRevalidation\Admin\SettingsRegistry;
use OnDemandRevalidation\Revalidation;

class Settings {

	/**
	 * @var SettingsRegistry
	 */
	public $settings_api;


	/**
	 * Initialize the Settings Pages
	 *
	 * @return void
	 */
	public function init() {
		$this->settings_api = new SettingsRegistry();
		add_action( 'admin_menu', [ $this, 'add_options_page' ] );
		add_action( 'init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'initialize_settings_page' ] );

		if ( is_admin() ) {
			Revalidation::testRevalidationButton();
		}
	}


	/**
	 * Add the options page to the WP Admin
	 *
	 * @return void
	 */
	public function add_options_page() {

		add_options_page(
			__( 'Next.js On-Demand Revalidation', 'on-demand-revalidation' ),
			__( 'Next.js On-Demand Revalidation', 'on-demand-revalidation' ),
			'manage_options',
			'on-demand-revalidation',
			[ $this, 'render_settings_page' ]
		);

	}

	/**
	 * Registers the settings fields
	 *
	 * @return void
	 */
	public function register_settings() {

		$this->settings_api->register_section( 'on_demand_revalidation_default_settings', [
			'title' => __( 'General', 'on-demand-revalidation' ),
		] );

		$this->settings_api->register_fields( 'on_demand_revalidation_default_settings', [
			[
				'name'  => 'frontend_url',
				'label' => __( 'Next.js URL', 'on-demand-revalidation' ),
				'type'  => 'text',
			],
			[
				'name'  => 'revalidate_secret_key',
				'label' => __( 'Revalidate Secret Key', 'on-demand-revalidation' ),
				'type'  => 'password',
			],
		] );

		$this->settings_api->register_section( 'on_demand_revalidation_post_update_settings', [
			'title' => __( 'On post update', 'on-demand-revalidation' ),
			'desc'  => __( 'On post update is current page revalidated automatically.', 'on-demand-revalidation' ),
		] );

		$this->settings_api->register_fields( 'on_demand_revalidation_post_update_settings', [
			[
				'name'    => 'revalidate_homepage',
				'desc'    => __( 'Revalidate Homepage on post update', 'on-demand-revalidation' ),
				'type'    => 'checkbox',
				'default' => 'on',
			],
			[
				'name' => 'disable_cron',
				'desc' => __( "<b>Disable scheduled revalidation.</b> Revalidation triggered immediately without using WP-Cron. It'll slow down post update.", 'on-demand-revalidation' ),
				'type' => 'checkbox',
			],
			[
				'name'        => 'revalidate_paths',
				'label'       => __( 'Additional paths to revalidate on Post update', 'on-demand-revalidation' ),
				'desc'        => 'One path per row.<br/><br/><i>Available current Post placeholders:</i><br/><code>%slug%</code> <code>%author_nicename%</code> <code>%author_username%</code> <code>%category%</code> <code>%post_tag%</code> <code>%custom_taxonomy%</code><br/><br/><i>Note:</i> Replace <code>%custom_taxonomy%</code> with your custom taxonomy name.',
				'placeholder' => '/category/%category%',
				'type'        => 'textarea',
			],
			[
				'name'  => 'test-config',
				'label' => __( 'Test your config:', 'on-demand-revalidation' ),
				'desc'  => '<a id="on-demand-revalidation-post-update-test" class="button button-primary" style="margin-bottom: 15px;">Revalidate Latest Post</a>',
				'type'  => 'html',
			],
		] );

	}

	/**
	 * Initialize the settings admin page
	 *
	 * @return void
	 */
	public function initialize_settings_page() {
		$this->settings_api->admin_init();
	}

	/**
	 * Render the settings page in the admin
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
		}

		?>
		<div class="wrap">
			<?php
			echo '<h1>Next.js On-Demand Revalidation</h1>';
			$this->settings_api->show_navigation();
			$this->settings_api->show_forms();
			?>
		</div>
		<?php
	}

	/**
	 * Get field value
	 */
	public static function get( string $option_name, $default = '', $section_name = 'on_demand_revalidation_default_settings' ) {

		$section_fields = get_option( $section_name );

		return isset( $section_fields[ $option_name ] ) ? $section_fields[ $option_name ] : $default;
	}

}
