import { company } from "@/lib/company";

export default function robots() {
  return {
    rules: [{ userAgent: "*", allow: "/", disallow: ["/owner", "/backoffice", "/pay"] }],
    sitemap: `${company.siteUrl}/sitemap.xml`,
    host: company.siteUrl,
  };
}
