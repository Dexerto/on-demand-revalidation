#!/bin/bash

#Site configuration options
SITE_TITLE="Dev Site"
ADMIN_USER=admin
ADMIN_PASS=password
ADMIN_EMAIL="admin@localhost.com"
#Space-separated list of plugin ID's to install and activate
PLUGINS=""

#Set to true to wipe out and reset your wordpress install (on next container rebuild)
WP_RESET=true

echo "Setting up WordPress"
DEVDIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd /var/www/html;

# Function to wait for database to be ready
wait_for_db() {
    echo "Waiting for database connection..."
    max_attempts=30
    attempt=0

    # Create a temporary wp-config.php file if it doesn't exist
    if [ ! -f wp-config.php ]; then
        echo "Creating temporary wp-config.php for database check..."
        wp config create --dbhost="db" --dbname="wordpress" --dbuser="wp_user" --dbpass="wp_pass" --skip-check
    fi

    echo "Checking database connection..."

    while [ $attempt -lt $max_attempts ]; do
        echo "Attempting to connect to database... ($attempt/$max_attempts)"
        if wp db check --quiet 2>/dev/null; then
            echo "Database is ready!"
            return 0
        fi

        attempt=$((attempt+1))
        echo "Waiting for database... ($attempt/$max_attempts)"
        echo "Attempt $attempt: $(date)"
        sleep 2
    done

    echo "Could not connect to database after $max_attempts attempts"
    return 1
}

# Wait for database to be ready
wait_for_db || exit 1

if $WP_RESET ; then
    echo "Resetting WP"
    if [ -f wp-config.php ]; then
        wp plugin delete $PLUGINS || true
        wp db reset --yes || true
        rm -f wp-config.php
    fi
fi

if [ ! -f wp-config.php ]; then
    echo "Configuring WordPress..."
    wp config create --dbhost="db" --dbname="wordpress" --dbuser="wp_user" --dbpass="wp_pass" --skip-check

    # Add debug settings
    echo "Enabling debug settings..."
    wp config set WP_DEBUG true --raw
    wp config set WP_DEBUG_LOG \'/var/www/html/wp-content/logs/debug.log\' --raw
    wp config set WP_DEBUG_DISPLAY false --raw

    # Create logs directory if it doesn't exist
    mkdir -p /var/www/html/wp-content/logs
    chmod 777 /var/www/html/wp-content/logs

    echo "Installing WordPress core..."
    wp core install --url="http://localhost:8080" --title="$SITE_TITLE" --admin_user="$ADMIN_USER" --admin_email="$ADMIN_EMAIL" --admin_password="$ADMIN_PASS" --skip-email

    echo "Installing plugins..."
    wp plugin install $PLUGINS --activate

    # Install and activate a default theme
    echo "Installing default WordPress theme..."
    wp theme install twentytwentyfive --activate

    # Activate the plugin being developed
    echo "Activating development plugin..."
    wp plugin activate plugin-dev || echo "Note: plugin-dev not activated. This is normal for a new plugin."

    # Call the post types setup script
    echo "Configuring custom post types..."
    chmod +x "$DEVDIR/setup-post-types.sh"
    . "$DEVDIR/setup-post-types.sh"

    # Ensure data directory exists
    mkdir -p $DEVDIR/data

    # Data import
    echo "Checking for SQL files to import..."
    cd $DEVDIR/data/
    if ls *.sql 2>/dev/null; then
        for f in *.sql; do
            echo "Importing $f..."
            wp db import $f
        done
    else
        echo "No SQL files found to import."
    fi

    # Ensure plugins directory exists
    mkdir -p $DEVDIR/data/plugins

    # Copy and activate plugins
    echo "Checking for plugins to install..."
    if [ -d "plugins" ] && [ "$(ls -A plugins 2>/dev/null)" ]; then
        cp -r plugins/* /var/www/html/wp-content/plugins 2>/dev/null || echo "No plugins to copy."
        for p in plugins/*; do
            if [ -d "$p" ]; then
                plugin_name=$(basename "$p")
                echo "Activating plugin: $plugin_name"
                wp plugin activate "$plugin_name" || echo "Could not activate $plugin_name"
            fi
        done
    else
        echo "No plugins found to install."
    fi

else
    echo "WordPress already configured"
fi

echo "WordPress setup complete!"
