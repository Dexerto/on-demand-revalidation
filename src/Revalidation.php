<?php

namespace OnDemandRevalidation;

use OnDemandRevalidation\Admin\Settings;
use OnDemandRevalidation\Helpers;
use WP_Error;

class Revalidation {

	public static function init() {
		add_action('wp_insert_post', function ( $post_ID, $post, $update ) {
			if ( wp_is_post_revision( $post_ID ) ) {
				return;
			}

			wp_schedule_single_event( time(), 'on_demand_revalidation_on_post_update', [ $post ] );
		}, 10, 3);

		add_action('transition_post_status', function ( $new_status, $old_status, $post ) {
			wp_schedule_single_event( time(), 'on_demand_revalidation_on_post_update', [ $post ] );
		}, 10, 3);

		add_action('on_demand_revalidation_on_post_update', function ( $post ) {
			self::revalidate( $post );
		}, 10, 1);
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

		if ( isset($parse_permalink['path']) ) {
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

		$data = json_encode( [ 'paths' => $paths ] );

		$response = wp_remote_request( "$frontend_url/api/revalidate", [
			'method'  => 'PUT',
			'body'    => $data,
			'headers' => [
				'Authorization' => "Bearer $revalidate_secret_key",
				'Content-Type'  => 'application/json',
			],
		]);

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$response_data = ( ! is_wp_error( $response ) ) ? $body : null;

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
