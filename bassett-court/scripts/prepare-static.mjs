/**
 * Prepare ./out for upload to a plain file host.
 *
 *   1. Drop the prefetch payloads. Every <Link> sets prefetch={false}, so
 *      nothing requests them; left in place they are more than half the
 *      upload for no benefit.
 *   2. Copy in the Apache config.
 *
 * Run after `npm run build:static`.
 */
import { copyFileSync, existsSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { join, resolve } from 'node:path';

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
copyFileSync(join(root, 'deploy', 'htaccess'), join(out, '.htaccess'));

const mb = (n) => `${(n / 1024 / 1024).toFixed(1)}MB`;
console.log(`Removed ${removed} prefetch file(s), freeing ${mb(freed)}.`);
console.log(`Ready to upload: ${kept + 1} files, ${mb(bytes)}.`);
