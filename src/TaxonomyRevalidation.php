<?php
/**
 * TaxonomyRevalidation class for On Demand Revalidation Plugin.
 *
 * Triggers on-demand Next.js revalidation when taxonomy terms are updated.
 *
 * @package OnDemandRevalidation
 */

namespace OnDemandRevalidation;

use OnDemandRevalidation\Admin\Settings;

/**
 * Class TaxonomyRevalidation
 *
 * Hooks into term saves and fires revalidation requests for taxonomy pages.
 */
class TaxonomyRevalidation {

	/**
	 * Registers all hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( Settings::get( 'taxonomy_revalidation_enabled' ) !== 'on' ) {
			return;
		}

		add_action( 'edited_term', array( self::class, 'on_term_updated' ), 10, 3 );
		add_action( 'on_demand_revalidation_on_taxonomy_update', array( self::class, 'revalidate_term' ), 10, 2 );
	}

	/**
	 * Returns all public taxonomy slugs, minus any explicitly excluded ones.
	 *
	 * Use the `on_demand_revalidation_excluded_taxonomies` filter to add exclusions.
	 * Use the `on_demand_revalidation_taxonomies` filter to override the entire list.
	 *
	 * @return string[]
	 */
	public static function get_watched_taxonomies(): array {
		$excluded = apply_filters(
			'on_demand_revalidation_excluded_taxonomies',
			array( 'post_format', 'link_category', 'nav_menu', 'evergreen_menu_location' )
		);

		$all_public = get_taxonomies( array( 'public' => true ), 'names' );
		$watched    = array_values( array_diff( $all_public, is_array( $excluded ) ? $excluded : array() ) );

		$filtered = apply_filters( 'on_demand_revalidation_taxonomies', $watched );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $watched;
	}

	/**
	 * Fires when a term is saved via WordPress core. Triggers immediate revalidation.
	 *
	 * @param int    $term_id  The term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 */
	public static function on_term_updated( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, self::get_watched_taxonomies(), true ) ) {
			return;
		}

		self::maybe_schedule_revalidation( $term_id, $taxonomy );
	}

	/**
	 * Deduplicates within a 5-second window then either fires immediately or
	 * schedules via WP-Cron, matching the behaviour of post revalidation.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 */
	public static function maybe_schedule_revalidation( int $term_id, string $taxonomy ): void {
		$transient_key = 'on_demand_tax_reval_' . $term_id . '_' . $taxonomy;

		if ( get_transient( $transient_key ) ) {
			return;
		}

		set_transient( $transient_key, true, 5 );

		if ( Settings::get( 'disable_cron', 'on', 'on_demand_revalidation_post_update_settings' ) === 'on' ) {
			self::revalidate_term( $term_id, $taxonomy );
		} else {
			wp_schedule_single_event( time(), 'on_demand_revalidation_on_taxonomy_update', array( $term_id, $taxonomy ) );
		}
	}

	/**
	 * Performs the revalidation HTTP request for a given term synchronously.
	 *
	 * Reads paths and tags from the plugin settings (global taxonomy settings and
	 * per-taxonomy settings), replaces placeholders, and sends them to the API.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 */
	public static function revalidate_term( int $term_id, string $taxonomy ): void {
		$frontend_url = Settings::get( 'frontend_url' );
		$secret       = Settings::get( 'revalidate_secret_key' );
		$term         = get_term( $term_id, $taxonomy );

		if ( empty( $frontend_url ) || empty( $secret ) || ! $term instanceof \WP_Term ) {
			return;
		}

		$paths = array();
		$tags  = array();

		// Global taxonomy paths.
		$global_raw_paths = trim( Settings::get( 'revalidate_taxonomy_paths', '', 'on_demand_revalidation_taxonomy_settings' ) );
		if ( ! empty( $global_raw_paths ) ) {
			foreach ( self::rewrite_placeholders( preg_split( '/\r\n|\n|\r/', $global_raw_paths ), $term ) as $path ) {
				if ( str_starts_with( $path, '/' ) ) {
					$paths[] = $path;
				}
			}
		}

		// Per-taxonomy paths.
		$per_tax_raw_paths = trim( Settings::get( 'revalidate_paths', '', 'on_demand_revalidation_' . $taxonomy . '_taxonomy_settings' ) );
		if ( ! empty( $per_tax_raw_paths ) ) {
			foreach ( self::rewrite_placeholders( preg_split( '/\r\n|\n|\r/', $per_tax_raw_paths ), $term ) as $path ) {
				if ( str_starts_with( $path, '/' ) ) {
					$paths[] = $path;
				}
			}
		}

		// Global taxonomy tags.
		$global_raw_tags = trim( Settings::get( 'revalidate_taxonomy_tags', '', 'on_demand_revalidation_taxonomy_settings' ) );
		if ( ! empty( $global_raw_tags ) ) {
			$tags = array_merge( $tags, self::rewrite_placeholders( preg_split( '/\r\n|\n|\r/', $global_raw_tags ), $term ) );
		}

		// Per-taxonomy tags.
		$per_tax_raw_tags = trim( Settings::get( 'revalidate_tags', '', 'on_demand_revalidation_' . $taxonomy . '_taxonomy_settings' ) );
		if ( ! empty( $per_tax_raw_tags ) ) {
			$tags = array_merge( $tags, self::rewrite_placeholders( preg_split( '/\r\n|\n|\r/', $per_tax_raw_tags ), $term ) );
		}

		$paths    = array_values( array_unique( array_filter( $paths ) ) );
		$tags     = array_values( array_unique( array_filter( $tags ) ) );
		$endpoint = rtrim( $frontend_url, '/' ) . '/api/revalidate';

		if ( empty( $paths ) && empty( $tags ) ) {
			return;
		}

		$data = array();
		if ( ! empty( $paths ) ) {
			$data['paths'] = $paths;
		}
		if ( ! empty( $tags ) ) {
			$data['tags'] = $tags;
		}

		wp_remote_request(
			$endpoint,
			array(
				'method'  => 'PUT',
				'body'    => wp_json_encode( $data ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				),
			)
		);
	}

	/**
	 * Replaces term placeholders in an array of path/tag strings.
	 *
	 * Supported placeholders:
	 * - %slug%     — term slug
	 * - %taxonomy% — taxonomy slug
	 * - %term_id%  — numeric term ID
	 *
	 * @param string[] $items Strings containing placeholders.
	 * @param \WP_Term $term  The term being revalidated.
	 * @return string[]
	 */
	private static function rewrite_placeholders( array $items, \WP_Term $term ): array {
		$replacements = array(
			'%slug%'     => $term->slug,
			'%taxonomy%' => $term->taxonomy,
			'%term_id%'  => (string) $term->term_id,
		);

		$result = array();
		foreach ( $items as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			$result[] = str_replace( array_keys( $replacements ), array_values( $replacements ), $item );
		}

		return $result;
	}
}
