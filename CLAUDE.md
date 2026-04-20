# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

WordPress plugin that triggers Next.js on-demand revalidation (paths + tags) when posts are updated. Ships to wordpress.org; PHP is the primary language. See @README.md for the Next.js-side API route examples that must be installed in the consumer app.

## Commands

Dev environment is **DDEV** (`.ddev/config.yaml`). `ddev start` bootstraps WordPress into `./wordpress/` (gitignored), symlinks the plugin into `wordpress/wp-content/plugins/plugin-dev`, and runs `composer install`. Site is served at `https://on-demand-revalidation.ddev.site` (admin: `admin`/`password`). See `.ddev/scripts/wp-setup.sh` for what the post-start hook does.

```bash
# Environment
ddev start                    # boot stack + run wp-setup.sh + composer install
ddev wp-reset                 # drop DB and re-run bootstrap (custom command)
ddev xdebug on                # Xdebug off by default; VS Code config in .vscode/launch.json
ddev wp <cmd>                 # WP-CLI against the dev site
ddev ssh                      # shell into web container (cwd = /var/www/html)

# PHP (run on host or via `ddev exec`)
composer install              # required after clone — autoloader drives the plugin
composer run phpcs            # WPCS + VIPCS lint (see phpcs.xml.dist)
composer run phpcbf           # auto-fix phpcs
composer run test             # PHPUnit (uses WP_Mock, NOT a WP test install — no DDEV needed)
./vendor/bin/phpunit --filter test_name   # single test

# JS/TS (wp-scripts) — run on host
npm run start                 # webpack watch
npm run build                 # production build → ./build
npm run typecheck
npm run lint                  # runs lint:js, lint:ts, lint:css, lint:php, typecheck
npm run test:js               # jest via wp-scripts
```

PHP 8.2+ is required (see composer.json). Commits must follow conventional-commits (commitlint + husky).

### Filesystem layout inside the web container

- `/var/www/html` = project root (this repo)
- `/var/www/html/wordpress` = WP install (docroot)
- `/var/www/html/wordpress/wp-content/plugins/plugin-dev` → symlink back to `/var/www/html`

Xdebug path mapping is `/var/www/html` → `${workspaceFolder}`. The plugin's own files resolve cleanly through the symlink.

## Architecture

### Bootstrap
`on-demand-revalidation.php` is a singleton (`OnDemandRevalidation::instance()`) that loads the Composer autoloader and wires three pieces:
1. `Admin\Settings::init()` — registers the WP admin options page.
2. `Revalidation::init()` — attaches post-lifecycle hooks.
3. `Helpers::prevent_wrong_api_url()` — rewrites `rest_url` when `home_url !== site_url` (needed for headless installs).

PSR-4 maps `OnDemandRevalidation\` → `src/`. If `vendor/autoload.php` is missing, the plugin renders an admin notice and aborts — always run `composer install` before testing.

### Revalidation flow (`src/Revalidation.php`)
The flow that matters:
1. `pre_post_update` / `wp_trash_post` stash the current permalink into `_old_permalink` post meta **before** the update lands — this is how slug changes still revalidate the old URL.
2. `save_post` and `transition_post_status` call `revalidate_post()`, which either fires immediately (if the `disable_cron` setting is on) or schedules `on_demand_revalidation_on_post_update` via `wp_schedule_single_event`.
3. `revalidate()` builds the `paths` + `tags` payload and `PUT`s it to `{frontend_url}/api/revalidate` with a `Bearer {revalidate_secret_key}` header. The Next.js side is expected to return `{ revalidated: true, message }`.

Paths assembled per call: homepage (`/`) if enabled, current permalink, `_old_permalink` if set, global extra paths, per-post-type extra paths. All strings run through `Helpers::rewrite_placeholders()` and final arrays go through `apply_filters('on_demand_revalidation_paths', …)` / `…_tags` for extension.

### Settings model (`src/Admin/Settings.php`, `src/Admin/SettingsRegistry.php`)
Settings are split across multiple `wp_options` rows, one per section:
- `on_demand_revalidation_default_settings` — URL, secret, cron toggle.
- `on_demand_revalidation_post_update_settings` — global paths/tags/homepage.
- `on_demand_revalidation_{post_type}_settings` — per-post-type overrides, generated dynamically from `get_post_types({public: true})` (attachments excluded).

Always read settings via `Settings::get($option, $default, $section)`. Per-post-type toggles fall back to global when the per-type value is `null` (see `revalidate_homepage` logic in `revalidate()`).

### Placeholder system (`src/Helpers.php`)
`rewrite_placeholders()` expands tokens in path/tag strings: `%slug%`, `%database_id%`, `%id%` (base64-encoded, matches WPGraphQL global IDs), `%author_nicename%`, `%author_username%`, `%categories%`, `%post_tag%`, and any custom taxonomy name. Taxonomy placeholders fan out — one input row with `%category%` becomes N output paths (one per term). Keep this fan-out behavior in mind when changing the function.

### Extensibility hooks
- `on_demand_revalidation_init` — fires after boot.
- `on_demand_revalidation_paths` / `on_demand_revalidation_tags` — filter the final arrays.
- `on_demand_revalidation_on_post_update` — the scheduled action; plugins can hook here to piggyback work.

### Tests
`tests/bootstrap.php` loads Composer autoload and boots `WP_Mock` — there is **no** WP test install. Tests must mock WP functions via `WP_Mock::userFunction()` / Brain\Monkey. Files must be named `test_*.php` (see `phpunit.xml.dist` suite config).
