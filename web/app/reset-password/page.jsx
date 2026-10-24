import { ResetPasswordForm } from "@/components/PasswordReset";

export const metadata = { title: "Choose a new password · NeoTalab", robots: { index: false, follow: false } };

export default function ResetPasswordPage() {
  return <ResetPasswordForm />;
}
