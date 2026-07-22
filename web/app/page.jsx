import Link from "next/link";

export default function Home() {
  return (
    <div className="home-wrap">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img src="/Logo1.png" alt="NeoTalab" className="home-logo" />
      <div className="home-cards">
        <Link href="/owner" className="home-card">
          <h3>Owner Portal</h3>
          <p>Platform management — merchants, billing, analytics and settings.</p>
        </Link>
        <Link href="/backoffice" className="home-card">
          <h3>Merchant Backoffice</h3>
          <p>Manage your shop — orders, menu, drivers, customers and settings.</p>
        </Link>
        <Link href="/join" className="home-card">
          <h3>Join NeoTalab</h3>
          <p>Apply for a 7-day free trial — automate WhatsApp orders for your business.</p>
        </Link>
      </div>
    </div>
  );
}
