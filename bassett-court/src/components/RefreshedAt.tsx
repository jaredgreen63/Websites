'use client';

import { useEffect, useState } from 'react';
import { relativeTime } from '@/lib/format';

/**
 * "Inventory refreshed 20 minutes ago", kept honest.
 *
 * The site is a static export, so anything the server renders is frozen at
 * build time. A relative phrase computed there is true for one moment and
 * wrong for the next twenty-four hours — the page claimed "12 seconds ago"
 * all day, which overstates freshness rather than merely looking stale.
 *
 * So the server renders the absolute timestamp, which cannot go stale, and
 * the browser swaps in the relative phrase and keeps it current. Seeding the
 * state with the server's own string means the first client render matches
 * the HTML exactly, so there is no hydration mismatch; the upgrade happens
 * immediately after mount. Without JavaScript the absolute date simply stays,
 * which is still true.
 */
export function RefreshedAt({ iso, absolute }: { iso: string; absolute: string }) {
  const [label, setLabel] = useState(absolute);

  useEffect(() => {
    const tick = () => setLabel(relativeTime(iso));
    tick();
    const id = setInterval(tick, 30_000);
    return () => clearInterval(id);
  }, [iso]);

  return <time dateTime={iso}>{label}</time>;
}
