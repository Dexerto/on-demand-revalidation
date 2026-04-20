#!/bin/bash
# Idempotent WordPress bootstrap for the DDEV environment.
# Runs inside the web container on every `ddev start` via post-start hook.

set -euo pipefail

PROJECT_ROOT="/var/www/html"
WP_DIR="${PROJECT_ROOT}/wordpress"
PLUGIN_LINK="${WP_DIR}/wp-content/plugins/plugin-dev"
MU_PLUGIN="${WP_DIR}/wp-content/mu-plugins/custom-post-types.php"
LOGS_DIR="${WP_DIR}/wp-content/logs"

SITE_TITLE="Dev Site"
ADMIN_USER="admin"
ADMIN_PASS="password"
ADMIN_EMAIL="admin@example.com"

write_mu_plugin() {
  mkdir -p "$(dirname "${MU_PLUGIN}")"
  cat > "${MU_PLUGIN}" <<'PHP'
<?php
/**
 * Plugin Name: Custom Post Types
 * Description: Registers custom post types and taxonomies used when developing the plugin.
 */

add_action(
	'init',
	function () {
		register_post_type(
			'product',
			array(
				'labels'       => array(
					'name'          => 'Products',
					'singular_name' => 'Product',
					'menu_name'     => 'Products',
				),
				'public'       => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-cart',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'show_in_rest' => true,
			)
		);

		register_post_type(
			'service',
			array(
				'labels'       => array(
					'name'          => 'Services',
					'singular_name' => 'Service',
					'menu_name'     => 'Services',
				),
				'public'       => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-clipboard',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			'product_category',
			array( 'product' ),
			array(
				'labels'            => array(
					'name'          => 'Product Categories',
					'singular_name' => 'Product Category',
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'product-category' ),
			)
		);

		register_taxonomy(
			'product_tag',
			array( 'product' ),
			array(
				'labels'            => array(
					'name'          => 'Product Tags',
					'singular_name' => 'Product Tag',
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'product-tag' ),
			)
		);

		register_taxonomy(
			'service_type',
			array( 'service' ),
			array(
				'labels'            => array(
					'name'          => 'Service Types',
					'singular_name' => 'Service Type',
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'service-type' ),
			)
		);

		register_taxonomy(
			'region',
			array( 'product', 'service' ),
			array(
				'labels'            => array(
					'name'          => 'Regions',
					'singular_name' => 'Region',
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'region' ),
			)
		);
	}
);
PHP
}

mkdir -p "${WP_DIR}"
cd "${WP_DIR}"

if [ ! -f "${WP_DIR}/wp-includes/version.php" ]; then
  echo "Downloading WordPress core..."
  wp core download --path="${WP_DIR}" --force
fi

mkdir -p "${WP_DIR}/wp-content/plugins"
if [ ! -L "${PLUGIN_LINK}" ]; then
  echo "Linking plugin source into wp-content/plugins/plugin-dev..."
  rm -rf "${PLUGIN_LINK}"
  ln -s "${PROJECT_ROOT}" "${PLUGIN_LINK}"
fi

if [ ! -f "${WP_DIR}/wp-config.php" ]; then
  echo "Creating wp-config.php..."
  wp config create \
    --dbhost="db" \
    --dbname="db" \
    --dbuser="db" \
    --dbpass="db" \
    --skip-check \
    --force
  wp config set WP_DEBUG true --raw
  wp config set WP_DEBUG_LOG "'/var/www/html/wordpress/wp-content/logs/debug.log'" --raw
  wp config set WP_DEBUG_DISPLAY false --raw
fi

mkdir -p "${LOGS_DIR}"
chmod 777 "${LOGS_DIR}"

# Write the mu-plugin before any WP-CLI call that needs the CPTs/taxonomies
# (wp core install loads mu-plugins at bootstrap, so term seeding works).
if [ ! -f "${MU_PLUGIN}" ]; then
  echo "Installing custom post types + taxonomies mu-plugin..."
  write_mu_plugin
fi

if ! wp core is-installed 2>/dev/null; then
  echo "Installing WordPress..."
  wp core install \
    --url="${DDEV_PRIMARY_URL}" \
    --title="${SITE_TITLE}" \
    --admin_user="${ADMIN_USER}" \
    --admin_password="${ADMIN_PASS}" \
    --admin_email="${ADMIN_EMAIL}" \
    --skip-email

  wp theme install twentytwentyfive --activate || true
  wp plugin activate plugin-dev || echo "plugin-dev not activated yet (likely missing vendor/) — run 'ddev composer install' then 'ddev wp plugin activate plugin-dev'"

  echo "Seeding taxonomy terms..."
  # product_category (hierarchical)
  wp term create product_category "Electronics" --slug=electronics || true
  wp term create product_category "Apparel"     --slug=apparel     || true
  wp term create product_category "Home"        --slug=home        || true
  HOME_ID=$(wp term list product_category --slug=home --field=term_id 2>/dev/null || echo "")
  if [ -n "${HOME_ID}" ]; then
    wp term create product_category "Kitchen" --slug=kitchen --parent="${HOME_ID}" || true
    wp term create product_category "Garden"  --slug=garden  --parent="${HOME_ID}" || true
  fi

  # product_tag (flat)
  wp term create product_tag "Featured" --slug=featured || true
  wp term create product_tag "Sale"     --slug=sale     || true
  wp term create product_tag "New"      --slug=new      || true

  # service_type (hierarchical)
  wp term create service_type "Consulting" --slug=consulting || true
  wp term create service_type "Support"    --slug=support    || true
  wp term create service_type "Training"   --slug=training   || true

  # region (flat, shared between product + service)
  wp term create region "US East" --slug=us-east || true
  wp term create region "US West" --slug=us-west || true
  wp term create region "EU"      --slug=eu      || true
fi

echo "WordPress ready at ${DDEV_PRIMARY_URL}/wp-admin (admin / password)"
