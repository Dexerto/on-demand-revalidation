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
		add_action( 'wp_ajax_purge_cloudflare_cache', array( self::class, 'handle_cloudflare_revalidation_ajax' ) );
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
		$api_token_verify_response = vip_safe_wp_remote_get(
			$api_token_verify_url,
			array(
				'headers' => $headers,
				'timeout' => 10, //phpcs:ignore --10 seconds timing out
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
		$zone_response = vip_safe_wp_remote_get(
			$zone_url,
			array(
				'headers' => $headers,
				'timeout' => 10, //phpcs:ignore --10 seconds timing out
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
	 * Purges the Cloudflare cache for the specified paths and tags.
	 *
	 * @param string $zone_id   The Cloudflare zone ID.
	 * @param string $api_token The Cloudflare API token.
	 * @param array  $paths     An array of URLs (paths) to purge.
	 * @param array  $tags      An array of tags to purge.
	 *
	 * @return array An array containing the success status and a message.
	 *               Example: ['success' => true, 'message' => 'Cache purged successfully.']
	 */
	public static function purge_cache( $zone_id, $api_token, $paths = array(), $tags = array() ) {


  
		$purge_data = array();

		if ( ! empty( $paths ) ) {
			$purge_data['files'] = $paths;  
		}
		if ( ! empty( $tags ) ) {
			$purge_data['tags'] = $tags; 
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
				'timeout' => 10, //phpcs:ignore --10 seconds timing out
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			return array(
				'success' => false,
				'message' => 'Cloudflare cache purge request failed: ' . $error_message,
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );


		if ( true === $data['success'] && ! empty( $data['success'] ) ) {

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
	 * Adds the jQuery script to handle the "Purge Cache" button click on the admin page.
	 *
	 * This script listens for the "Purge Cache" button click, disables the button, and sends an AJAX request
	 * to trigger the cache purging process.
	 *
	 * @return void
	 */
	public static function add_purge_cache_button_script() {
		add_action(
			'admin_footer',
			function () { ?>
		<script type="text/javascript">

			jQuery(document).ready(function($) {
				$('#purge-cloudflare-cache').on('click', function (e) {
					e.preventDefault();
					var $button = $(this);
					$button.addClass('disabled').text('Purging...');


					jQuery.post(ajaxurl, { action: 'purge_cloudflare_cache' }, function(response) {
						
						if (response.success) {
							alert(response.data.message);
						} else {
							alert(response.data.message);
						}
						
						$button.removeClass('disabled').text('Purge Cache');
						
					}).fail(function() {
						alert('An error occurred. Please try again.');
						$button.removeClass('disabled').text('Purge Cache');
					});
				});
			});
		</script>
				<?php
			}
		);
	}

	/**
	 * Handles the Cloudflare cache revalidation via AJAX.
	 *
	 * This function checks the user's permissions, validates the Cloudflare API token and zone ID,
	 * and triggers the cache purging process for the provided paths and tags. Sends a JSON response 
	 * back to the client indicating success or failure.
	 *
	 * @return void
	 */
	public static function handle_cloudflare_revalidation_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage options.', 'on-demand-revalidation' ) ) );
			wp_die();
		}

		$settings  = get_option( 'on_demand_revalidation_cloudflare_settings' );
		$zone_id   = $settings['cloudflare_zone_id'] ?? '';
		$api_token = $settings['cloudflare_api_token'] ?? '';
		$paths     = isset( $settings['cloudflare_cache_purge_paths'] ) ? explode( "\n", trim( $settings['cloudflare_cache_purge_paths'] ) ) : array();
		$tags      = isset( $settings['cloudflare_cache_purge_tags'] ) ? explode( "\n", trim( $settings['cloudflare_cache_purge_tags'] ) ) : array();



		if ( ! isset( $settings['cloudflare_cache_purge_enabled'] ) || 'on' !== $settings['cloudflare_cache_purge_enabled'] ) {
			wp_send_json_error( array( 'message' => 'Cloudflare cache purge is disabled.' ) );
			wp_die();
		}

  
		if ( ! self::validate_token_zone_id( $zone_id, $api_token ) ) {
			$settings['cloudflare_cache_purge_enabled'] = 'off';
			update_option( 'on_demand_revalidation_cloudflare_settings', $settings );
			wp_send_json_error( array( 'message' => 'Invalid Cloudflare API Token or Zone ID. Cache purge disabled.' ) );
			wp_die();
		}

  
		if ( empty( $paths ) && empty( $tags ) ) {
			wp_send_json_error( array( 'message' => 'No paths or tags provided for cache purging.' ) );
			wp_die();
		}

  
		$purge_result = self::purge_cache( $zone_id, $api_token, $paths, $tags );
		if ( $purge_result ) {
			wp_send_json_success( array( 'message' => $purge_result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $purge_result['message'] ) );
		}

		wp_die();
	}
}

