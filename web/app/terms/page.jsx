import Link from "next/link";
import { LegalPage, place } from "@/components/public/SiteShell";
import { getSiteLang } from "@/lib/site-lang";
import { company } from "@/lib/company";

export const metadata = {
  title: "Terms of Service — NeoTalab",
  description: "The terms that apply to businesses using NeoTalab for WhatsApp ordering and booking.",
  alternates: { canonical: "/terms" },
};

export default async function TermsPage() {
  const lang = await getSiteLang();
  const p = place(lang);
  if (lang === "ar") return <TermsPageAr p={p} />;
  if (lang === "fr") return <TermsPageFr p={p} />;
  return <TermsPageEn />;
}

function TermsPageEn() {
  return (
    <LegalPage title="Terms of Service">
      <p>
        These terms govern the use of {company.brand}, a service provided by {company.legalName}
        {company.address ? `, ${company.address}` : ""}, {company.country}. By applying for or using {company.brand}, a business
        (&quot;merchant&quot;) agrees to these terms.
      </p>

      <h2>1. The service</h2>
      <p>
        {company.brand} connects a merchant&apos;s WhatsApp business number to an automated assistant that answers customers, takes orders
        and bookings, and coordinates the merchant&apos;s drivers and staff, together with a dashboard to manage the catalog and settings.
      </p>

      <h2>2. Accounts</h2>
      <p>
        Merchants must provide accurate information, keep login credentials secure, and are responsible for everything done with their
        account, number and staff access. We may verify businesses before activating them.
      </p>

      <h2>3. Merchant responsibilities</h2>
      <ul>
        <li>Comply with the WhatsApp Business Terms, WhatsApp Business Messaging Policy and Commerce Policy, and with applicable law.</li>
        <li>Only message customers who contacted the business or agreed to receive messages, and honour opt-out requests.</li>
        <li>Keep products, prices, opening hours, delivery areas and services accurate; the merchant is the seller and is responsible for the goods and services it provides, including their quality, legality and delivery.</li>
        <li>Not sell prohibited goods or services, or use {company.brand} for spam, fraud or misleading messages.</li>
      </ul>

      <h2>4. Automated replies</h2>
      <p>
        The assistant uses AI and can make mistakes. Prices and totals always come from the merchant&apos;s catalog, and the merchant can
        review conversations and take over at any time. Merchants remain responsible for confirming and fulfilling orders.
      </p>

      <h2>5. Payments</h2>
      <p>
        Customers pay the merchant directly — in cash or through the merchant&apos;s own Whish account. {company.brand} is not a party to the
        sale between the merchant and its customers and does not hold customer funds.
      </p>

      <h2>6. Subscription and trial</h2>
      <p>
        New merchants may receive a free trial. After the trial, continued use requires the subscription fee agreed with the merchant,
        payable monthly or yearly. Unpaid accounts may be suspended after reminders. Fees already paid are non-refundable unless required by law.
      </p>

      <h2>7. Availability</h2>
      <p>
        We work to keep {company.brand} available but do not guarantee uninterrupted service. The service depends on third parties such as
        Meta (WhatsApp) and Whish, whose outages or policy decisions are outside our control.
      </p>

      <h2>8. Data protection</h2>
      <p>Our handling of personal data is described in the <Link href="/privacy">Privacy Policy</Link>.</p>

      <h2>9. Liability</h2>
      <p>
        To the extent permitted by law, {company.legalName} is not liable for indirect or consequential losses, lost profits, or losses
        arising from the merchant&apos;s goods, services or deliveries. Our total liability is limited to the fees paid by the merchant in
        the three months before the claim.
      </p>

      <h2>10. Suspension and termination</h2>
      <p>
        Merchants may stop using {company.brand} at any time. We may suspend or end an account that breaches these terms, WhatsApp
        policies or the law, or that does not pay its subscription.
      </p>

      <h2>11. Changes and law</h2>
      <p>
        We may update these terms and will show the latest date above. These terms are governed by the laws of {company.country}.
      </p>

      <h2>12. Contact</h2>
      <p><a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? ` — ${company.phone}` : ""}</p>
    </LegalPage>
  );
}

function TermsPageAr({ p }) {
  const b = company.brand;
  return (
    <LegalPage title="شروط الخدمة">
      <p>
        تحكم هذه الشروط استخدام {b}، وهي خدمة تقدّمها {company.legalName}{p.address ? `، ${p.address}` : ""}، {p.country}. بتقديم طلب
        للانضمام إلى {b} أو باستخدامه، يوافق العمل («التاجر») على هذه الشروط.
      </p>

      <h2>1. الخدمة</h2>
      <p>يربط {b} رقم واتساب التجاري الخاص بالتاجر بمساعد آلي يردّ على الزبائن ويأخذ الطلبات والحجوزات وينسّق مع السائقين والموظفين، إلى جانب لوحة تحكّم لإدارة القائمة والإعدادات.</p>

      <h2>2. الحسابات</h2>
      <p>على التاجر تقديم معلومات صحيحة والحفاظ على سرّية بيانات الدخول، وهو مسؤول عن كل ما يتم عبر حسابه ورقمه وصلاحيات موظفيه. قد نتحقّق من الأعمال قبل تفعيلها.</p>

      <h2>3. مسؤوليات التاجر</h2>
      <ul>
        <li>الالتزام بشروط واتساب للأعمال وسياسة المراسلة التجارية وسياسة التجارة، وبالقوانين المعمول بها.</li>
        <li>مراسلة الزبائن الذين تواصلوا مع العمل أو وافقوا على تلقّي الرسائل فقط، واحترام طلبات إلغاء الاشتراك.</li>
        <li>الحفاظ على دقّة المنتجات والأسعار وساعات العمل ومناطق التوصيل والخدمات؛ فالتاجر هو البائع وهو مسؤول عن السلع والخدمات التي يقدّمها، بما في ذلك جودتها وقانونيتها وتوصيلها.</li>
        <li>عدم بيع سلع أو خدمات محظورة، وعدم استخدام {b} للرسائل المزعجة أو الاحتيال أو الرسائل المضلّلة.</li>
      </ul>

      <h2>4. الردود الآلية</h2>
      <p>يستخدم المساعد الذكاء الاصطناعي وقد يخطئ. تأتي الأسعار والمجاميع دائمًا من قائمة التاجر، ويمكن للتاجر مراجعة المحادثات والتدخّل في أي وقت. يبقى التاجر مسؤولًا عن تأكيد الطلبات وتنفيذها.</p>

      <h2>5. الدفع</h2>
      <p>يدفع الزبائن للتاجر مباشرة — نقدًا أو عبر حساب Whish الخاص بالتاجر. {b} ليس طرفًا في عملية البيع بين التاجر وزبائنه ولا يحتفظ بأموال الزبائن.</p>

      <h2>6. الاشتراك والتجربة</h2>
      <p>قد يحصل التجّار الجدد على تجربة مجانية. بعد انتهائها، يتطلّب الاستمرار دفع رسم الاشتراك المتّفق عليه، شهريًا أو سنويًا. قد يتم تعليق الحسابات غير المدفوعة بعد التذكير. الرسوم المدفوعة غير قابلة للاسترداد إلا إذا فرض القانون ذلك.</p>

      <h2>7. التوفّر</h2>
      <p>نعمل على إبقاء {b} متاحًا لكننا لا نضمن خدمة دون انقطاع. تعتمد الخدمة على أطراف ثالثة مثل Meta (واتساب) وWhish، وأعطالها أو قراراتها خارجة عن سيطرتنا.</p>

      <h2>8. حماية البيانات</h2>
      <p>نصف طريقة تعاملنا مع البيانات الشخصية في <Link href="/privacy">سياسة الخصوصية</Link>.</p>

      <h2>9. المسؤولية</h2>
      <p>في الحدود التي يسمح بها القانون، لا تتحمّل {company.legalName} المسؤولية عن الخسائر غير المباشرة أو التبعية أو الأرباح الفائتة، أو الخسائر الناتجة عن سلع التاجر أو خدماته أو توصيلاته. تقتصر مسؤوليتنا الإجمالية على الرسوم التي دفعها التاجر خلال الأشهر الثلاثة السابقة للمطالبة.</p>

      <h2>10. التعليق والإنهاء</h2>
      <p>يمكن للتاجر التوقّف عن استخدام {b} في أي وقت. ويمكننا تعليق أو إنهاء أي حساب يخالف هذه الشروط أو سياسات واتساب أو القانون، أو لا يدفع اشتراكه.</p>

      <h2>11. التعديلات والقانون</h2>
      <p>قد نحدّث هذه الشروط وسنعرض تاريخ آخر تحديث أعلاه. تخضع هذه الشروط لقوانين {p.country}.</p>

      <h2>12. التواصل</h2>
      <p><a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? <> — <span dir="ltr">{company.phone}</span></> : null}</p>
    </LegalPage>
  );
}

function TermsPageFr({ p }) {
  const b = company.brand;
  return (
    <LegalPage title="Conditions d'utilisation">
      <p>
        Les présentes conditions régissent l&apos;utilisation de {b}, un service fourni par {company.legalName}
        {p.address ? `, ${p.address}` : ""}, {p.country}. En demandant à rejoindre ou en utilisant {b}, un commerce
        (« commerçant ») accepte ces conditions.
      </p>

      <h2>1. Le service</h2>
      <p>{b} connecte le numéro WhatsApp professionnel du commerçant à un assistant automatisé qui répond aux clients, prend les commandes et réservations et coordonne les livreurs et l&apos;équipe du commerçant, avec un tableau de bord pour gérer le catalogue et les réglages.</p>

      <h2>2. Comptes</h2>
      <p>Le commerçant doit fournir des informations exactes, protéger ses identifiants et reste responsable de tout ce qui est fait avec son compte, son numéro et les accès de son équipe. Nous pouvons vérifier les commerces avant leur activation.</p>

      <h2>3. Responsabilités du commerçant</h2>
      <ul>
        <li>Respecter les Conditions de WhatsApp Business, la Politique de messagerie WhatsApp Business et la Politique commerciale, ainsi que la loi applicable.</li>
        <li>N&apos;écrire qu&apos;aux clients qui ont contacté le commerce ou accepté de recevoir des messages, et respecter les demandes de désinscription.</li>
        <li>Tenir à jour produits, prix, horaires, zones de livraison et services ; le commerçant est le vendeur et répond des biens et services qu&apos;il fournit, y compris leur qualité, leur légalité et leur livraison.</li>
        <li>Ne pas vendre de biens ou services interdits ni utiliser {b} pour du spam, de la fraude ou des messages trompeurs.</li>
      </ul>

      <h2>4. Réponses automatisées</h2>
      <p>L&apos;assistant utilise l&apos;IA et peut se tromper. Les prix et totaux proviennent toujours du catalogue du commerçant, qui peut relire les conversations et reprendre la main à tout moment. Le commerçant reste responsable de la confirmation et de l&apos;exécution des commandes.</p>

      <h2>5. Paiements</h2>
      <p>Les clients paient directement le commerçant — en espèces ou via le compte Whish du commerçant. {b} n&apos;est pas partie à la vente entre le commerçant et ses clients et ne détient pas les fonds des clients.</p>

      <h2>6. Abonnement et essai</h2>
      <p>Les nouveaux commerçants peuvent bénéficier d&apos;un essai gratuit. Ensuite, l&apos;utilisation continue nécessite l&apos;abonnement convenu, payable mensuellement ou annuellement. Les comptes impayés peuvent être suspendus après rappels. Les sommes déjà payées ne sont pas remboursables, sauf obligation légale.</p>

      <h2>7. Disponibilité</h2>
      <p>Nous faisons en sorte que {b} reste disponible sans garantir un service ininterrompu. Le service dépend de tiers comme Meta (WhatsApp) et Whish, dont les pannes ou décisions échappent à notre contrôle.</p>

      <h2>8. Protection des données</h2>
      <p>Notre traitement des données personnelles est décrit dans la <Link href="/privacy">Politique de confidentialité</Link>.</p>

      <h2>9. Responsabilité</h2>
      <p>Dans la mesure permise par la loi, {company.legalName} n&apos;est pas responsable des pertes indirectes ou consécutives, des pertes de profits, ni des pertes liées aux biens, services ou livraisons du commerçant. Notre responsabilité totale est limitée aux frais payés par le commerçant au cours des trois mois précédant la réclamation.</p>

      <h2>10. Suspension et résiliation</h2>
      <p>Le commerçant peut cesser d&apos;utiliser {b} à tout moment. Nous pouvons suspendre ou fermer un compte qui enfreint ces conditions, les règles de WhatsApp ou la loi, ou qui ne paie pas son abonnement.</p>

      <h2>11. Modifications et droit applicable</h2>
      <p>Nous pouvons mettre à jour ces conditions et afficherons la date ci-dessus. Ces conditions sont régies par le droit du {p.country}.</p>

      <h2>12. Contact</h2>
      <p><a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? <> — <span dir="ltr">{company.phone}</span></> : null}</p>
    </LegalPage>
  );
}
