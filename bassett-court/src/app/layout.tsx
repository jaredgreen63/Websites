import type { Metadata, Viewport } from 'next';
import localFont from 'next/font/local';
import './globals.css';

import { SiteHeader } from '@/components/SiteHeader';
import { VisitCounter } from '@/components/VisitCounter';
import { SiteFooter } from '@/components/SiteFooter';
import { Logo } from '@/components/Logo';
import { themeScript } from '@/components/ThemeToggle';
import { getSnapshot } from '@/lib/inventory';
import { siteConfig } from '~/site.config';

/*
 * The fonts ship with the repository rather than being fetched from Google at
 * build time.
 *
 * next/font/google downloads the files during the build, which made every
 * deploy depend on Google answering. On 3 October it did not, and the build
 * died — after the sync had already committed fresh inventory. The data moved,
 * the site did not, and nothing said so.
 *
 * These are the same latin variable files next/font/google was fetching, byte
 * for byte. Characters outside the latin range fall back per-glyph to the
 * system stack, which is what the browser does anyway.
 */
const inter = localFont({
  src: './fonts/inter-latin-var.woff2',
  weight: '100 900',
  variable: '--font-inter',
  display: 'swap',
});

// Headings want weight and tight tracking to hold up on a dark page; a light
// high-contrast serif does neither.
const display = localFont({
  src: './fonts/plus-jakarta-sans-latin-var.woff2',
  weight: '200 800',
  variable: '--font-display',
  display: 'swap',
});

export const metadata: Metadata = {
  metadataBase: new URL(siteConfig.url),
  title: {
    default: `${siteConfig.name} — ${siteConfig.tagline}`,
    template: `%s · ${siteConfig.name}`,
  },
  description: siteConfig.description,
  openGraph: {
    type: 'website',
    siteName: siteConfig.name,
    title: `${siteConfig.name} — ${siteConfig.tagline}`,
    description: siteConfig.description,
    url: siteConfig.url,
  },
  twitter: { card: 'summary_large_image' },
  robots: { index: true, follow: true },
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#0a0e14',
};

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const snapshot = await getSnapshot();

  return (
    <html lang="en" suppressHydrationWarning className={`${inter.variable} ${display.variable}`}>
      <head>
        {/* Runs before paint so a stored theme preference never flashes. */}
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body className="flex min-h-screen flex-col">
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[100] focus:rounded-lg focus:px-4 focus:py-2"
          style={{ backgroundColor: 'var(--accent)', color: 'var(--accent-contrast)' }}
        >
          Skip to content
        </a>

        {/* Above the sticky header, so it scrolls away and never competes
            with the navigation for room. */}
        <VisitCounter />

        {/* The logo is resolved on the server (it checks the filesystem), then
            handed to the client header as a prop. */}
        <SiteHeader logo={<Logo />} />

        <main id="main" className="flex-1">
          {children}
        </main>

        <SiteFooter syncedAt={snapshot.generatedAt} vehicleCount={snapshot.vehicleCount} />
      </body>
    </html>
  );
}
