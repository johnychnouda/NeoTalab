import { NextResponse } from "next/server";

// One canonical host for SEO: www.neotalab.com → neotalab.com (permanent redirect, path and query kept).
export function proxy(request) {
  const host = request.headers.get("host") || "";
  if (host.toLowerCase().startsWith("www.")) {
    const url = new URL(request.url);
    url.host = host.slice(4);
    url.protocol = "https:";
    url.port = "";
    return NextResponse.redirect(url, 308);
  }
  // Whish sends customers back to /pay/... with ?lang= set to their WhatsApp language. They have no
  // language cookie on this site, so the page (layout direction included) is rendered in that language.
  const lang = request.nextUrl.searchParams.get("lang");
  if (request.nextUrl.pathname.startsWith("/pay/") && ["en", "ar", "fr"].includes(lang)) {
    const headers = new Headers(request.headers);
    const others = (request.headers.get("cookie") || "").split(/;\s*/).filter((c) => c && !c.startsWith("nt_lang="));
    headers.set("cookie", [...others, `nt_lang=${lang}`].join("; "));
    return NextResponse.next({ request: { headers } });
  }
  return NextResponse.next();
}

export const config = {
  matcher: "/((?!_next/static|_next/image|favicon.ico).*)",
};
