"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

/** Legacy route — merged into Inbox → History */
export default function ActivityRedirect() {
  const router = useRouter();
  useEffect(() => { router.replace("/owner/inbox?tab=history"); }, [router]);
  return null;
}
