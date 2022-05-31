<?php

namespace OnDemandRevalidation;

use OnDemandRevalidation\Settings;
use OnDemandRevalidation\Helpers;
use WP_Error;

class Revalidation
{
    public static function init()
    {
        add_action('wp_insert_post', function ($post_ID, $post, $update) {
            if (wp_is_post_revision($post_ID)) {
                return;
            }

            self::revalidate($post);
        }, 10, 3);
    }

    public static function revalidate($post)
    {
        $settings = Settings::get();

        if (!($settings['frontend_url'] || $settings['revalidate_secret_key'])) {
            return new WP_Error('rest_forbidden', __('Fill Next.js URL and Revalidate Secret Key first.', 'on-demand-revalidation'), [ 'status' => 401 ]);
        }


        $paths = [];

        if (!empty($settings['revalidate_homepage'])) {
            $paths[] = '/';
        }

        $pagePath = "/$post->post_name";
        $paths[] = substr($pagePath, -1) == '/' ? substr($pagePath, 0, -1) : $pagePath;

        $revalidatePaths = trim($settings['revalidate_paths']);
        $revalidatePaths = preg_split('/\r\n|\n|\r/', $revalidatePaths);
        $revalidatePaths = Helpers::rewritePaths($revalidatePaths, $post);

        if ($revalidatePaths) {
            foreach ($revalidatePaths as $path) {
                if (str_starts_with($path, '/')) {
                    $paths[] = $path;
                }
            }
        }

        $data = json_encode(['paths' => $paths]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$settings['frontend_url']}/api/revalidate");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data),
            'Authorization: Bearer '. trim($settings['revalidate_secret_key'])
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = json_decode(curl_exec($ch));

        if (curl_error($ch)) {
            return new WP_Error('revalidate_error', curl_error($ch), [ 'status' => 403 ]);
        }

        curl_close($ch);

        if (!$response->revalidated) {
            return new WP_Error('revalidate_error', $response->message, [ 'status' => 403 ]);
        }

        $revalidated = implode(', ', $paths);

        return (object) [
            'success' => $response->revalidated,
            'message' => "Next.js revalidated $revalidated successfully."
        ];
    }

    public static function testRevalidationButton()
    {
        add_action('admin_footer', function () { ?>
			<script type="text/javascript" >
				jQuery('#on-demand-revalidation-post-update-test').on('click', function () {
					jQuery.post(ajaxurl, { action: 'revalidation-post-update-test' }, function(response) {
						alert(response?.message || response?.errors?.revalidate_error[0] || JSON.stringify(response.errors));
					});
				});
			</script> <?php
        });

        add_action('wp_ajax_revalidation-post-update-test', function () {
            if (!current_user_can('edit_posts')) {
                $response = new WP_Error('rest_forbidden', __('You cannot edit posts.', 'on-demand-revalidation'), [ 'status' => 401 ]);
            }

            $latestPost = get_posts([
                'numberposts' => 1,
                'post_status' => 'publish'
            ])[0];
            $response = self::revalidate($latestPost);

            wp_send_json($response);
            wp_die();
        });
    }
}
