import Link from "next/link";
import Brand from "@/components/Brand";
import LangSwitch from "@/components/public/LangSwitch";
import { getSiteLang, pageMetadata } from "@/lib/site-lang";

export function generateMetadata() {
  return pageMetadata("join", {
    description: "Apply to join NeoTalab and automate WhatsApp orders for your business.",
  });
}

/** The join form with the site's logo (back to the home page) and language switch. */
export default async function JoinLayout({ children }) {
  const lang = await getSiteLang();

  return (
    <>
      <header className="join-top" dir="ltr">
        <Link href="/" aria-label="NeoTalab" style={{ textDecoration: "none" }}><Brand size={30} /></Link>
        <LangSwitch current={lang} />
      </header>
      {children}
    </>
  );
}
