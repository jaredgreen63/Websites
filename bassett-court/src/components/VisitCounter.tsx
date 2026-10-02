'use client';

import { useEffect, useState } from 'react';

/**
 * The visit count, in a slim bar above the header.
 *
 * It sits in its own row rather than inside the header: that row is already
 * full at tablet widths, and a number that grows a digit should never be what
 * pushes the navigation off the screen.
 *
 * The site is static HTML, so the number has to come from the server after the
 * page loads. Until it arrives — or if it never does, because the endpoint is
 * missing or the database is down — the bar renders nothing at all. A counter
 * is decoration; a broken counter is worse than none.
 */
export function VisitCounter() {
  const [visits, setVisits] = useState<number | null>(null);

  useEffect(() => {
    const controller = new AbortController();

    fetch('/api/visits.php', {
      method: 'POST',
      signal: controller.signal,
      headers: { 'Content-Type': 'application/json' },
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((data) => {
        if (data?.ok && typeof data.visits === 'number') {
          setVisits(data.visits);
        }
      })
      .catch(() => {
        // Aborted, offline, or no backend. Stay silent.
      });

    return () => controller.abort();
  }, []);

  if (visits === null) {
    return null;
  }

  return (
    <div
      className="w-full border-b text-center"
      style={{ borderColor: 'var(--border-subtle)', backgroundColor: 'var(--surface-sunken)' }}
    >
      <p
        className="mx-auto max-w-7xl px-4 py-1.5 text-[0.6875rem] font-medium tracking-wide sm:px-6 lg:px-8"
        style={{ color: 'var(--text-muted)' }}
      >
        <span style={{ color: 'var(--accent)' }} className="font-bold tabular-nums">
          {visits.toLocaleString('en-US')}
        </span>{' '}
        visits
      </p>
    </div>
  );
}
