import { company } from "@/lib/company";

export default function sitemap() {
  const pages = [
    ["", 1.0, "weekly"],
    ["/join", 0.9, "monthly"],
    ["/contact", 0.6, "yearly"],
    ["/privacy", 0.4, "yearly"],
    ["/terms", 0.4, "yearly"],
    ["/data-deletion", 0.3, "yearly"],
  ];

  return pages.map(([path, priority, changeFrequency]) => ({
    url: `${company.siteUrl}${path}`,
    lastModified: new Date("2026-10-05"),
    changeFrequency,
    priority,
  }));
}
