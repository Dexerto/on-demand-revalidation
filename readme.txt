=== On-Demand Revalidation ===
Contributors: bebjakub
Tags: nextjs, ssg, revalidation, on-demand
Requires at least: 6.0.0
Tested up to: 6.7.2
Stable tag: 1.3.0
Requires PHP: 8.0
License: GPL-3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

=== Description ===

Next.js On-Demand Revalidation for Wordpress on post updates, revalidate specific paths and tags on post updates.

Feel free to create a PR to [plugin Github repo](https://github.com/Dexerto/on-demand-revalidation).

== Installation ==

1. Search for the plugin in WordPress under "Plugins -> Add New".
2. Click the “Install Now” button, followed by "Activate".
3. Add Next.js URL and Revalidate Secret Key in the Settings -> Next.js On-Demand Revalidation
4. In your Next.js project add a new file `/pages/api/revalidate.ts` or `/app/api/revalidate/route.ts` with a code snippet, you'll find [here](https://github.com/Dexerto/on-demand-revalidation).
5. Add `REVALIDATE_SECRET_KEY` env variable to your Next.js with Revalidate Secret Key value you added in the Plugin Settings.


== Changelog ==
= 1.3.0 =
- feat: Add ability to provide custom paths and tags per post type from @MuhammedAO
- fix: prevent locales from being loaded too early @humet
= 1.2.5 =
- feat: prevent revalidate functions from running more than once within a single save_post request from @MuhammedAO
- fix: tags array populated by paths filter from @cavemon
- fix: paths array empty if `revalidate_paths` is not defined from @cavemon
- fix: better error handling from @humet
= 1.2.4 =
- fix: do not send non-replaced string if term is not there
= 1.2.3 =
- fix: Rename database id placeholder
- fix: Remove unwanted default placeholders
- fix: Do not send items if empty
= 1.2.2 =
- feat: Added the rewrite_placeholders function to dynamically replace placeholders like `%slug%`, `%id%`, `%categories%`, and `%tags%` with actual post data from @MuhammedAO
= 1.2.1 =
- fix: renamed filenames to match PSR-4 compliance  from @MuhammedAO
= 1.2.0 =
- feat: Allow tags and paths to be sent to the revalidation API to support `revalidateTags` and `revalidatePaths` independently from @MuhammedAO

= 1.1.3 =
- fix: Add old permalink tracking to revalidation process from @humet

= 1.1.2 =
- fix: reduce unnecessary revalidations from @humet

= 1.1.1 =
- Allow custom taxonomies revalidation from @humet

= 1.0.16 =
- fix from @slimzc

= 1.0.15 =
- Add postId to revalidation request

= 1.0.13 =
- Add Disable scheduled revalidation option

= 1.0.11 =
- Connect with Cloudflare purge cache plugin

= 1.0.9 =
- Update readme, minors. Thank you @gibix!

= 1.0.8 =
- Fix: ensure correct deep paths for posts. Thank you @pressoholics!

= 1.0.7 =
- Fix: address conflicts with wpgraphql plugin. Thank you @pressoholics!

= 1.0.6 =
- add filter on_demand_revalidation_paths
