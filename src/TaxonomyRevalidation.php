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
	 * Fires revalidation for a term immediately, deduplicating within a 5-second window.
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

		self::revalidate_term( $term_id, $taxonomy );
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
		$term_name    = $term instanceof \WP_Term ? $term->name : "Term $term_id";

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

		$response = wp_remote_request(
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

		self::log_revalidation_result( $term_id, $term_name, $taxonomy, $paths, $tags, $endpoint, $response );
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

	/**
	 * Logs a revalidation attempt to a rolling WP option (last 50 entries).
	 *
	 * @param int             $term_id   The term ID.
	 * @param string          $term_name The term name.
	 * @param string          $taxonomy  The taxonomy slug.
	 * @param string[]        $paths     Paths that were submitted.
	 * @param string[]        $tags      Tags that were submitted.
	 * @param string          $endpoint  The API endpoint called.
	 * @param array|\WP_Error $response  The wp_remote_request() response.
	 * @return void
	 */
	private static function log_revalidation_result( int $term_id, string $term_name, string $taxonomy, array $paths, array $tags, string $endpoint, array|\WP_Error $response ): void {
		$is_wp_error = is_wp_error( $response );
		$http_code   = $is_wp_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body        = $is_wp_error ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$success     = ! $is_wp_error && $http_code >= 200 && $http_code < 300 && is_array( $body ) && ! empty( $body['revalidated'] );

		$entry = array(
			'timestamp' => time(),
			'term_id'   => $term_id,
			'term_name' => $term_name,
			'taxonomy'  => $taxonomy,
			'paths'     => $paths,
			'tags'      => $tags,
			'endpoint'  => $endpoint,
			'http_code' => $http_code,
			'success'   => $success,
			'error'     => $is_wp_error
				? $response->get_error_message()
				: ( ! $success ? ( is_array( $body ) ? ( $body['message'] ?? 'Unexpected response from revalidation endpoint' ) : 'Unexpected response from revalidation endpoint' ) : null ),
		);

		$log = get_option( 'on_demand_revalidation_taxonomy_log', array() );
		$log = is_array( $log ) ? $log : array();
		$log = array_slice( array_merge( array( $entry ), $log ), 0, 50 );
		update_option( 'on_demand_revalidation_taxonomy_log', $log, false );
	}

	/**
	 * Renders the revalidation activity log.
	 * Called as a section callback from the plugin settings page.
	 *
	 * @return void
	 */
	public static function render_revalidation_log(): void {
		$log = get_option( 'on_demand_revalidation_taxonomy_log', array() );

		if ( empty( $log ) ) {
			echo '<p style="color:#666;">No revalidations logged yet. Entries will appear here automatically after term saves.</p>';
			return;
		}

		?>
		<table class="widefat striped" style="max-width:900px;">
			<thead>
				<tr>
					<th>Time</th>
					<th>Term</th>
					<th>Taxonomy</th>
					<th>Paths</th>
					<th>Tags</th>
					<th>HTTP</th>
					<th>Result</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $log as $entry ) : ?>
				<?php
				$success = ! empty( $entry['success'] );
				// $time_label, $paths_list, $tags_list are escaped at assignment via esc_html() inside array_map.
				$time_label = isset( $entry['timestamp'] ) ? esc_html( human_time_diff( $entry['timestamp'] ) . ' ago' ) : '—';
				$http_code  = ! empty( $entry['http_code'] ) ? $entry['http_code'] : '—';
				$paths_list = ! empty( $entry['paths'] ) && is_array( $entry['paths'] )
					? implode( '<br>', array_map( fn( $p ) => '<code>' . esc_html( $p ) . '</code>', $entry['paths'] ) )
					: '—';
				$tags_list  = ! empty( $entry['tags'] ) && is_array( $entry['tags'] )
					? implode( '<br>', array_map( fn( $t ) => '<code>' . esc_html( $t ) . '</code>', $entry['tags'] ) )
					: '—';
				?>
				<tr<?php echo $success ? '' : ' style="background:#fff0f0;"'; ?>>
					<td style="white-space:nowrap;"><?php echo $time_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td>
						<?php echo esc_html( $entry['term_name'] ?? '—' ); ?>
						<br><small style="color:#888;">ID: <?php echo esc_html( (string) ( $entry['term_id'] ?? '—' ) ); ?></small>
					</td>
					<td><code><?php echo esc_html( $entry['taxonomy'] ?? '—' ); ?></code></td>
					<td><?php echo $paths_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo $tags_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo esc_html( (string) $http_code ); ?></td>
					<td>
						<?php if ( $success ) : ?>
							<span style="color:#46b450;font-weight:600;">&#10003; Success</span>
						<?php else : ?>
							<span style="color:#dc3232;font-weight:600;">&#10007; Failed</span>
							<?php if ( ! empty( $entry['error'] ) ) : ?>
								<br><small style="color:#dc3232;"><?php echo esc_html( $entry['error'] ); ?></small>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:8px;color:#666;font-size:12px;">
			Showing last <?php echo count( $log ); ?> of up to 50 entries.
		</p>
		<?php
	}
}
