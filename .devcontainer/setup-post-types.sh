#!/bin/bash

# setup-post-types.sh
# Script to set up custom post types as mu-plugins in WordPress

echo "Setting up custom post types via mu-plugin..."
mkdir -p /var/www/html/wp-content/mu-plugins

# Create mu-plugin file if it doesn't exist
if [ ! -f /var/www/html/wp-content/mu-plugins/custom-post-types.php ]; then
    echo "Creating custom post types mu-plugin..."
    cat > /var/www/html/wp-content/mu-plugins/custom-post-types.php << 'EOL'
<?php
/**
 * Plugin Name: Custom Post Types
 * Description: Registers custom post types for the site
 * Version: 1.0
 * Author: Admin
 */

// Register Custom Post Types
function register_custom_post_types() {

    // Products Post Type
    register_post_type('product', array(
        'labels' => array(
            'name'               => 'Products',
            'singular_name'      => 'Product',
            'menu_name'          => 'Products',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Product',
            'edit_item'          => 'Edit Product',
        ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-cart',
        'supports'      => array('title', 'editor', 'thumbnail', 'excerpt'),
        'show_in_rest'  => true, // Enable Gutenberg editor
    ));

    // Services Post Type
    register_post_type('service', array(
        'labels' => array(
            'name'               => 'Services',
            'singular_name'      => 'Service',
            'menu_name'          => 'Services',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Service',
            'edit_item'          => 'Edit Service',
        ),
        'public'        => true,
        'has_archive'   => true,
        'menu_icon'     => 'dashicons-clipboard',
        'supports'      => array('title', 'editor', 'thumbnail', 'excerpt'),
        'show_in_rest'  => true, // Enable Gutenberg editor
    ));

    // Add more custom post types as needed
}

add_action('init', 'register_custom_post_types');
EOL
    echo "Custom post types mu-plugin created."
else
    echo "Custom post types mu-plugin already exists."
fi
