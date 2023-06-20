<?php

namespace OnDemandRevalidation;

use OnDemandRevalidation\Admin\Settings;
use OnDemandRevalidation\Helpers;
use WP_Error;

class Revalidation {

	public static function init() {
		add_action( 'save_post', [ self::class, 'handleSavePost' ], 10, 2 );
		add_action( 'transition_post_status', [ self::class, 'handleTransitionPostStatus' ], 10, 3 );
		add_action( 'on_demand_revalidation_on_post_update', [ self::class, 'revalidate' ], 10, 1 );
	}
	
	public static function handleSavePost( $post_id, $post ) {
		$excluded_statuses = [ 'auto-draft', 'inherit', 'draft', 'trash' ];
		
		if ( isset( $post->post_status ) && in_array( $post->post_status, $excluded_statuses, true ) ) {
			return;
		}
		
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
	
		if ( false !== wp_is_post_revision( $post_id ) ) {
			return;
		}
		
		self::revalidatePost( $post );
	}
	
	public static function handleTransitionPostStatus( $new_status, $old_status, $post ) {
		if ( ( ( 'draft' !== $old_status && 'trash' !== $old_status ) && 'trash' === $new_status ) ||
			( 'publish' === $old_status && 'draft' === $new_status ) ) {
			self::revalidatePost( $post );
		}
	}   

	static function revalidatePost( $post ) {
		if ( Settings::get( 'disable_cron', 'on', 'on_demand_revalidation_post_update_settings' ) === 'on' ) {
			self::revalidate( $post );
		} else {
			wp_schedule_single_event( time(), 'on_demand_revalidation_on_post_update', [ $post ] );
		}
	}

	public static function revalidate( $post ) {
		$frontend_url          = Settings::get( 'frontend_url' );
		$revalidate_secret_key = Settings::get( 'revalidate_secret_key' );

		if ( ! ( $frontend_url || $revalidate_secret_key ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Fill Next.js URL and Revalidate Secret Key first.', 'on-demand-revalidation' ), [ 'status' => 401 ] );
		}

		$paths = [];

		if ( Settings::get( 'revalidate_homepage', 'on', 'on_demand_revalidation_post_update_settings' ) === 'on' ) {
			$paths[] = '/';
		}

		$post_permalink  = get_permalink( $post );
		$parse_permalink = parse_url( $post_permalink );
		$page_path       = '/';

		if ( isset( $parse_permalink['path'] ) ) {
			$page_path = $parse_permalink['path'];
		}

		$paths[] = substr( $page_path, -1 ) === '/' ? substr( $page_path, 0, -1 ) : $page_path;

		$revalidate_paths = trim( Settings::get( 'revalidate_paths', '', 'on_demand_revalidation_post_update_settings' ) );
		$revalidate_paths = preg_split( '/\r\n|\n|\r/', $revalidate_paths );
		$revalidate_paths = Helpers::rewritePaths( $revalidate_paths, $post );

		if ( $revalidate_paths ) {
			foreach ( $revalidate_paths as $path ) {
				if ( str_starts_with( $path, '/' ) ) {
					$paths[] = $path;
				}
			}
		}

		$paths = apply_filters( 'on_demand_revalidation_paths', $paths, $post );

		$data = json_encode( [
			'paths'  => $paths,
			'postId' => $post->ID,
		] );

		$response = wp_remote_request( "$frontend_url/api/revalidate", [
			'method'  => 'PUT',
			'body'    => $data,
			'headers' => [
				'Authorization' => "Bearer $revalidate_secret_key",
				'Content-Type'  => 'application/json',
			],
		]);

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$response_data = ( ! is_wp_error( $response ) ) ? $body : $response;


		if ( class_exists( 'CPurgeCache' ) ) {
			\CPurgeCache\Purge::purge( $post );
		}

		return $response_data;

		if ( ! $response_data['revalidated'] ) {
			return new WP_Error( 'revalidate_error', $response['message'], [ 'status' => 403 ] );
		}

		$revalidated = implode( ', ', $paths );

		return (object) [
			'success' => $response_data['revalidated'],
			'message' => "Next.js revalidated $revalidated successfully.",
		];
	}

	public static function testRevalidationButton() {
		add_action('admin_footer', function () { ?>
			<script type="text/javascript" >
				jQuery('#on-demand-revalidation-post-update-test').on('click', function () {
					jQuery.post(ajaxurl, { action: 'revalidation-post-update-test' }, function(response) {
						alert(response?.message || response?.errors?.revalidate_error[0] || JSON.stringify(response.errors));
					});
				});
			</script>
			<?php
		});

		add_action('wp_ajax_revalidation-post-update-test', function () {

			if ( ! current_user_can( 'edit_posts' ) ) {
				$response = new WP_Error( 'rest_forbidden', __( 'You cannot edit posts.', 'on-demand-revalidation' ), [ 'status' => 401 ] );
			}

			$latest_post = get_posts([
				'numberposts' => 1,
				'post_status' => 'publish',
			])[0];
			$response    = self::revalidate( $latest_post );

			wp_send_json( $response );
			wp_die();
		});
	}
}
