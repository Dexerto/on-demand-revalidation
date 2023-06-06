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
	
			// Match all placeholders in the path
			preg_match_all( '/%(.+?)%/', $path, $matches );
			$placeholders = $matches[1];
	
			$current_paths = [ $path ];
	
			foreach ( $placeholders as $placeholder ) {
				$new_paths = [];
	
				foreach ( $current_paths as $current_path ) {
					if ( 'slug' === $placeholder ) {
						$new_paths[] = str_replace( '%slug%', $post->post_name, $current_path );
					} elseif ( 'author_nicename' === $placeholder ) {
						$new_paths[] = str_replace( '%author_nicename%', get_the_author_meta( 'user_nicename', $post->post_author ), $current_path );
					} elseif ( 'author_username' === $placeholder ) {
						$new_paths[] = str_replace( '%author_username%', get_the_author_meta( 'user_login', $post->post_author ), $current_path );
					} elseif ( 'categories' === $placeholder ) {
						$terms = wp_get_post_terms( $post->ID, 'category', [ 'fields' => 'slugs' ] ) ?? [];
						foreach ( $terms as $term ) {
							$new_paths[] = str_replace( '%categories%', $term, $current_path );
						}
					} elseif ( 'tags' === $placeholder ) {
						$terms = wp_get_post_terms( $post->ID, 'post_tag', [ 'fields' => 'slugs' ] ) ?? [];
						foreach ( $terms as $term ) {
							$new_paths[] = str_replace( '%tags%', $term, $current_path );
						}
					} elseif ( in_array( $placeholder, get_post_taxonomies( $post ), true ) ) {
						$terms = wp_get_post_terms( $post->ID, $placeholder, [ 'fields' => 'slugs' ] ) ?? [];
						foreach ( $terms as $term ) {
							$new_paths[] = str_replace( '%' . $placeholder . '%', $term, $current_path );
						}
					} else {
						$new_paths[] = $current_path;
					}
				}
				
				$current_paths = $new_paths;
			}
	
			// Add the paths to the final array
			$final_paths = array_merge( $final_paths, $current_paths );
		}

		return $final_paths;
	}
}
