=== On-Demand Revalidation ===
Contributors: bebjakub
Tags: nextjs, ssg, revalidation, on-demand
Requires at least: 4.7
Tested up to: 6.0
Stable tag: 1.0.6
Requires PHP: 5.6
License: GPL-3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

=== Description ===

Next.js On-Demand Revalidation for Wordpress on the post update, revalidate specific paths on the post update.

Feel free to create PR to [plugin Github repo](https://github.com/gdidentity/on-demand-revalidation).

== Installation ==

1. Search for the plugin in WordPress under "Plugins -> Add New".
2. Click the “Install Now” button, followed by "Activate".
3. Add Next.js URL and Revalidate Secret Key in the Settings -> Next.js On-Demand Revalidation
4. In your Next.js project add a new file `/pages/api/revalidate.ts` with a code snippet, you'll find [here](https://github.com/gdidentity/on-demand-revalidation).
5. Add `REVALIDATE_SECRET_KEY` env variable to your Next.js with Revalidate Secret Key value you added in the Plugin Settings.


== Changelog ==

= 1.0.6 =
- add filter on_demand_revalidation_paths
