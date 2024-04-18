<?php
/**
 * Helpers Class
 *
 * This class provides helpers to the plugin.
 *
 * @package OnDemandRevalidation
 */

namespace OnDemandRevalidation;

/**
 * Class Helpers
 *
 * This class provides helper methods for various tasks.
 */
class Helpers {


	/**
	 * Prevents wrong API URL.
	 *
	 * This method prevents wrong API URLs by filtering the REST URL if home URL is different from site URL.
	 */ 
	public static function prevent_wrong_api_url() {
		if ( home_url() !== site_url() ) {
			add_filter(
				'rest_url',
				function ( $url ) {
					return str_replace( home_url(), site_url(), $url );
				}
			);
		}
	}

		/**
		 * Rewrites paths.
		 *
		 * This method rewrites paths based on post placeholders and returns the final paths array.
		 *
		 * @param array  $paths An array of paths to rewrite.
		 * @param object $post The post object.
		 * @return array The final array of rewritten paths.
		 */
	public static function rewrite_paths( $paths, $post ) {
		$final_paths = array();

		foreach ( $paths as $path ) {
			$path = trim( $path );
	
			// Match all placeholders in the path.
			preg_match_all( '/%(.+?)%/', $path, $matches );
			$placeholders = $matches[1];
	
			$current_paths = array( $path );
	
			foreach ( $placeholders as $placeholder ) {
				$new_paths = array();
	
				foreach ( $current_paths as $current_path ) {
					if ( 'slug' === $placeholder ) {
						$new_paths[] = str_replace( '%slug%', $post->post_name, $current_path );
					} elseif ( 'author_nicename' === $placeholder ) {
						$new_paths[] = str_replace( '%author_nicename%', get_the_author_meta( 'user_nicename', $post->post_author ), $current_path );
					} elseif ( 'author_username' === $placeholder ) {
						$new_paths[] = str_replace( '%author_username%', get_the_author_meta( 'user_login', $post->post_author ), $current_path );
					} elseif ( 'categories' === $placeholder ) {
						$terms = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) ) ?? array();
						foreach ( $terms as $term ) {
							$new_paths[] = str_replace( '%categories%', $term, $current_path );
						}
					} elseif ( 'tags' === $placeholder ) {
						$terms = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'slugs' ) ) ?? array();
						foreach ( $terms as $term ) {
							$new_paths[] = str_replace( '%tags%', $term, $current_path );
						}
					} elseif ( in_array( $placeholder, get_post_taxonomies( $post ), true ) ) {
						$terms = wp_get_post_terms( $post->ID, $placeholder, array( 'fields' => 'slugs' ) ) ?? array();
						foreach ( $terms as $term ) {
							$new_paths[] = str_replace( '%' . $placeholder . '%', $term, $current_path );
						}
					} else {
						$new_paths[] = $current_path;
					}
				}
				
				$current_paths = $new_paths;
			}
	
			// Add the paths to the final array.
			$final_paths = array_merge( $final_paths, $current_paths );
		}

		return $final_paths;
	}


			/**
			 * Rewrites tags.
			 *
			 * This method rewrites tags based on post placeholders and returns the final tags array.
			 *
			 * @param array  $tags An array of tags to rewrite.
			 * @param object $post The post object.
			 * @return array The final array of rewritten tags.
			 */ 
	public static function rewrite_tags( $tags, $post ) {
		$final_tags = array();
	
		foreach ( $tags as $tag ) {
				$tag = trim( $tag );
	
				// Match all placeholders within the tag template.
				preg_match_all( '/{(.+?)}/', $tag, $matches );
				$placeholders = $matches[1];
	
				$current_tags = array( $tag );
	
			foreach ( $placeholders as $placeholder ) {
					$new_tags = array();
	
				foreach ( $current_tags as $current_tag ) {
					switch ( $placeholder ) {
						case 'databaseId':
						case 'id':
								$new_tags[] = str_replace( '{' . $placeholder . '}', $post->ID, $current_tag );
							break;
						case 'category':
								$terms = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
							if ( ! empty( $terms ) ) {
								foreach ( $terms as $term ) {
									$new_tags[] = str_replace( '{category}', $term, $current_tag );
								}
							} else {
											// Ensure at least one tag remains even if there are no terms.
											$new_tags[] = str_replace( '{category}', 'uncategorized', $current_tag );
							}
							break;
						case 'tag':
								$terms = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'slugs' ) );
							if ( ! empty( $terms ) ) {
								foreach ( $terms as $term ) {
									$new_tags[] = str_replace( '{tag}', $term, $current_tag );
								}
							} else {
											// Ensure at least one tag remains even if there are no terms.
											$new_tags[] = str_replace( '{tag}', 'notag', $current_tag );
							}
							break;
						default:
								$terms = wp_get_post_terms( $post->ID, $placeholder, array( 'fields' => 'slugs' ) );
							if ( ! empty( $terms ) ) {
								foreach ( $terms as $term ) {
									$new_tags[] = str_replace( '{' . $placeholder . '}', $term, $current_tag );
								}
							} else {
											// Preserve the tag without replacement if no terms found.
											$new_tags[] = $current_tag;
							}
							break;
					}
				}
	
					// Update the current tags for the next round of replacements.
					$current_tags = $new_tags;
			}
	
				// Merge all fully processed tags into the final list.
				$final_tags = array_merge( $final_tags, $current_tags );
		}
	
		return $final_tags;
	}
}
