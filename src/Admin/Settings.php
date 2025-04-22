<?php
/**
 * Settings Class
 *
 * This class handles the initialization and management of plugin settings.
 *
 * @package OnDemandRevalidation
 */

namespace OnDemandRevalidation\Admin;

use OnDemandRevalidation\Admin\SettingsRegistry;
use OnDemandRevalidation\Revalidation;

/**
 * Class Settings
 *
 * This class handles the initialization and management of plugin settings.
 *
 * @package OnDemandRevalidation
 */
class Settings {

	/**
	 * The settings registry
	 *
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
		add_action( 'admin_menu', array( $this, 'add_options_page' ) );
		add_action( 'init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'initialize_settings_page' ) );

		if ( is_admin() ) {
			Revalidation::test_revalidation_button();
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
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the settings fields
	 *
	 * @return void
	 */
	public function register_settings() {

		$this->settings_api->register_section(
			'on_demand_revalidation_default_settings',
			array(
				'title' => __( 'General', 'on-demand-revalidation' ),
			)
		);

		$this->settings_api->register_fields(
			'on_demand_revalidation_default_settings',
			array(
				array(
					'name'  => 'frontend_url',
					'label' => __( 'Next.js URL', 'on-demand-revalidation' ),
					'type'  => 'text',
				),
				array(
					'name'  => 'revalidate_secret_key',
					'label' => __( 'Revalidate Secret Key', 'on-demand-revalidation' ),
					'type'  => 'password',
				),

				array(
					'name' => 'disable_cron',
					'desc' => __( "<b>Disable scheduled revalidation.</b> Revalidation triggered immediately without using WP-Cron. It'll slow down post update.", 'on-demand-revalidation' ),
					'type' => 'checkbox',
				),

				array(
					'name'  => 'test-config',
					'label' => __( 'Test your config:', 'on-demand-revalidation' ),
					'desc'  => '<a id="on-demand-revalidation-post-update-test" class="button button-primary" style="margin-bottom: 15px;">Revalidate Latest Post</a>',
					'type'  => 'html',
				),
			)
		);

		$this->settings_api->register_section(
			'on_demand_revalidation_post_update_settings',
			array(
				'title' => __( 'All Settings', 'on-demand-revalidation' ),
				'desc'  => __( 'Settings that run on every update for any post type.', 'on-demand-revalidation' ),
			)
		);

		$this->settings_api->register_fields(
			'on_demand_revalidation_post_update_settings',
			array(
				array(
					'name'    => 'revalidate_homepage',
					'desc'    => __( 'Revalidate Homepage on post update', 'on-demand-revalidation' ),
					'type'    => 'checkbox',
					'default' => 'on',
				),

				array(
					'name'        => 'revalidate_paths',
					'label'       => __( 'Additional paths to revalidate on all updates', 'on-demand-revalidation' ),
					'desc'        => 'One path per row.',
					'placeholder' => '/category/%category%',
					'type'        => 'textarea',
				),

				array(
					'name'        => 'revalidate_tags',
					'label'       => __( 'Tags to revalidate on all updates', 'on-demand-revalidation' ),
					'desc'        => 'One tag per row.<br/><br/><i>Available current Post placeholders:</i><br/><code>%slug%</code> <code>%author_nicename%</code> <code>%author_username%</code> <code>%category%</code> <code>%post_tag%</code><code>%database_id%</code> <code>%id%</code> <code>%custom_taxonomy%</code><br/><br/><i>Note:</i> Replace <code>%custom_taxonomy%</code> with your custom taxonomy name.',
					'placeholder' => '%databaseid%',
					'type'        => 'textarea',
				),


			)
		);

		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		foreach ( $post_types as $post_type_obj ) {

			if ( 'attachment' === $post_type_obj->name ) {
				continue;
			}
			$section_id = 'on_demand_revalidation_' . $post_type_obj->name . '_settings';
			$this->settings_api->register_section(
				$section_id,
				array(
					// translators: %s: plural name of the post type, e.g. "Posts" or "Books".
					'title' => sprintf( __( '%s Settings', 'on-demand-revalidation', ), $post_type_obj->labels->name ),
					// translators: %s: singular name of the post type, e.g. "Post" or "Book".
					'desc'  => sprintf( __( 'Revalidation settings specific to %s posts.', 'on-demand-revalidation', ), $post_type_obj->labels->singular_name ),
				)
			);
			$this->settings_api->register_fields(
				$section_id,
				array(
					array(
						'name'    => 'revalidate_enabled_' . $post_type_obj->name,
						// translators: %s: singular post type name, used in the checkbox label.
						'desc'    => sprintf( __( 'Enable revalidation for %s', 'on-demand-revalidation' ), $post_type_obj->labels->singular_name ),
						'type'    => 'checkbox',
						'default' => 'on',
					),
					array(
						'name'        => 'revalidate_paths_' . $post_type_obj->name,
						// translators: %s: singular post type name, used in the textarea label.
						'label'       => sprintf( __( 'Additional paths for %s', 'on-demand-revalidation' ), $post_type_obj->labels->singular_name ),
						'desc'        => __( 'One path per row. Leave empty if not applicable.', 'on-demand-revalidation' ),
						'placeholder' => '/custom/path',
						'type'        => 'textarea',
					),
					array(
						'name'        => 'revalidate_tags_' . $post_type_obj->name,
						// translators: %s: singular post type name, used in the textarea label.
						'label'       => sprintf( __( 'Tags for %s', 'on-demand-revalidation' ), $post_type_obj->labels->singular_name ),
						'desc'        => __( 'One tag per row. Supports placeholders.', 'on-demand-revalidation' ),
						'placeholder' => '%id%',
						'type'        => 'textarea',
					),
					array(
						'name'    => 'revalidate_homepage_' . $post_type_obj->name,
						// translators: %s: singular post type name, used in the textarea label.
						'desc'    => sprintf( __( 'Revalidate Homepage on all updates for %s', 'on-demand-revalidation' ), $post_type_obj->labels->singular_name ),
						'type'    => 'checkbox',
						'default' => 'on',
					),
				)
			);
		}
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
	 *
	 * @param string $option_name The name of the option.
	 * @param mixed  $default_value     The default value if option is not found.
	 * @param string $section_name The name of the settings section.
	 * @return mixed The option value.
	 */
	public static function get( string $option_name, $default_value = '', $section_name = 'on_demand_revalidation_default_settings' ) {

		$section_fields = get_option( $section_name );

		return isset( $section_fields[ $option_name ] ) ? $section_fields[ $option_name ] : $default_value;
	}
}
