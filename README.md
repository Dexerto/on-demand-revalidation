# On-Demand Revalidation

Next.js On-Demand Revalidation for Wordpress on the post update, revalidate specific paths on the post update.

## Installation

### Pages Router

- In your Next.js project add new file `/pages/api/revalidate.ts` with this code:

```ts
import { NextApiRequest, NextApiResponse } from 'next';

export default async function handler(req: NextApiRequest, res: NextApiResponse) {
  const {
    body: { paths, postId },
    method,
  } = req;

  if (req.headers.authorization !== `Bearer ${process.env.REVALIDATE_SECRET_KEY}`) {
    return res.status(401).json({ message: 'Invalid token' });
  }

  if (method !== 'PUT') {
    return res.status(405).json({ message: `Method ${method} Not Allowed` });
  }

  if (!paths) {
    return res.status(412).json({ message: 'No paths' });
  }

  const correctPaths = paths.filter((path: string) => path.startsWith('/'));

  try {
    const revalidatePaths = correctPaths.map((path: string) =>
      res.revalidate(path, { unstable_onlyGenerated: false })
    );

    await Promise.all(revalidatePaths);

    // Logging for debugging purposes only
    console.log(`${new Date().toJSON()} - Paths revalidated: ${correctPaths.join(', ')}`);

    return res.json({
      revalidated: true,
      message: `Paths revalidated: ${correctPaths.join(', ')}`,
    });
  } catch (err) {
    return res.status(500).json({ message: err.message });
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
  const { paths, tags }: { paths?: string[]; tags?: string[] } = await request.json();

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
    revalidatePaths = paths.filter(path => path.startsWith('/'));

    console.log('Filtered correct paths:', revalidatePaths);
  }

  if (tags) {
    correctTags = tags.filter(tag => typeof tag === 'string');
    console.log('Filtered correct tags:', correctTags);
  }

  try {
    revalidatePaths.forEach(path => {
      revalidatePath(path);
    });

    correctTags.forEach(tag => {
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

---

## Local Development

### Prerequisites

- [Docker](https://www.docker.com/products/docker-desktop/) (or OrbStack / Colima)
- [DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/) v1.23+

### Getting Started

1. Clone the repository to your local machine.
2. From the project root, run:
   ```bash
   ddev start
   ```
   On first run DDEV will build the web/db containers, download WordPress into `./wordpress/` (gitignored), symlink this plugin into `wp-content/plugins/plugin-dev`, create the `wp-config.php`, install WordPress, and register the `product` / `service` custom post types via an mu-plugin. `composer install` also runs automatically.
3. Open the site with `ddev launch` (opens `https://on-demand-revalidation.ddev.site`) and sign in at `/wp-admin` with `admin` / `password`.

To reset WordPress state (drop the DB, recreate the config, and re-run the bootstrap) without rebuilding containers:

```bash
ddev wp-reset
```

### Development Environment

DDEV provides:

- WordPress (latest, installed into `./wordpress/` on first start)
- PHP 8.2 with Xdebug (toggle with `ddev xdebug on` / `ddev xdebug off`)
- MariaDB 10.11
- Node.js 20.x and npm
- WP-CLI (`ddev wp …`)
- Composer (`ddev composer …`)

Useful URLs / commands:

- Site: `https://on-demand-revalidation.ddev.site` (or `ddev launch`)
- Admin: `https://on-demand-revalidation.ddev.site/wp-admin` (`admin` / `password`)
- DB GUI: `ddev describe` prints phpMyAdmin and raw MySQL credentials
- Shell inside web container: `ddev ssh`

### Development Workflow

#### PHP Development

- The plugin lives at the repo root and is symlinked to `wordpress/wp-content/plugins/plugin-dev` inside the container, so edits reflect instantly.
- Run WP-CLI against the dev site with `ddev wp <command>` (e.g., `ddev wp plugin list`).

#### JavaScript/TypeScript Development

The project includes several npm scripts for development:

```bash
# Start the development build with watch mode
npm run start

# Create a production build
npm run build

# Type checking
npm run typecheck
npm run typecheck:watch

# Linting
npm run lint:js
npm run lint:ts
npm run lint:css
npm run lint:php
npm run lint # Run all linters

# Formatting
npm run format:js
npm run format:ts
npm run format:php
npm run format # Run all formatters

# Testing
npm run test:js
npm run test:php
```

#### PHP Composer Commands

```bash
# Install dependencies
composer install

# Run PHP CodeSniffer
composer run phpcs

# Fix coding standards
composer run phpcbf

# Run PHP tests
composer run test
```

### Debugging

Xdebug is installed but disabled by default so it doesn't slow down requests. Turn it on when you need it:

```bash
ddev xdebug on
```

VS Code: use the **Listen for Xdebug (DDEV)** launch config in `.vscode/launch.json` (port 9003, path mapping `/var/www/html` → project root). Set breakpoints, start the listener, hit the site. `ddev xdebug off` when done.

### Custom Post Types & Taxonomies

The development environment registers a small set of fixtures via an mu-plugin generated on first `ddev start` (regenerated by `ddev wp-reset`):

**Post types**

- `product`
- `service`

**Taxonomies** (designed to exercise the plugin's `%taxonomy%` placeholder fan-out)

| Taxonomy           | Attached to          | Hierarchical | Seeded terms                                               |
| ------------------ | -------------------- | ------------ | ---------------------------------------------------------- |
| `product_category` | `product`            | yes          | Electronics, Apparel, Home › Kitchen, Home › Garden        |
| `product_tag`      | `product`            | no           | Featured, Sale, New                                        |
| `service_type`     | `service`            | yes          | Consulting, Support, Training                              |
| `region`           | `product` + `service`| no           | US East, US West, EU                                       |

Edit or remove `wordpress/wp-content/mu-plugins/custom-post-types.php` to change registrations. Terms are only seeded on the initial install — run `ddev wp-reset` to re-seed.

### Unit Testing

The project includes comprehensive testing setups for both PHP and JavaScript/TypeScript code.

#### PHP Testing

The PHP testing framework uses:

- PHPUnit for test execution
- WP_Mock for mocking WordPress functions
- Brain\Monkey for additional mocking capabilities

To run PHP tests:

```bash
# Run all PHP tests
composer run test

# Or using PHPUnit directly
./vendor/bin/phpunit
```

Test files are located in the `tests` directory and should be prefixed with `test_` (e.g., `test_revalidation.php`).

When writing tests:

1. Extend the `PHPUnit\Framework\TestCase` class
2. Use WP_Mock to mock WordPress functions
3. Follow the naming convention: `test_*` for test methods

Example test:

```php
class Test_Example extends PHPUnit\Framework\TestCase {
    public function setUp(): void {
        parent::setUp();
        WP_Mock::setUp();
    }

    public function tearDown(): void {
        WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_example_function() {
        // Mock WordPress functions if needed
        WP_Mock::userFunction('get_option', [
            'args' => ['my_option'],
            'return' => 'option_value',
        ]);

        // Test your code
        $result = my_function();
        $this->assertEquals('expected_value', $result);
    }
}
```

#### JavaScript/TypeScript Testing

JavaScript testing uses the WordPress scripts testing framework, which is built on Jest.

To run JavaScript tests:

```bash
# Run all JavaScript tests
npm run test:js

# Run with watch mode
npm run test:js -- --watch
```

Test files should be placed in the `__tests__` directory or named with `.test.js` or `.test.ts` extensions.

Example test:

```js
describe('MyComponent', () => {
  it('should render correctly', () => {
    // Your test code here
    expect(true).toBe(true);
  });
});
```

## Troubleshooting

- Revalidation on post update is not working: [Next.js](https://github.com/wpengine/faustjs/discussions/842), [WP-Cron](https://github.com/gdidentity/on-demand-revalidation/issues/4#issuecomment-1304602677)
