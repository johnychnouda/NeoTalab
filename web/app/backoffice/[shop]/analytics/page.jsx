import { redirect } from "next/navigation";

// The shop dashboard has no reports page. An old link or bookmark lands on the shop's home.
export default async function Reports({ params }) {
  const { shop } = await params;
  redirect(`/backoffice/${shop}`);
}
