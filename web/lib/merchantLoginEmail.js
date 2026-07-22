/** Derive merchant backoffice login email from shop name (must match backend slug rules). */
export function merchantLoginEmail(shopName) {
  const slug = (shopName || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "")
    .slice(0, 40);

  return slug ? `${slug}@merchant.neotalab` : "";
}
