/**
 * Prepare ./out for upload to a plain file host.
 *
 *   1. Drop the prefetch payloads. Every <Link> sets prefetch={false}, so
 *      nothing requests them; left in place they are more than half the
 *      upload for no benefit.
 *   2. Copy in the Apache config.
 *   3. Copy in the PHP backend — the booking endpoint and the admin — which
 *      Apache runs alongside the static pages. config.php is deliberately not
 *      copied: it holds database credentials and lives only on the server.
 *
 * Run after `npm run build:static`.
 */
import { cpSync, copyFileSync, existsSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const out = join(root, 'out');

if (!existsSync(out)) {
  console.error('No ./out directory — run `npm run build:static` first.');
  process.exit(1);
}

let removed = 0;
let freed = 0;
let kept = 0;
let bytes = 0;

function walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(path);
      continue;
    }
    const size = statSync(path).size;
    // index.txt is fetched during a real navigation and must stay. The
    // __next.* variants are prefetch-only.
    if (entry.name.startsWith('__next.') && entry.name.endsWith('.txt')) {
      unlinkSync(path);
      removed += 1;
      freed += size;
      continue;
    }
    kept += 1;
    bytes += size;
  }
}

walk(out);

// The backend. server/ is copied so its contents land beside the pages:
// api/submit.php, admin/, lib/, make-hash.php.
//
// Three exclusions:
//   - config.php holds the database password. It belongs only on the server,
//     never in a build artifact.
//   - a .htaccess at the TOP of server/ would land on out/.htaccess and
//     replace the site's own, silently dropping every rule in it. Site-wide
//     Apache config belongs in deploy/htaccess. Nested ones are kept: lib/
//     ships its own deny, which is what keeps the includes unreachable.
//   - SETUP.md documents the admin URL and the setup files. It is for whoever
//     installs this, not for visitors.
//
// make-hash.php IS included: setup needs it once, and the admin refuses to
// run until it has been deleted again.
const serverRoot = join(root, 'server');
cpSync(serverRoot, out, {
  recursive: true,
  filter: (src) => {
    const rel = relative(serverRoot, src);
    return !['config.php', '.htaccess', 'SETUP.md'].includes(rel) && !/[\\/]config\.php$/.test(rel);
  },
});

// Last, so nothing copied above can overwrite it.
copyFileSync(join(root, 'deploy', 'htaccess'), join(out, '.htaccess'));

const mb = (n) => `${(n / 1024 / 1024).toFixed(1)}MB`;
console.log(`Removed ${removed} prefetch file(s), freeing ${mb(freed)}.`);
console.log(`Ready to upload: ${kept + 1} files, ${mb(bytes)}.`);
