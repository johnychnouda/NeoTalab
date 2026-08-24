import Link from "next/link";

export const metadata = {
  title: "Terms of Service — NeoTalab",
};

export default function TermsPage() {
  return (
    <div className="login-wrap join-page">
      <div className="login-card join-form-card" style={{ maxWidth: 640 }}>
        <h2 style={{ fontSize: 22, fontWeight: 800, marginBottom: 12 }}>Terms of Service</h2>
        <p style={{ color: "var(--text-muted)", fontSize: 13, lineHeight: 1.65, marginBottom: 16 }}>
          By applying to NeoTalab, merchants agree to use the platform for lawful WhatsApp commerce,
          pay applicable subscription fees after any trial period, and keep account credentials secure.
          The platform operator may suspend accounts that violate these terms or applicable law.
        </p>
        <Link href="/join" className="btn-sm" style={{ display: "inline-flex" }}>← Back to Join</Link>
      </div>
    </div>
  );
}
