"use client";

import { useRouter } from "next/navigation";
import { useLang } from "@/lib/i18n";

const LANGS = [["en", "EN"], ["ar", "عربي"], ["fr", "FR"]];

/** Site-wide language: saved in a cookie (server pages) and localStorage (dashboards). */
export default function LangSwitch({ current }) {
  const router = useRouter();
  const { setLang } = useLang();

  return (
    <div className="site-lang" role="group" aria-label="Language">
      {LANGS.map(([code, label]) => (
        <button
          key={code}
          type="button"
          className={code === current ? "active" : ""}
          aria-pressed={code === current}
          onClick={() => { setLang(code); router.refresh(); }}
        >
          {label}
        </button>
      ))}
    </div>
  );
}
