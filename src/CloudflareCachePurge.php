<?php
/**
 * CloudflareCachePurge Class
 *
 * This class handles the revalidation of cloudclafre's tags and paths.
 *
 * @package OnDemandRevalidation
 */

namespace OnDemandRevalidation;

/**
 * Class CloudflareCachePurge
 *
 * This class handles the revalidation of cloudclafre's tags and paths.
 *
 * @package OnDemandRevalidation
 */
class CloudflareCachePurge {

	/**
	 * Initialize the CloudflareCachePurge revalidation.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'save_post', array( self::class, 'handle_post_update' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'handle_transition_post_status' ), 10, 3 );
		add_action( 'on_demand_revalidation_cloudflare_on_post_update', array( self::class, 'cron_purge' ) );
	}

	/**
	 * Validate Cloudflare API Token and Zone ID
	 *
	 * @param string $zone_id Cloudflare Zone ID.
	 * @param string $api_token Cloudflare API Token.
	 * @return bool True if valid, false otherwise
	 */
	public static function validate_token_zone_id( $zone_id, $api_token ) {
	
		$api_token_verify_url = 'https://api.cloudflare.com/client/v4/user/tokens/verify';
		$headers              = array(
			'Authorization' => 'Bearer ' . $api_token,
			'Content-Type'  => 'application/json',
		);
	
		// Verify the token.
		$api_token_verify_response = wp_remote_request(
			$api_token_verify_url,
			array(
				'method'  => 'GET',
				'headers' => $headers,
				'timeout' => 10, //phpcs:ignore
			)
		);
	
	
		if ( is_wp_error( $api_token_verify_response ) ) {
			return false;
		}
	
		$api_token_verified_body = wp_remote_retrieve_body( $api_token_verify_response );
		$api_token_verified_data = json_decode( $api_token_verified_body, true );
	
		if ( ! isset( $api_token_verified_data['success'] ) || 1 !== (int) $api_token_verified_data['success'] ) {
			return false;
		}
			
		// Validate Zone ID.
		$zone_url      = 'https://api.cloudflare.com/client/v4/zones?status=active';  
		$zone_response = wp_remote_request(
			$zone_url,
			array(
				'method'  => 'GET',
				'headers' => $headers,
				'timeout' => 10, //phpcs:ignore 
			)
		);
	
		if ( is_wp_error( $zone_response ) ) {
			return false;
		}
	
		$zone_body = wp_remote_retrieve_body( $zone_response );
		$zone_data = json_decode( $zone_body, true );
	

		if ( ! isset( $zone_data['success'] ) || 1 !== (int) $zone_data['success'] ) {
			return false;
		}

	  
		$zone_found = false;
		foreach ( $zone_data['result'] as $zone ) {
			if ( $zone['id'] === $zone_id ) {
				$zone_found = true;
				break;
			}
		}
	
		if ( ! $zone_found ) {
			return false;
		}

		return true;
	}
	
	/**
	 * Runs the purge process using the current settings and a provided post.
	 *
	 * @param \WP_Post $post The post object used for placeholder replacement.
	 * @return array The result from purge_cache().
	 */
	public static function run_purge( $post ) {
			$settings = get_option( 'on_demand_revalidation_cloudflare_settings' );


		if ( ! isset( $settings['cloudflare_cache_purge_enabled'] ) || 'on' !== $settings['cloudflare_cache_purge_enabled'] ) {
			return array(
				'success' => false,
				'message' => 'Cloudflare cache purge is disabled.',
			);
		}


			$zone_id   = $settings['cloudflare_zone_id'] ?? '';
			$api_token = $settings['cloudflare_api_token'] ?? '';
			$paths     = isset( $settings['cloudflare_cache_purge_paths'] ) ? explode( "\n", trim( $settings['cloudflare_cache_purge_paths'] ) ) : array();
			$tags      = isset( $settings['cloudflare_cache_purge_tags'] ) ? explode( "\n", trim( $settings['cloudflare_cache_purge_tags'] ) ) : array();

			// If no paths/tags are configured, return early.
		if ( empty( $paths ) && empty( $tags ) ) {
				return array(
					'success' => false,
					'message' => 'No paths or tags provided for cache purging.',
				);
		}

			// Validate credentials.
		if ( ! self::validate_token_zone_id( $zone_id, $api_token ) ) {
				return array(
					'success' => false,
					'message' => 'Invalid Cloudflare API Token or Zone ID. Cache purge disabled.',
				);
		}

			return self::purge_cache( $zone_id, $api_token, $paths, $tags, $post );
	}

	/**
	 * Purges the Cloudflare cache for the specified paths and tags, with dynamic placeholder replacement.
	 *
	 * @param string   $zone_id   The Cloudflare zone ID.
	 * @param string   $api_token The Cloudflare API token.
	 * @param array    $paths     An array of URLs (paths) to purge (can contain placeholders).
	 * @param array    $tags      An array of tags to purge (can contain placeholders).
	 * @param \WP_Post $post      The WordPress post object used to replace placeholders in paths and tags.
	 *
	 * @return array An array containing the success status and a message.
	 */
	public static function purge_cache( $zone_id, $api_token, $paths = array(), $tags = array(), $post = null ) {
		if ( $post instanceof \WP_Post ) {
				$paths = Helpers::rewrite_placeholders( $paths, $post );
				$tags  = Helpers::rewrite_placeholders( $tags, $post );
		}

			// Ensure full URLs for paths if required by Cloudflare.
			$site_url = get_site_url();
			$paths    = array_map(
				function ( $path ) use ( $site_url ) {
					return ( str_starts_with( $path, '/' ) ) ? trailingslashit( $site_url ) . ltrim( $path, '/' ) : $path;
				},
				$paths 
			);

			$purge_data = array();
		if ( ! empty( $paths ) ) {
				$purge_data['files'] = array_values( array_unique( $paths ) );
		}
		if ( ! empty( $tags ) ) {
				$purge_data['tags'] = array_values( array_unique( $tags ) );
		}

			$purge_url = "https://api.cloudflare.com/client/v4/zones/$zone_id/purge_cache";
			$headers   = array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			);

			$response = wp_remote_post(
				$purge_url,
				array(
					'method'  => 'POST',
					'headers' => $headers,
					'body'    => wp_json_encode( $purge_data ),
					'timeout' => 10, //phpcs:ignore 
				)
			);

		if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'message' => 'Cloudflare cache purge request failed: ' . $response->get_error_message(),
				);
		}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

		if ( isset( $data['success'] ) && true === $data['success'] ) {
			return array(
				'success' => true,
				'message' => 'Cloudflare cache purged successfully.',
			);
		} else {
				$error_message = ! empty( $data['errors'] ) ? wp_json_encode( $data['errors'] ) : 'Unknown error occurred.';
				return array(
					'success' => false,
					'message' => 'Cloudflare cache purge failed: ' . $error_message,
				);
		}
	}


		/**
		 * Automatically handle Cloudflare cache purging on post update.
		 *
		 * This function is hooked into the 'save_post' action.
		 *
		 * @param int      $post_ID The post ID.
		 * @param \WP_Post $post    The post object.
		 */
	public static function handle_post_update( $post_ID, $post ) {
			$excluded_statuses = array( 'auto-draft', 'inherit', 'draft', 'trash' );
		
		if ( isset( $post->post_status ) && in_array( $post->post_status, $excluded_statuses, true ) ) {
			return;
		}
		
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
	
		if ( false !== wp_is_post_revision( $post_ID ) ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { 
			return; 
		}
		self::purge_post( $post );
	}



		/**
		 * Checks the "schedule on post update" setting and either purges immediately or schedules a cron event.
		 *
		 * @param \WP_Post $post The post object.
		 */
	public static function purge_post( $post ) {
			$settings = get_option( 'on_demand_revalidation_cloudflare_settings' );
	
		if ( isset( $settings['cloudflare_schedule_on_post_update'] ) && 'on' === $settings['cloudflare_schedule_on_post_update'] ) {
		
				wp_schedule_single_event( time(), 'on_demand_revalidation_cloudflare_on_post_update', array( $post->ID ) );
		} else {

				self::run_purge( $post );
		}
	}

		/**
		 * Cron callback: Purges the cache for a post given its ID.
		 *
		 * @param int $post_ID The post ID.
		 */
	public static function cron_purge( int $post_ID ) {
			$post = get_post( $post_ID );
		if ( $post ) {
				self::run_purge( $post );
		}
	}




		/**
		 * Handles the transition of post status.
		 *
		 * @param string $new_status The new status of the post.
		 * @param string $old_status The old status of the post.
		 * @param object $post       The post object.
		 */
	public static function handle_transition_post_status( $new_status, $old_status, $post ) {
		if ( ( ( 'draft' !== $old_status && 'trash' !== $old_status ) && 'trash' === $new_status ) ||
			( 'publish' === $old_status && 'draft' === $new_status ) ) {

			self::purge_post( $post );
		}
	} 


		/**
		 * Adds the jQuery script to handle the "Test Config" button click on the admin page.
		 *
		 * @return void
		 */
	public static function add_purge_cache_button_script() {
		add_action(
			'admin_footer',
			function () { ?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#test-cloudflare-config').on('click', function(e) {
				e.preventDefault();
				var $button = $(this);
				$button.addClass('disabled').text('Testing...');
				jQuery.post(ajaxurl, { action: 'purge_cloudflare_cache' }, function(response) {
					if (response.success) {
						alert(response.message);
					} else {
						alert(response.message);
					}
					$button.removeClass('disabled').text('Test Config');
				}).fail(function() {
					alert('An error occurred. Please try again.');
					$button.removeClass('disabled').text('Test Config');
				});
			});
		});
	</script>
				<?php
			}
		);

		add_action(
			'wp_ajax_purge_cloudflare_cache',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( array( 'message' => __( 'You do not have permission to manage options.', 'on-demand-revalidation' ) ) );
					wp_die();
				}
				$posts = get_posts(
					array(
						'numberposts'      => 1,
						'post_status'      => 'publish',
						'suppress_filters' => false,
					) 
				);
				$post  = ! empty( $posts ) ? $posts[0] : null;

							$response = self::run_purge( $post );
				wp_send_json( $response );
				wp_die();
			}
		);
	}
}
