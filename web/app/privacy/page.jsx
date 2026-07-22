import Link from "next/link";

export const metadata = {
  title: "Privacy Policy — NeoTalab",
};

export default function PrivacyPage() {
  return (
    <div className="login-wrap join-page">
      <div className="login-card join-form-card" style={{ maxWidth: 640 }}>
        <h2 style={{ fontSize: 22, fontWeight: 800, marginBottom: 12 }}>Privacy Policy</h2>
        <p style={{ color: "var(--text-muted)", fontSize: 13, lineHeight: 1.65, marginBottom: 16 }}>
          NeoTalab collects shop and contact details submitted on the join form to review applications
          and operate your merchant account. We do not sell your data. WhatsApp numbers are used for
          onboarding communication and platform operations. Full privacy policy will be published here before public launch.
        </p>
        <Link href="/join" className="btn-sm" style={{ display: "inline-flex" }}>← Back to Join</Link>
      </div>
    </div>
  );
}
