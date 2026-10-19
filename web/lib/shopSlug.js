// Same rule as backend/app/Support/ShopSlug.php: Arabic letters become Latin letters that keep the vowel letters.
const ARABIC = {
  أ: "a", إ: "i", آ: "a", ا: "a", ب: "b", ت: "t", ث: "th", ج: "j", ح: "h", خ: "kh", د: "d", ذ: "dh", ر: "r", ز: "z",
  س: "s", ش: "sh", ص: "s", ض: "d", ط: "t", ظ: "z", ع: "a", غ: "gh", ف: "f", ق: "q", ك: "k", ل: "l", م: "m", ن: "n",
  ه: "h", و: "w", ي: "y", ى: "a", ة: "a", ء: "", ؤ: "w", ئ: "y",
};

/** The shop's address part (also the start of its login email). */
export function shopSlug(name) {
  const romanized = (name || "")
    .replace(/[ً-ٰٟ]/g, "")
    .split("")
    .map((ch) => ARABIC[ch] ?? ch)
    .join("");
  return romanized
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 40)
    .replace(/-+$/g, "");
}
