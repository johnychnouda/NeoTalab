import { LegalPage } from "@/components/public/SiteShell";
import { company } from "@/lib/company";
import { getSiteLang, pageMetadata } from "@/lib/site-lang";

export function generateMetadata() {
  return pageMetadata("deletion", {
    description: "How customers and businesses can ask NeoTalab to delete their personal data.",
    alternates: { canonical: "/data-deletion" },
  });
}

// Meta App Dashboard → App settings → Basic → "User data deletion" → Data deletion instructions URL.
export default async function DataDeletionPage() {
  const lang = await getSiteLang();
  const b = company.brand;
  const wa = company.whatsapp ? company.whatsapp.replace(/\D/g, "") : "";
  const mail = (subject) => <a href={`mailto:${company.email}?subject=${encodeURIComponent(subject)}`}>{company.email}</a>;
  const waLink = <a href={`https://wa.me/${wa}`} dir="ltr">{company.whatsapp}</a>;

  if (lang === "ar") {
    return (
      <LegalPage title="تعليمات حذف البيانات">
        <p>يمكنك أن تطلب من {b} حذف بياناتك الشخصية التي نحتفظ بها في أي وقت. ننفّذ طلبات الحذف خلال 30 يومًا ونؤكّد ذلك عبر البريد الإلكتروني أو واتساب.</p>

        <h2>إذا كنت زبونًا لدى عمل يستخدم {b}</h2>
        <ol>
          <li>أرسل بريدًا إلكترونيًا إلى {mail("Delete my data")}{wa ? <> أو رسالة واتساب إلى {waLink}</> : null} بعنوان «احذف بياناتي».</li>
          <li>اذكر رقم واتساب الذي استخدمته واسم العمل الذي طلبت منه.</li>
          <li>قد نطلب منك تأكيد الطلب من رقم واتساب هذا للتأكد من أنه رقمك.</li>
        </ol>
        <p>بعد ذلك نحذف محادثاتك وملفك كزبون ومواقعك المحفوظة وتقييماتك لدى هذا العمل، ونُخفي هويتك في طلباتك السابقة بحيث يحتفظ العمل فقط بالمجاميع المحاسبية التي يُلزمه القانون بالاحتفاظ بها.</p>

        <h2>إذا كنت صاحب عمل (تاجر)</h2>
        <p>أرسل بريدًا إلكترونيًا إلى {mail("Close my account")} من البريد المسجّل في حسابك لإغلاقه. نفصل رقم واتساب الخاص بك ونحذف حسابك وقائمتك وزبائنك وطلباتك وحجوزاتك خلال 90 يومًا، باستثناء السجلات التي يُلزمنا القانون بالاحتفاظ بها.</p>

        <h2>تسجيل الدخول عبر فيسبوك / Meta</h2>
        <p>إذا ربطت حساب واتساب للأعمال عبر تسجيل الدخول بفيسبوك، يمكنك أيضًا إزالة {b} من إعدادات فيسبوك ضمن <em>الإعدادات والخصوصية ← الإعدادات ← عمليات تكامل الأعمال</em>. هذا يوقف وصولنا؛ ولحذف البيانات التي نحتفظ بها، أرسل طلبًا كما هو موضّح أعلاه.</p>
      </LegalPage>
    );
  }

  if (lang === "fr") {
    return (
      <LegalPage title="Instructions de suppression des données">
        <p>Vous pouvez à tout moment demander à {b} de supprimer les données personnelles que nous détenons à votre sujet. Nous traitons les demandes sous 30 jours et confirmons par e-mail ou WhatsApp.</p>

        <h2>Si vous êtes client d&apos;un commerce qui utilise {b}</h2>
        <ol>
          <li>Envoyez un e-mail à {mail("Delete my data")}{wa ? <> ou un message WhatsApp au {waLink}</> : null} avec l&apos;objet « Supprimer mes données ».</li>
          <li>Indiquez le numéro WhatsApp utilisé et le nom du commerce auprès duquel vous avez commandé.</li>
          <li>Nous pourrons vous demander de confirmer depuis ce numéro WhatsApp pour vérifier qu&apos;il vous appartient.</li>
        </ol>
        <p>Nous supprimons alors vos conversations, votre profil client, vos adresses enregistrées et vos évaluations pour ce commerce, et anonymisons vos commandes passées afin que le commerce ne conserve que les totaux comptables qu&apos;il est légalement tenu de garder.</p>

        <h2>Si vous êtes un commerce (commerçant)</h2>
        <p>Écrivez à {mail("Close my account")} depuis l&apos;adresse e-mail de votre compte pour le fermer. Nous déconnectons votre numéro WhatsApp et supprimons votre compte, catalogue, clients, commandes et réservations sous 90 jours, sauf les données que la loi nous oblige à conserver.</p>

        <h2>Connexion Facebook / Meta</h2>
        <p>Si vous avez connecté votre compte WhatsApp Business via Facebook Login, vous pouvez aussi retirer {b} dans vos paramètres Facebook sous <em>Paramètres et confidentialité → Paramètres → Intégrations professionnelles</em>. Cela arrête notre accès ; pour supprimer les données déjà détenues, envoyez une demande comme indiqué ci-dessus.</p>
      </LegalPage>
    );
  }

  return (
    <LegalPage title="Data Deletion Instructions">
      <p>
        You can ask {b} to delete the personal data we hold about you at any time. We complete deletion requests within
        30 days and confirm by email or WhatsApp.
      </p>

      <h2>If you are a customer of a business that uses {b}</h2>
      <ol>
        <li>
          Send an email to {mail("Delete my data")}
          {wa ? <> or a WhatsApp message to {waLink}</> : null} with the subject
          {" "}&quot;Delete my data&quot;.
        </li>
        <li>Include the WhatsApp phone number you used and the name of the business you ordered from.</li>
        <li>We may ask you to confirm the request from that WhatsApp number to make sure it is yours.</li>
      </ol>
      <p>
        We then delete your conversations, customer profile, saved locations and ratings for that business, and anonymise your past orders
        so the business keeps only the accounting totals it is legally required to keep.
      </p>

      <h2>If you are a business (merchant)</h2>
      <p>
        Email {mail("Close my account")} from your account email to close your
        account. We disconnect your WhatsApp number and delete your account, catalog, customers, orders and bookings within 90 days,
        except records we must keep by law.
      </p>

      <h2>Facebook / Meta login</h2>
      <p>
        If you connected your WhatsApp Business account through Facebook Login, you can also remove {b} from your Facebook
        settings under <em>Settings &amp; privacy → Settings → Business integrations</em>. This stops our access; to delete data we already
        hold, send a request as described above.
      </p>
    </LegalPage>
  );
}
