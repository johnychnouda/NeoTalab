import { ResetPasswordForm } from "@/components/PasswordReset";
import { pageMetadata } from "@/lib/site-lang";

export function generateMetadata() {
  return pageMetadata("reset", { robots: { index: false, follow: false } });
}

export default function ResetPasswordPage() {
  return <ResetPasswordForm />;
}
