/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false, // no "X-Powered-By: Next.js" label
  outputFileTracingRoot: new URL(".", import.meta.url).pathname,
  // Always HTTPS: browsers that once visited http://neotalab.com otherwise keep showing "Not secure".
  async headers() {
    return [{
      source: "/:path*",
      headers: [
        { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
        // No other site may show NeoTalab inside a frame (stops click-jacking on the dashboards).
        { key: "X-Frame-Options", value: "DENY" },
        { key: "Content-Security-Policy", value: "frame-ancestors 'none'" },
        { key: "X-Content-Type-Options", value: "nosniff" },
        { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
      ],
    }];
  },
};

export default nextConfig;
