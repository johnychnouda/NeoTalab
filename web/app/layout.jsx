import "./globals.css";
import { Inter } from "next/font/google";
import { ToastProvider } from "@/components/ui";
import { LangProvider } from "@/lib/i18n";

const inter = Inter({ subsets: ["latin"], display: "swap", variable: "--font-inter" });

export const metadata = {
  title: "NeoTalab",
  description: "AI WhatsApp Commerce OS",
  icons: { icon: "/Logo.png" },
};

export default function RootLayout({ children }) {
  return (
    <html lang="en" className={inter.variable}>
      <body>
        <LangProvider>
          <ToastProvider>{children}</ToastProvider>
        </LangProvider>
      </body>
    </html>
  );
}
