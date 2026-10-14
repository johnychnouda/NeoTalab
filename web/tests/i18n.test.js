import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { I18N } from "@/lib/i18n";
import { SITE } from "@/lib/site-i18n";

const placeholders = (s) => [...String(s).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();

function sameKeysAndPlaceholders(dict) {
  const en = dict.en;
  for (const lang of ["ar", "fr"]) {
    expect(Object.keys(dict[lang]).filter((k) => !(k in en)), `${lang} has keys English doesn't`).toEqual([]);
    // "_one" keys are optional singular forms; without one, t() uses the normal text.
    expect(Object.keys(en).filter((k) => !k.endsWith("_one") && !(k in dict[lang])), `${lang} is missing keys`).toEqual([]);
    for (const key of Object.keys(en)) {
      if (typeof en[key] !== "string" || !(key in dict[lang])) continue;
      expect(placeholders(dict[lang][key]), `${lang} ${key}`).toEqual(placeholders(en[key]));
    }
  }
}

function sourceFiles(dir) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return /\.(jsx?|mjs)$/.test(name) ? [path] : [];
  });
}

describe("translations", () => {
  it("dashboard text exists in English, Arabic and French", () => sameKeysAndPlaceholders(I18N));

  it("website text exists in English, Arabic and French", () => sameKeysAndPlaceholders(SITE));

  it("every t(\"key\") used on a page has English text", () => {
    const root = join(import.meta.dirname, "..");
    const missing = [];
    for (const file of [...sourceFiles(join(root, "app")), ...sourceFiles(join(root, "components"))]) {
      const src = readFileSync(file, "utf8");
      for (const [, key] of src.matchAll(/\bt\(\s*["']([\w.-]+)["']/g)) {
        if (!(key in I18N.en)) missing.push(`${key} (${file.slice(root.length + 1)})`);
      }
    }
    expect(missing).toEqual([]);
  });
});
