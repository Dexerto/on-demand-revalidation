<?php
/**
 * On-Demand Revalidation
 *
 * Plugin Name:         On-Demand Revalidation
 * Plugin URI:          https://wordpress.org/plugins/on-demand-revalidation
 * GitHub Plugin URI:   https://github.com/dexerto/on-demand-revalidation
 * Description:         Next.js On-Demand Revalidation on the post update, revalidate specific paths, tags on the post update.
 * Version:             1.2.4
 * Author:              Dexerto
 * Author URI:          https://dexerto.com
 * Text Domain:         on-demand-revalidation
 * License:             GPL-3
 * License URI:         https://www.gnu.org/licenses/gpl-3.0.html
 * 
 * @package OnDemandRevalidation
 */

use OnDemandRevalidation\Admin\Settings;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


if ( ! class_exists( 'OnDemandRevalidation' ) ) :

	/**
	 * This is the one true OnDemandRevalidation class
	 */
	final class OnDemandRevalidation {


		/**
		 * Stores the instance of the OnDemandRevalidation class
		 *
		 * @since 0.0.1
		 *
		 * @var OnDemandRevalidation The one true OnDemandRevalidation
		 */
		private static $instance;

		/**
		 * The instance of the OnDemandRevalidation object
		 *
		 * @since 0.0.1
		 *
		 * @return OnDemandRevalidation The one true OnDemandRevalidation
		 */
		public static function instance(): self {
			if ( ! isset( self::$instance ) && ! ( is_a( self::$instance, __CLASS__ ) ) ) {
				self::$instance = new self();
				self::$instance->setup_constants();
				if ( self::$instance->includes() ) {
					self::$instance->settings();
					self::$instance->revalidation();
					self::$instance->plugin_links();

					\OnDemandRevalidation\Helpers::prevent_wrong_api_url();
				}
			}

			/**
			 * Fire off init action.
			 *
			 * @param OnDemandRevalidation $instance The instance of the OnDemandRevalidation class
			 */
			do_action( 'on_demand_revalidation_init', self::$instance );

			// Return the OnDemandRevalidation Instance.
			return self::$instance;
		}

		/**
		 * Throw error on object clone.
		 * The whole idea of the singleton design pattern is that there is a single object
		 * therefore, we don't want the object to be cloned.
		 *
		 * @since 0.0.1
		 */
		public function __clone() {

			// Cloning instances of the class is forbidden.
			_doing_it_wrong(
				__FUNCTION__,
				esc_html__(
					'The OnDemandRevalidation class should not be cloned.',
					'on-demand-revalidation'
				),
				'0.0.1'
			);
		}

		/**
		 * Disable unserializing of the class.
		 *
		 * @since 0.0.1
		 */
		public function __wakeup() {

			// De-serializing instances of the class is forbidden.
			_doing_it_wrong(
				__FUNCTION__,
				esc_html__(
					'De-serializing instances of the OnDemandRevalidation class is not allowed.',
					'on-demand-revalidation'
				),
				'0.0.1'
			);
		}

		/**
		 * Setup plugin constants.
		 *
		 * @since 0.0.1
		 */
		private function setup_constants(): void {

			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Plugin version.
			if ( ! defined( 'ON_DEMAND_REVALIDATION_VERSION' ) ) {
				define( 'ON_DEMAND_REVALIDATION_VERSION', get_plugin_data( __FILE__ )['Version'] );
			}

			// Plugin Folder Path.
			if ( ! defined( 'ON_DEMAND_REVALIDATION_PLUGIN_DIR' ) ) {
				define( 'ON_DEMAND_REVALIDATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
			}

			// Plugin Folder URL.
			if ( ! defined( 'ON_DEMAND_REVALIDATION_PLUGIN_URL' ) ) {
				define( 'ON_DEMAND_REVALIDATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
			}

			// Plugin Root File.
			if ( ! defined( 'ON_DEMAND_REVALIDATION_PLUGIN_FILE' ) ) {
				define( 'ON_DEMAND_REVALIDATION_PLUGIN_FILE', __FILE__ );
			}

			// Whether to autoload the files or not.
			if ( ! defined( 'ON_DEMAND_REVALIDATION_AUTOLOAD' ) ) {
				define( 'ON_DEMAND_REVALIDATION_AUTOLOAD', true );
			}
		}

		/**
		 * Uses composer's autoload to include required files.
		 *
		 * @since 0.0.1
		 *
		 * @return bool
		 */
		private function includes(): bool {

			// Autoload Required Classes.
			if ( defined( 'ON_DEMAND_REVALIDATION_AUTOLOAD' ) && false !== ON_DEMAND_REVALIDATION_AUTOLOAD ) {
				if ( file_exists( ON_DEMAND_REVALIDATION_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
					require_once ON_DEMAND_REVALIDATION_PLUGIN_DIR . 'vendor/autoload.php';
				}

				// Bail if installed incorrectly.
				if ( ! class_exists( '\OnDemandRevalidation\Admin\Settings' ) ) {
					add_action( 'admin_notices', array( $this, 'missing_notice' ) );
					return false;
				}
			}

			return true;
		}

		/**
		 * Composer dependencies missing notice.
		 *
		 * @since 0.0.1
		 */
		public function missing_notice(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			} ?>
			<div class="notice notice-error">
				<p>
					<?php esc_html_e( 'On-Demand Revalidation appears to have been installed without its dependencies. It will not work properly until dependencies are installed. This likely means you have cloned Next.js On-Demand Revalidation from Github and need to run the command `composer install`.', 'on-demand-revalidation' ); ?>
				</p>
			</div>
			<?php
		}

		/**
		 * Set up settings.
		 *
		 * @since 0.0.1
		 */
		private function settings(): void {

			$settings = new Settings();
			$settings->init();
		}

		/**
		 * Set up Purge.
		 *
		 * @since 0.0.1
		 */
		private function revalidation(): void {
			\OnDemandRevalidation\Revalidation::init();
		}


		/**
		 * Set up Action Links.
		 *
		 * @since 0.0.1
		 */
		private function plugin_links(): void {

			// Setup Settings link.
			add_filter(
				'plugin_action_links_' . plugin_basename( __FILE__ ),
				function ( $links ) {
					$links[] = '<a href="/wp-admin/admin.php?page=on-demand-revalidation">Settings</a>';

					return $links;
				}
			);
		}
	}

endif;

\OnDemandRevalidation::instance();
