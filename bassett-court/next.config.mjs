/**
 * Set STATIC_EXPORT=1 to emit plain HTML into ./out for a host that only
 * serves files — Apache, nginx, cPanel. Without it the app builds as a normal
 * Next.js server app, so both deployment targets stay available from one
 * codebase.
 */
const staticExport = process.env.STATIC_EXPORT === '1';

/** @type {import('next').NextConfig} */
const nextConfig = {
  ...(staticExport
    ? {
        output: 'export',
        // Emits /inventory/slug/index.html, which Apache serves at
        // /inventory/slug/ with no rewrite rules at all.
        trailingSlash: true,
      }
    : {}),
  // Vehicle photography is hot-linked from the upstream inventory source, so the
  // hostname set is not known ahead of time. `unoptimized` keeps <img> semantics
  // simple and avoids a build-time allowlist that breaks whenever the source
  // changes CDNs.
  images: { unoptimized: true },



  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
        ],
      },
    ];
  },
};

export default nextConfig;
