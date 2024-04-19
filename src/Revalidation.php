<?php
/**
 * Revalidation class for On Demand Revalidation Plugin.
 *
 * @package OnDemandRevalidation
 */

namespace OnDemandRevalidation;

use OnDemandRevalidation\Admin\Settings;
use OnDemandRevalidation\Helpers;
use WP_Error;

/**
 * Class Revalidation
 *
 * This class handles various functionalities related to post revalidation.
 *
 * @package OnDemandRevalidation
 */
class Revalidation {

	/**
	 * Initializes the Revalidation class.
	 *
	 * Registers necessary actions upon initialization.
	 */
	public static function init() {
		add_action( 'save_post', array( self::class, 'handle_save_post' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'handle_transition_post_status' ), 10, 3 );
		add_action( 'on_demand_revalidation_on_post_update', array( self::class, 'revalidate' ), 10, 1 );
		add_action( 'pre_post_update', array( self::class, 'capture_old_permalink' ), 10, 3 );
		add_action( 'wp_trash_post', array( self::class, 'capture_old_permalink_before_trash' ), 10, 1 );
	}

	/**
	 * Captures the old permalink before a post is updated.
	 *
	 * @param int    $post_ID The ID of the post being updated.
	 * @param object $data    The data for the post being updated.
	 */
	public static function capture_old_permalink( $post_ID, $data ) {
		if ( 'trash' === $data['post_status'] ) {
			return;
		}
		
		$old_permalink = get_permalink( $post_ID );
		update_post_meta( $post_ID, '_old_permalink', $old_permalink );
	}
	/**
	 * Captures the old permalink before a post is trashed.
	 *
	 * @param int $post_ID The ID of the post being trashed.
	 */
	public static function capture_old_permalink_before_trash( $post_ID ) {
		$old_permalink = get_permalink( $post_ID );
		update_post_meta( $post_ID, '_old_permalink', $old_permalink );
	}

	/**
	 * Handles the saving of a post.
	 *
	 * @param int    $post_id The ID of the post being saved.
	 * @param object $post    The post object.
	 */
	public static function handle_save_post( $post_id, $post ) {
		$excluded_statuses = array( 'auto-draft', 'inherit', 'draft', 'trash' );
		
		if ( isset( $post->post_status ) && in_array( $post->post_status, $excluded_statuses, true ) ) {
			return;
		}
		
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
	
		if ( false !== wp_is_post_revision( $post_id ) ) {
			return;
		}
		
		self::revalidate_post( $post );
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

			self::revalidate_post( $post );
		}
	}   

	/**
	 * Revalidates a post.
	 *
	 * @param object $post The post object to be revalidated.
	 * @return mixed the response data or WP_Error if revalidation fails.
	 */
	public static function revalidate_post( $post ) {
		if ( Settings::get( 'disable_cron', 'on', 'on_demand_revalidation_post_update_settings' ) === 'on' ) {
			self::revalidate( $post );
		} else {
			wp_schedule_single_event( time(), 'on_demand_revalidation_on_post_update', array( $post ) );
		}
	}

	/**
	 * Revalidates a post.
	 *
	 * @param object $post The post object to be revalidated.
	 * @return mixed the response data or WP_Error if revalidation fails.
	 */
	public static function revalidate( $post ) {
		$frontend_url          = Settings::get( 'frontend_url' );
		$revalidate_secret_key = Settings::get( 'revalidate_secret_key' );

		if ( ! ( $frontend_url || $revalidate_secret_key ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Fill Next.js URL and Revalidate Secret Key first.', 'on-demand-revalidation' ), array( 'status' => 401 ) );
		}

		$paths = array();

		if ( Settings::get( 'revalidate_homepage', 'on', 'on_demand_revalidation_post_update_settings' ) === 'on' ) {
			$paths[] = '/';
		}

		$post_permalink  = get_permalink( $post );
		$parse_permalink = wp_parse_url( $post_permalink );
		$page_path       = '/';

		if ( isset( $parse_permalink['path'] ) && '/' !== $parse_permalink['path'] ) {
			$page_path = substr( $parse_permalink['path'], -1 ) === '/' ? substr( $parse_permalink['path'], 0, -1 ) : $parse_permalink['path'];
			$paths[]   = $page_path;
		}

		$old_permalink = get_post_meta( $post->ID, '_old_permalink', true );

		if ( ! empty( $old_permalink ) ) {
			$parse_old_permalink = wp_parse_url( $old_permalink );
		
			if ( isset( $parse_old_permalink['path'] ) && '/' !== $parse_old_permalink['path'] ) {
				$old_page_path = substr( $parse_old_permalink['path'], -1 ) === '/' ? substr( $parse_old_permalink['path'], 0, -1 ) : $parse_old_permalink['path'];
				$paths[]       = $old_page_path;
			}
		}

		$paths = array_unique( $paths );

		$revalidate_paths = trim( Settings::get( 'revalidate_paths', '', 'on_demand_revalidation_post_update_settings' ) );
		
		if ( ! empty( $revalidate_paths ) ) {
			$revalidate_paths = preg_split( '/\r\n|\n|\r/', $revalidate_paths );
			$revalidate_paths = Helpers::rewrite_placeholders( $revalidate_paths, $post );
		} else {
			$paths = array();
		}

		$revalidate_tags = trim( Settings::get( 'revalidate_tags', '', 'on_demand_revalidation_post_update_settings' ) );

		if ( ! empty( $revalidate_tags ) ) {
			$revalidate_tags = preg_split( '/\r\n|\n|\r/', $revalidate_tags );
			$tags            = Helpers::rewrite_placeholders( $revalidate_tags, $post );
		} else {
			$tags = array();
		}

		if ( $revalidate_paths ) {
			foreach ( $revalidate_paths as $path ) {
				if ( str_starts_with( $path, '/' ) ) {
					$paths[] = $path;
				}
			}
		}

		$paths = apply_filters( 'on_demand_revalidation_paths', $paths, $post );
		$tags  = apply_filters( 'on_demand_revalidation_paths', $tags, $post );

		$data = array(
			'postId' => $post->ID,
		);
	
		if ( ! empty( $paths ) ) {
			$data['paths'] = $paths;
		}
	
		if ( ! empty( $tags ) ) {
			$data['tags'] = $tags;
		}

		$data = wp_json_encode( $data );

		$response = wp_remote_request(
			"$frontend_url/api/revalidate",
			array(
				'method'  => 'PUT',
				'body'    => $data,
				'headers' => array(
					'Authorization' => "Bearer $revalidate_secret_key",
					'Content-Type'  => 'application/json',
				),
			)
		);

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$response_data = ( ! is_wp_error( $response ) ) ? $body : $response;


		if ( class_exists( 'CPurgeCache' ) ) {
			\CPurgeCache\Purge::purge( $post );
		}


		if ( ! $response_data['revalidated'] ) {
			return new WP_Error( 'revalidate_error', $response['message'], array( 'status' => 403 ) );
		}

		$revalidated = implode( ', ', $paths );

		return (object) array(
			'success' => $response_data['revalidated'],
			'message' => "Next.js revalidated $revalidated successfully.",
		);
	}

	/**
	 * Adds a test revalidation button to the admin interface.
	 */
	public static function test_revalidation_button() {
		add_action(
			'admin_footer',
			function () { ?>
			<script type="text/javascript" >
				jQuery('#on-demand-revalidation-post-update-test').on('click', function () {
					jQuery.post(ajaxurl, { action: 'revalidation-post-update-test' }, function(response) {
						alert(response?.message || response?.errors?.revalidate_error[0] || JSON.stringify(response.errors));
					});
				});
			</script>
				<?php
			}
		);

		add_action(
			'wp_ajax_revalidation-post-update-test',
			function () {

				if ( ! current_user_can( 'edit_posts' ) ) {
					$response = new WP_Error( 'rest_forbidden', __( 'You cannot edit posts.', 'on-demand-revalidation' ), array( 'status' => 401 ) );
				}

				$latest_post = get_posts( //phpcs:ignore --suppress_filters already set to false
					array(
						'numberposts'      => 1,
						'post_status'      => 'publish',
						'suppress_filters' => false,
					)
				)[0];
				$response    = self::revalidate( $latest_post );

				wp_send_json( $response );
				wp_die();
			}
		);
	}
}
