import "./globals.css";
import { Inter } from "next/font/google";
import { ToastProvider } from "@/components/ui";
import { LangProvider } from "@/lib/i18n";
import { company } from "@/lib/company";
import { getSiteLang } from "@/lib/site-lang";

const inter = Inter({ subsets: ["latin"], display: "swap", variable: "--font-inter" });

export const metadata = {
  metadataBase: new URL(company.siteUrl),
  title: "NeoTalab",
  description: "AI WhatsApp ordering and booking for local businesses",
  openGraph: { siteName: "NeoTalab", type: "website", images: ["/Logo1.png"] },
  ...(company.googleSiteVerification ? { verification: { google: company.googleSiteVerification } } : {}),
  // Meta Business Settings → Brand safety → Domains → meta-tag verification.
  ...(company.facebookDomainVerification ? { other: { "facebook-domain-verification": company.facebookDomainVerification } } : {}),
};

export const viewport = {
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
  themeColor: "#0b1410",
};

export default async function RootLayout({ children }) {
  const lang = await getSiteLang();
  return (
    <html lang={lang} dir={lang === "ar" ? "rtl" : "ltr"} className={inter.variable}>
      <body>
        <LangProvider>
          <ToastProvider>{children}</ToastProvider>
        </LangProvider>
      </body>
    </html>
  );
}
