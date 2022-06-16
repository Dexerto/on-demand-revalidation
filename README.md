# On-Demand Revalidation

Next.js On-Demand Revalidation for Wordpress on the post update, revalidate specific paths on the post update.

## Installation
- In your Next.js project add new file `/pages/api/revalidate.ts` with this code:
```
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
```
- Add `REVALIDATE_SECRET_KEY` env variable to your Next.js with Revalidate Secret Key value you added in the Plugin Settings.
