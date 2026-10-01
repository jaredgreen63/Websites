import type { MetadataRoute } from 'next';

// Generated once at build time so it works on a static host.
export const dynamic = 'force-static';
import { siteConfig } from '~/site.config';

export default function robots(): MetadataRoute.Robots {
  const base = siteConfig.url.replace(/\/$/, '');
  return {
    rules: [{ userAgent: '*', allow: '/' }],
    sitemap: `${base}/sitemap.xml`,
  };
}
