<?php

namespace OnDemandRevalidation;

class Helpers {


	// Prevent wrong REST API url in Headless WP
	public static function preventWrongApiUrl() {
		if ( home_url() !== site_url() ) {
			add_filter('rest_url', function ( $url ) {
				return str_replace( home_url(), site_url(), $url );
			});
		}
	}

	public static function rewritePaths( $paths, $post ) {
		$final_paths = [];

		foreach ( $paths as $path ) {
			$path = trim( $path );

			if ( strpos( $path, '%slug%' ) !== false ) {
				$final_paths[] = str_replace( '%slug%', $post->post_name, $path );
			} elseif ( strpos( $path, '%author_nicename%' ) !== false ) {
				$final_paths[] = str_replace( '%author_nicename%', get_the_author_meta( 'user_nicename', $post->post_author ), $path );
			} elseif ( strpos( $path, '%categories%' ) !== false ) {
				$categories = wp_get_post_categories( $post->ID, [ 'fields' => 'slugs' ] ) ?? [];
				foreach ( $categories as $category ) {
					$final_paths[] = str_replace( '%categories%', $category, $path );
				}
			} elseif ( strpos( $path, '%tags%' ) !== false ) {
				$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'slugs' ] ) ?? [];
				foreach ( $tags as $tag ) {
					$final_paths[] = str_replace( '%tags%', $tag, $path );
				}
			} else {
				$final_paths[] = $path;
			}
		}

		return $final_paths;
	}
}
