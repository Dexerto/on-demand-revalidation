# On-Demand Revalidation

Next.js On-Demand Revalidation for Wordpress on the post update, revalidate specific paths on the post update.

## Installation

### Pages Router
- In your Next.js project add new file `/pages/api/revalidate.ts` with this code:
```ts
import { NextApiRequest, NextApiResponse } from "next"

export default async function handler(req: NextApiRequest, res: NextApiResponse) {
    const {
        body: { paths, postId },
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

    const correctPaths = paths.filter((path: string) => path.startsWith('/'))

    try {
        const revalidatePaths = correctPaths.map((path: string) => res.revalidate(
            path,
            { unstable_onlyGenerated: false }
        ));

        await Promise.all(revalidatePaths);

        // Logging for debugging purposes only
        console.log(`${new Date().toJSON()} - Paths revalidated: ${correctPaths.join(', ')}`)

        return res.json({
            revalidated: true,
            message: `Paths revalidated: ${correctPaths.join(', ')}`
        })

    } catch (err) {

        return res.status(500).json({ message: err.message })
    }
}
```
### App Router (with tags support)
- In your Next.js project add new file `/app/api/revalidate/route.ts` with this code:
```ts
import { revalidatePath, revalidateTag } from 'next/cache';
import { headers } from 'next/headers';
import { NextRequest } from 'next/server';

/**
 * Constants for HTTP Status codes.
 */
const STATUS_CODES = {
	UNAUTHORIZED: 401,
	PRECONDITION_FAILED: 412,
	INTERNAL_SERVER_ERROR: 500,
};

const { REVALIDATE_SECRET_KEY } = process.env;

if (!REVALIDATE_SECRET_KEY) {
	throw new Error('Missing REVALIDATE_SECRET_KEY environment variable');
}

export async function PUT(request: NextRequest) {
	const { paths, tags }: { paths?: string[]; tags?: string[] } =
		await request.json();

	console.log('Received paths:', paths);
	console.log('Received tags:', tags);

	const headersList = headers();
	const authorizationHeader = headersList.get('authorization');

	console.log('Authorization header:', authorizationHeader);

	if (authorizationHeader !== `Bearer ${REVALIDATE_SECRET_KEY}`) {
		console.error(`Invalid token: ${authorizationHeader}`);
		return new Response(`Invalid token`, { status: STATUS_CODES.UNAUTHORIZED });
	}

	if (!paths && !tags) {
		console.error(`Precondition Failed: Missing paths and tags`);
		return new Response(`Precondition Failed: Missing paths and tags`, {
			status: STATUS_CODES.PRECONDITION_FAILED,
		});
	}

	let revalidatePaths: string[] = [];
	let correctTags: string[] = [];

	if (paths) {
		revalidatePaths = paths.filter((path) => path.startsWith('/'));

		console.log('Filtered correct paths:', revalidatePaths);
	}

	if (tags) {
		correctTags = tags.filter((tag) => typeof tag === 'string');
		console.log('Filtered correct tags:', correctTags);
	}

	try {
		revalidatePaths.forEach((path) => {
			revalidatePath(path);
		});

		correctTags.forEach((tag) => {
			revalidateTag(tag);
		});

		console.log(
			`${new Date().toJSON()} - Paths and tags revalidated: ${revalidatePaths.join(
				', '
			)} and ${correctTags.join(', ')}`
		);

		return new Response(
			JSON.stringify({
				revalidated: true,
				message: `Paths and tags revalidated: ${revalidatePaths.join(
					', '
				)} and ${correctTags.join(', ')}`,
			}),
			{
				status: 200,
				headers: {
					'Content-Type': 'application/json',
				},
			}
		);
	} catch (err: unknown) {
		let message: string;

		if (err instanceof Error) {
			message = err.message;
		} else {
			message = 'An error occurred';
		}
		console.error('Revalidation error:', message);
		return new Response(message, {
			status: STATUS_CODES.INTERNAL_SERVER_ERROR,
		});
	}
}
```
- Add `REVALIDATE_SECRET_KEY` env variable to your Next.js with Revalidate Secret Key value you added in the Plugin Settings.
___

## Troubleshooting

-  Revalidation on post update is not working: [Next.js](https://github.com/wpengine/faustjs/discussions/842), [WP-Cron](https://github.com/gdidentity/on-demand-revalidation/issues/4#issuecomment-1304602677)
