"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useMerchant } from "./layout";

/** /backoffice → /backoffice/{shopSlug} once authenticated. */
export default function BackofficeIndexPage() {
  const router = useRouter();
  const { shopSlug } = useMerchant();

  useEffect(() => {
    if (shopSlug) {
      router.replace(`/backoffice/${shopSlug}`);
    }
  }, [shopSlug, router]);

  return <div className="loader">Loading…</div>;
}
