<?php

namespace OnDemandRevalidation;

use OnDemandRevalidation\Revalidation;

class Admin
{
    protected static $option = 'on-demand-revalidation';

    public static function init()
    {
        if (is_admin()) {
            self::settings();
            self::adminRender();

            Revalidation::testRevalidationButton();
        }
    }

    private static function adminRender()
    {
        add_action('admin_menu', function () {
            add_options_page(
                'Next.js On-Demand Revalidation',
                'Next.js On-Demand Revalidation',
                'manage_options',
                'on-demand-revalidation',
                function () {
                    if (!current_user_can('manage_options')) {
                        wp_die(__('You do not have sufficient permissions to access this page.'));
                    } ?>
					<div class="wrap">
						<h1>Next.js On-Demand Revalidation</h1>
						<form method="post" action="options.php">
						<?php
                    settings_fields(self::$option);
                    do_settings_sections(self::$option);
                    submit_button(); ?>
						</form>
					</div>
					<?php
                }
            );
        });
    }

    private static function settings()
    {
        add_action('admin_init', function () {
            register_setting(self::$option, self::$option);
            add_settings_section(
                'general',
                'General',
                function () {
                },
                self::$option
            );
            add_settings_field(
                'frontend_url',
                'Next.js URL',
                function () {
                    $field = 'frontend_url';
                    $option = get_option(self::$option);
                    $value = $option[$field] ? esc_attr($option[$field]) : get_site_url();
                    echo('<input type="text" name="'.self::$option.'['.$field.']" value="'.$value.'" />');
                },
                self::$option,
                'general'
            );
            add_settings_field(
                'revalidate_secret_key',
                'Revalidate Secret Key',
                function () {
                    $field = 'revalidate_secret_key';
                    $option = get_option(self::$option);
                    $value = $option[$field] ? esc_attr($option[$field]) : '';
                    echo('<input type="password" name="'.self::$option.'['.$field.']" value="'.$value.'" />');
                },
                self::$option,
                'general'
            );

            add_settings_section(
                'on_post_update',
                'On post update:',
                function () {
                    echo '<p class="description">On post update is current page revalidated automatically.</p>';
                },
                self::$option
            );
            add_settings_field(
                'revalidate_homepage',
                'Revalidate Homepage',
                function () {
                    $field = 'revalidate_homepage';
                    $option = get_option(self::$option);
                    echo '<input type="checkbox" id="'.$field.'" name="'.self::$option.'['.$field.']" value="1"' . checked('1', isset($option[$field]), false) . '/>';
                    echo '<label for="'.$field.'">Revalidate Homepage on post update</label>';
                },
                self::$option,
                'on_post_update'
            );
            add_settings_field(
                'revalidate_paths',
                'Additional paths to revalidate on Post update',
                function () {
                    $field = 'revalidate_paths';
                    $option = get_option(self::$option);
                    $value = $option[$field] ? esc_attr($option[$field]) : '';
                    $placeholder = '/category/%categories%';
                    echo '<textarea type="textarea" name="'.self::$option.'['.$field.']" placeholder="'. $placeholder .'" rows="8" cols="40">'.$value.'</textarea>';
                    echo '<p class="description">One path per row.</p>';
                    echo '<p class="description"><i>Available current Post placeholders:</i></p>';
                    echo '<p class="description"><code>%slug%</code> <code>%author_nicename%</code> <code>%categories%</code> <code>%tags%</code></p>';
                    echo '<p class="description"><br/>Test your config:</p>';
                    echo '<p><a id="on-demand-revalidation-post-update-test" class="button button-primary" style="margin-bottom: 15px;">Revalidate Latest Post</a></p>';
                },
                self::$option,
                'on_post_update'
            );
        });
    }
}
