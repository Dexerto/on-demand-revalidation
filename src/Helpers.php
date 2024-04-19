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
	 * Replaces placeholders in given items with actual values from a specified post and its taxonomies.
	 * 
	 * This function processes an array of strings, replacing placeholders like %slug%, %id%, %categories%, etc.,
	 * with corresponding data from the post. It handles special placeholders for post IDs and taxonomy terms,
	 * formatting the output as needed for different use cases.
	 *
	 * @param array    $items Array of strings containing placeholders to be replaced.
	 * @param \WP_Post $post Post object used to extract data for replacing placeholders.
	 * @return array Array of processed items with placeholders replaced by actual post data.
	 */
	public static function rewrite_placeholders( $items, $post ) {
		$final_items = array();

		foreach ( $items as $item ) {
			$item = trim( $item );

			// Match all placeholders in the item.
			preg_match_all( '/%(.+?)%/', $item, $matches );
			$placeholders = $matches[1];

			$current_items = array( $item );

			foreach ( $placeholders as $placeholder ) {
				$new_items = array();

				foreach ( $current_items as $current_item ) {
					switch ( $placeholder ) {
						case 'slug':
							$new_items[] = str_replace( '%slug%', $post->post_name, $current_item );
							break;
						case 'author_nicename':
							$new_items[] = str_replace( '%author_nicename%', get_the_author_meta( 'user_nicename', $post->post_author ), $current_item );
							break;
						case 'author_username':
							$new_items[] = str_replace( '%author_username%', get_the_author_meta( 'user_login', $post->post_author ), $current_item );
							break;
						case 'database_id':
							$new_items[] = str_replace( '%database_id%', $post->ID, $current_item );
							break;
						case 'id':
							// Encode the ID in a format that matches WPGRAPHQL.
							$encoded_id  = base64_encode( $post->ID );
							$new_items[] = str_replace( '%id%', $encoded_id, $current_item );
							break;
						case 'categories':
							$terms = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
							if ( ! empty( $terms ) ) {
								foreach ( $terms as $term ) {
									$new_items[] = str_replace( '%categories%', $term, $current_item );
								}
							}
							break;
						case 'post_tag':
							$terms = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'slugs' ) );
							if ( ! empty( $terms ) ) {
								foreach ( $terms as $term ) {
									$new_items[] = str_replace( '%post_tag%', $term, $current_item );
								}
							}
							break;
						default:
							$terms = wp_get_post_terms( $post->ID, $placeholder, array( 'fields' => 'slugs' ) );
							if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
								foreach ( $terms as $term ) {
									$new_items[] = str_replace( '%' . $placeholder . '%', $term, $current_item );
								}
							}
							break;
					}
				}
				$current_items = $new_items;
			}

			// Add the fully processed items to the final array.
			$final_items = array_merge( $final_items, $current_items );
		}

		return $final_items;
	}
}
