=== On-Demand Revalidation ===
Contributors: bebjakub
Tags: nextjs, ssg, revalidation, on-demand
Requires at least: 4.7
Tested up to: 6.0
Stable tag: 1.0.2
Requires PHP: 5.6
License: GPL-3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

=== Description ===

Next.js On-Demand Revalidation for Wordpress on the post update, revalidate specific paths on the post update.

== Installation ==

1. Search for the plugin in WordPress under "Plugins -> Add New".
2. Click the “Install Now” button, followed by "Activate".
3. Add Next.js URL and Revalidate Secret Key in the Settings -> Next.js On-Demand Revalidation
4. In your Next.js project add new file `/pages/api/revalidate.ts` with this code:
`
import { NextApiRequest, NextApiResponse } from "next"

export default async function handler(req: NextApiRequest, res: NextApiResponse) {
    const {
        body: { paths },
        method,
    } = req

    if (req.headers.authorization !== `Bearer ${process.env.REVALIDATE_SECRET_KEY}`) {
        return res.status(401).json({ message: 'Invalid token' })
    }

    if (method !== 'PUT') {
        return res.status(405).json({ message: `Method ${method} Not Allowed` })
    }

    if (!paths) {
        return res.status(412).json({ message: 'No paths' })
    }

    try {
        const revalidatePaths = paths
            .filter((path: string) => path.startsWith('/'))
            .map((path: string) => res.unstable_revalidate(
                path,
                { unstable_onlyGenerated: false }
            ));

        await Promise.all(revalidatePaths);

        return res.json({ revalidated: true, message: 'Paths revalidated' })

    } catch (err) {

        return res.status(500).json({ message: err.message })
    }
}
`
5. Add `REVALIDATE_SECRET_KEY` env variable to your Next.js with Revalidate Secret Key value you added in the Plugin Settings.

== Changelog ==

= 1.0.2 =
- publish plugin
