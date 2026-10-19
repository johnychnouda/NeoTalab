import { shopSlug } from "@/lib/shopSlug";

/** Derive merchant backoffice login email from shop name (must match backend rules). */
export function merchantLoginEmail(shopName) {
  const slug = shopSlug(shopName);
  return slug ? `${slug}@merchant.neotalab` : "";
}
