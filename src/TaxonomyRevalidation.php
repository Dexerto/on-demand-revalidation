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
		add_action( 'admin_notices', array( self::class, 'maybe_show_config_notice' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_show_term_revalidation_notice' ) );
		add_action( 'admin_menu', array( self::class, 'register_admin_page' ) );

		if ( Settings::get( 'taxonomy_revalidation_enabled' ) !== 'on' ) {
			return;
		}

		add_action( 'edited_term', array( self::class, 'on_term_updated' ), 10, 3 );
		add_action( 'acf/save_post', array( self::class, 'on_acf_term_save' ), 20 );
	}

	/**
	 * Returns all public taxonomy slugs, minus any explicitly excluded ones.
	 *
	 * Use the `dexerto_taxonomy_revalidation_excluded_taxonomies` filter to add exclusions.
	 * Use the `dexerto_taxonomy_revalidation_taxonomies` filter to override the entire list.
	 *
	 * @return string[]
	 */
	public static function get_watched_taxonomies(): array {
		$excluded = apply_filters(
			'dexerto_taxonomy_revalidation_excluded_taxonomies',
			array( 'post_format', 'link_category', 'nav_menu', 'evergreen_menu_location' )
		);

		$all_public = get_taxonomies( array( 'public' => true ), 'names' );
		$watched    = array_values( array_diff( $all_public, is_array( $excluded ) ? $excluded : array() ) );

		$filtered = apply_filters( 'dexerto_taxonomy_revalidation_taxonomies', $watched );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $watched;
	}

	/**
	 * Returns the current configuration status.
	 *
	 * @return array{frontend_url: string, secret_set: bool, enabled: bool, watched_taxonomies: string[], ready: bool}
	 */
	private static function get_config_status(): array {
		$frontend_url = Settings::get( 'frontend_url' );
		$secret       = Settings::get( 'revalidate_secret_key' );
		$enabled      = Settings::get( 'taxonomy_revalidation_enabled' ) === 'on';

		return array(
			'frontend_url'       => $frontend_url,
			'secret_set'         => ! empty( $secret ),
			'enabled'            => $enabled,
			'watched_taxonomies' => self::get_watched_taxonomies(),
			'ready'              => $enabled && ! empty( $frontend_url ) && ! empty( $secret ),
		);
	}

	/**
	 * Shows a global admin notice when the plugin is misconfigured.
	 *
	 * @return void
	 */
	public static function maybe_show_config_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = self::get_config_status();

		if ( ! $status['enabled'] ) {
			return;
		}

		$missing = array();

		if ( empty( $status['frontend_url'] ) ) {
			$missing[] = 'Frontend URL';
		}

		if ( ! $status['secret_set'] ) {
			$missing[] = 'Revalidate Secret Key';
		}

		if ( empty( $missing ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=on-demand-revalidation' );
		$fields       = implode( ' and ', $missing );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>Taxonomy Revalidation:</strong> %s %s not configured in the <a href="%s">On-Demand Revalidation settings</a>. Taxonomy revalidation will not fire until this is resolved.</p></div>',
			esc_html( $fields ),
			count( $missing ) === 1 ? 'is' : 'are',
			esc_url( $settings_url )
		);
	}

	/**
	 * Shows a revalidation status notice on the term edit screen after a save.
	 *
	 * Reads the last revalidation result from term meta so editors get immediate
	 * feedback without needing to visit the log page.
	 *
	 * @return void
	 */
	public static function maybe_show_term_revalidation_notice(): void {
		global $pagenow;

		if ( 'term.php' !== $pagenow ) {
			return;
		}

		// Only show directly after a save (WP appends ?message=1 on success).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display check, no data is processed.
		if ( ! isset( $_GET['message'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display check, no data is processed.
		$term_id = isset( $_GET['tag_ID'] ) ? (int) $_GET['tag_ID'] : 0;
		if ( $term_id <= 0 ) {
			return;
		}

		$last = get_term_meta( $term_id, '_dexerto_tax_reval_last', true );
		if ( empty( $last ) || ! is_array( $last ) ) {
			return;
		}

		// Only show if the result is fresh (within the last 2 minutes).
		$timestamp = isset( $last['timestamp'] ) && is_numeric( $last['timestamp'] ) ? (int) $last['timestamp'] : 0;
		if ( ! $timestamp || time() - $timestamp > 120 ) {
			return;
		}

		$time_ago = human_time_diff( $timestamp );
		// Each path is run through esc_html() inside the array_map — the wrapping <code> tags are safe literals.
		$raw_paths = isset( $last['paths'] ) && is_array( $last['paths'] ) ? $last['paths'] : array();
		$paths     = $raw_paths ? implode( ', ', array_map( fn( $p ) => '<code>' . esc_html( $p ) . '</code>', $raw_paths ) ) : '—';

		if ( ! empty( $last['success'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>Revalidation triggered</strong> %s ago &mdash; paths sent: %s</p></div>',
				esc_html( $time_ago ),
				$paths // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		} else {
			$error = ! empty( $last['error'] ) ? $last['error'] : 'Unknown error';
			printf(
				'<div class="notice notice-error is-dismissible"><p><strong>Revalidation failed</strong> %s ago (HTTP %s) &mdash; %s. <a href="%s">View log &rarr;</a></p></div>',
				esc_html( $time_ago ),
				esc_html( (string) ( $last['http_code'] ?? 0 ) ),
				esc_html( $error ),
				esc_url( admin_url( 'tools.php?page=dexerto-taxonomy-revalidation' ) )
			);
		}
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
	 * Fires when ACF saves a post. Handles term saves from ACF field groups on term edit screens,
	 * covering featured article selections and other ACF-managed term detail fields.
	 *
	 * ACF uses the string format "term_{term_id}" as the $post_id for term saves.
	 *
	 * @param int|string $post_id The ACF post identifier.
	 * @return void
	 */
	public static function on_acf_term_save( int|string $post_id ): void {
		if ( ! is_string( $post_id ) || ! str_starts_with( $post_id, 'term_' ) ) {
			return;
		}

		$term_id = (int) substr( $post_id, 5 );
		if ( $term_id <= 0 ) {
			return;
		}

		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		if ( ! in_array( $term->taxonomy, self::get_watched_taxonomies(), true ) ) {
			return;
		}

		self::maybe_schedule_revalidation( $term_id, $term->taxonomy );
	}

	/**
	 * Fires revalidation for a term immediately, deduplicating within a 5-second window.
	 *
	 * The dedup window prevents double-firing when both `edited_term` and `acf/save_post`
	 * fire during the same term save request.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 */
	public static function maybe_schedule_revalidation( int $term_id, string $taxonomy ): void {
		$transient_key = 'dexerto_tax_reval_' . $term_id . '_' . $taxonomy;

		if ( get_transient( $transient_key ) ) {
			return;
		}

		set_transient( $transient_key, true, 5 );

		self::revalidate_term( $term_id, $taxonomy );
	}

	/**
	 * Performs the revalidation HTTP request for a given term synchronously.
	 *
	 * Revalidates the taxonomy landing page and homepage by default.
	 * Use the `dexerto_taxonomy_revalidation_paths` filter to add further surfaces.
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

		if ( empty( $frontend_url ) || empty( $secret ) ) {
			return;
		}

		$paths    = self::build_revalidation_paths( $term_id, $taxonomy );
		$endpoint = rtrim( $frontend_url, '/' ) . '/api/revalidate';

		if ( empty( $paths ) ) {
			self::log_revalidation_result( $term_id, $term_name, $taxonomy, array(), $endpoint, new \WP_Error( 'no_paths', 'Could not resolve a revalidation path for this term. Check that permalink structure is configured.' ) );
			return;
		}

		$response = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'PUT',
				'body'    => wp_json_encode( array( 'paths' => $paths ) ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				),
			)
		);

		self::log_revalidation_result(
			$term_id,
			$term_name,
			$taxonomy,
			$paths,
			$endpoint,
			$response
		);
	}

	/**
	 * Builds the list of paths to revalidate for a given term.
	 *
	 * @param int    $term_id  The term ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return string[] Filtered list of paths, or empty array on failure.
	 */
	public static function build_revalidation_paths( int $term_id, string $taxonomy ): array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return array();
		}

		$term_link = get_term_link( $term );
		if ( is_wp_error( $term_link ) ) {
			return array();
		}

		$path = wp_parse_url( $term_link, PHP_URL_PATH );
		if ( empty( $path ) || '/' === $path ) {
			return array();
		}

		$path = rtrim( $path, '/' );

		/**
		 * Filters the list of paths to revalidate for a given term.
		 *
		 * Defaults to the taxonomy landing page + homepage. Add further dependent surfaces
		 * (e.g. hub pages, listing aggregators) via this filter.
		 *
		 * @param string[] $paths Array of paths, e.g. ['/category/esports', '/'].
		 * @param \WP_Term $term  The term being updated.
		 */
		$filtered = apply_filters( 'dexerto_taxonomy_revalidation_paths', array( $path, '/' ), $term );

		if ( ! is_array( $filtered ) ) {
			return array( $path, '/' );
		}

		return array_values( array_unique( array_filter( $filtered, fn( $p ) => is_string( $p ) && '' !== $p ) ) );
	}

	/**
	 * Logs a revalidation attempt to a rolling WP option (last 50 entries).
	 * Also writes the result to term meta so it can be shown on the term edit screen.
	 *
	 * @param int             $term_id   The term ID.
	 * @param string          $term_name The term name.
	 * @param string          $taxonomy  The taxonomy slug.
	 * @param string[]        $paths     Paths that were submitted.
	 * @param string          $endpoint  The API endpoint called.
	 * @param array|\WP_Error $response  The wp_remote_request() response.
	 * @return void
	 */
	public static function log_revalidation_result( int $term_id, string $term_name, string $taxonomy, array $paths, string $endpoint, array|\WP_Error $response ): void {
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
			'endpoint'  => $endpoint,
			'http_code' => $http_code,
			'success'   => $success,
			'error'     => $is_wp_error
				? $response->get_error_message()
				: ( ! $success ? ( is_array( $body ) ? ( $body['message'] ?? 'Unexpected response from revalidation endpoint' ) : 'Unexpected response from revalidation endpoint' ) : null ),
		);

		update_term_meta( $term_id, '_dexerto_tax_reval_last', $entry );

		$log = get_option( 'dexerto_taxonomy_revalidation_log', array() );
		$log = array_slice( array_merge( array( $entry ), $log ), 0, 50 );
		update_option( 'dexerto_taxonomy_revalidation_log', $log, false );
	}

	/**
	 * Registers the admin page under Tools.
	 *
	 * @return void
	 */
	public static function register_admin_page(): void {
		add_management_page(
			'Taxonomy Revalidation',
			'Taxonomy Revalidation',
			'manage_options',
			'dexerto-taxonomy-revalidation',
			array( self::class, 'render_admin_page' )
		);
	}

	/**
	 * Renders the admin page: config status + revalidation activity log.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status       = self::get_config_status();
		$settings_url = admin_url( 'options-general.php?page=on-demand-revalidation' );

		?>
		<div class="wrap">
			<h1>Taxonomy Revalidation</h1>

			<?php if ( ! $status['enabled'] ) : ?>
				<p>Taxonomy revalidation is currently disabled. <a href="<?php echo esc_url( $settings_url ); ?>">Enable it in the On-Demand Revalidation settings &rarr;</a></p>
			<?php else : ?>
				<h2 style="margin-top:20px;">Configuration Status</h2>
				<?php self::render_config_status( $status ); ?>

				<hr style="margin:28px 0;" />

				<h2>Recent Activity</h2>
				<?php self::render_revalidation_log(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the configuration status table.
	 *
	 * @param array $status Result from get_config_status().
	 * @return void
	 */
	private static function render_config_status( array $status ): void {
		$ok           = '<span style="color:#46b450;font-weight:600;">&#10003; Yes</span>';
		$nok          = '<span style="color:#dc3232;font-weight:600;">&#10007; No</span>';
		$settings_url = admin_url( 'options-general.php?page=on-demand-revalidation' );

		?>
		<table class="widefat striped" style="max-width:620px;">
			<tbody>
				<tr>
					<td>
						Frontend URL configured
						<?php if ( ! empty( $status['frontend_url'] ) ) : ?>
							<br><small style="color:#666;"><?php echo esc_html( $status['frontend_url'] ); ?></small>
						<?php endif; ?>
					</td>
					<td>
						<?php echo wp_kses_post( ! empty( $status['frontend_url'] ) ? $ok : $nok ); ?>
						<?php if ( empty( $status['frontend_url'] ) ) : ?>
							&nbsp;<a href="<?php echo esc_url( $settings_url ); ?>" style="font-size:12px;">Configure &rarr;</a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>Revalidate secret key configured</td>
					<td>
						<?php echo wp_kses_post( $status['secret_set'] ? $ok : $nok ); ?>
						<?php if ( ! $status['secret_set'] ) : ?>
							&nbsp;<a href="<?php echo esc_url( $settings_url ); ?>" style="font-size:12px;">Configure &rarr;</a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td>Watched taxonomies</td>
					<td>
						<?php if ( ! empty( $status['watched_taxonomies'] ) ) : ?>
							<code><?php echo esc_html( implode( ', ', $status['watched_taxonomies'] ) ); ?></code>
						<?php else : ?>
							<em style="color:#666;">None (taxonomies load after init)</em>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders the recent revalidation activity log.
	 *
	 * @return void
	 */
	private static function render_revalidation_log(): void {
		$log = get_option( 'dexerto_taxonomy_revalidation_log', array() );

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
					<th>HTTP</th>
					<th>Result</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $log as $entry ) : ?>
				<?php
				$success = ! empty( $entry['success'] );
				// $time_label is run through esc_html() at assignment. $paths_list values are run through esc_html() inside array_map.
				$time_label = isset( $entry['timestamp'] ) ? esc_html( human_time_diff( $entry['timestamp'] ) . ' ago' ) : '—';
				$http_code  = ! empty( $entry['http_code'] ) ? $entry['http_code'] : '—';
				$paths_list = ! empty( $entry['paths'] )
					? implode( '<br>', array_map( fn( $p ) => '<code>' . esc_html( $p ) . '</code>', $entry['paths'] ) )
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
