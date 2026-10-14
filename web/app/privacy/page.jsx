import Link from "next/link";
import { LegalPage, place } from "@/components/public/SiteShell";
import { getSiteLang } from "@/lib/site-lang";
import { company } from "@/lib/company";

export const metadata = {
  title: "Privacy Policy — NeoTalab",
  description: "How NeoTalab collects, uses, shares and deletes personal data when businesses use WhatsApp ordering and booking.",
  alternates: { canonical: "/privacy" },
};

export default async function PrivacyPage() {
  const lang = await getSiteLang();
  const p = place(lang);
  if (lang === "ar") return <PrivacyPageAr p={p} />;
  if (lang === "fr") return <PrivacyPageFr p={p} />;
  return <PrivacyPageEn />;
}

function PrivacyPageEn() {
  const who = company.address ? `${company.legalName}, ${company.address}, ${company.country}` : `${company.legalName}, ${company.country}`;

  return (
    <LegalPage title="Privacy Policy">
      <p>
        This policy explains how {company.brand} (&quot;we&quot;, &quot;us&quot;), operated by {who}, handles personal data. {company.brand} is
        a software platform that lets businesses (&quot;merchants&quot;) take orders and bookings from their customers on WhatsApp using the
        WhatsApp Business Platform provided by Meta.
      </p>

      <h2>1. Who is responsible</h2>
      <p>
        For data about merchants and their staff, {company.legalName} is the controller. For data about a merchant&apos;s customers, the
        merchant is the controller and {company.brand} processes that data on the merchant&apos;s behalf to provide the service. Contact:
        {" "}<a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? `, ${company.phone}` : ""}.
      </p>

      <h2>2. Data we collect</h2>
      <ul>
        <li><strong>Merchant account data:</strong> business name, contact name, email, phone/WhatsApp numbers, address, business type, login credentials (stored hashed), subscription and billing records.</li>
        <li><strong>Customer data received through WhatsApp:</strong> phone number, WhatsApp profile name, the messages exchanged with the merchant&apos;s number, order and booking details, delivery address or shared location, preferred language, payment method and status, and ratings.</li>
        <li><strong>Driver and staff data:</strong> name, WhatsApp number, shift times, deliveries completed and cash collected.</li>
        <li><strong>Technical data:</strong> log files and IP addresses needed to run and secure the service.</li>
      </ul>
      <p>We do not collect card numbers: online payments are processed by Whish on its own pages.</p>

      <h2>3. How we use it</h2>
      <ul>
        <li>To answer customers, take orders and bookings, calculate totals and send order updates on the merchant&apos;s behalf;</li>
        <li>to dispatch orders to the merchant&apos;s drivers and staff;</li>
        <li>to provide merchants with reports and analytics about their own business;</li>
        <li>to operate, secure, support and bill the service; and to comply with legal obligations.</li>
      </ul>
      <p>We do not sell personal data and we do not use customers&apos; data for our own advertising.</p>

      <h2>4. Automated assistant (AI)</h2>
      <p>
        Customer messages and the merchant&apos;s catalog are processed by an AI language model to understand requests and draft replies.
        The assistant cannot set prices or fees: totals are always calculated from the merchant&apos;s own catalog. Customers can ask to
        talk to a person at any time, and merchants can take over any conversation.
      </p>

      <h2>5. Service providers we share data with</h2>
      <ul>
        <li><strong>Meta Platforms (WhatsApp Business Platform)</strong> — to send and receive WhatsApp messages.</li>
        <li><strong>OpenAI</strong> — AI processing of conversation text (not used to train their models under API terms).</li>
        <li><strong>Whish Money</strong> — online payments, when the merchant offers them.</li>
        <li><strong>Railway</strong> — cloud hosting of our servers and database.</li>
        <li><strong>Cloudflare</strong> — storage of encrypted database backups.</li>
        <li><strong>Google</strong> — website analytics, only for visitors who accept cookies.</li>
      </ul>
      <p>Each provider processes data only to provide its service to us. Data may be processed outside {company.country}; we rely on the providers&apos; contractual safeguards.</p>

      <h2>6. How long we keep data</h2>
      <ul>
        <li>Conversation history used by the assistant is limited to the most recent messages of each chat.</li>
        <li>Orders, bookings and customer records are kept while the merchant uses {company.brand} so the merchant can serve and support its customers, and deleted within 90 days after the merchant&apos;s account is closed.</li>
        <li>Login sessions expire after 30 days. Server logs are kept for a limited period for security.</li>
      </ul>

      <h2 id="analytics">Website analytics and cookies</h2>
      <p>
        Our public website uses Google Analytics only if you click &quot;Accept&quot; in the cookie banner. It then sets cookies to
        measure visits (pages viewed, approximate location, device type) with IP anonymisation. If you decline, no analytics
        cookies are set. Your choice is stored in your browser; clear your browser storage to change it. The merchant and owner
        dashboards use no analytics cookies.
      </p>

      <h2>7. Your rights</h2>
      <p>
        You can ask to access, correct or delete your personal data, or object to its processing. Customers can contact the business they
        ordered from or us directly. See <Link href="/data-deletion">how to delete your data</Link>.
      </p>

      <h2>8. Security</h2>
      <p>
        Data is transmitted over HTTPS, access is restricted by role, each business can only see its own data, passwords and payment
        credentials are stored hashed or encrypted, and WhatsApp webhooks are verified with Meta&apos;s signature.
      </p>

      <h2>9. Children</h2>
      <p>{company.brand} is a business tool and is not directed to children under 13.</p>

      <h2>10. Changes</h2>
      <p>We will update this page when our practices change and show the date of the latest version above.</p>

      <h2>11. Contact</h2>
      <p>
        {company.legalName}{company.address ? `, ${company.address}` : ""}, {company.country} — <a href={`mailto:${company.email}`}>{company.email}</a>
        {company.phone ? ` — ${company.phone}` : ""}.
      </p>
    </LegalPage>
  );
}

function PrivacyPageAr({ p }) {
  const b = company.brand;
  const who = `${company.legalName}${p.address ? `، ${p.address}` : ""}، ${p.country}`;
  return (
    <LegalPage title="سياسة الخصوصية">
      <p>توضّح هذه السياسة كيف يتعامل {b} («نحن»)، الذي تشغّله {who}، مع البيانات الشخصية. {b} منصّة برمجية تتيح للأعمال («التجّار») تلقّي الطلبات والحجوزات من زبائنهم عبر واتساب باستخدام منصّة واتساب للأعمال المقدَّمة من Meta.</p>

      <h2>1. الجهة المسؤولة</h2>
      <p>بالنسبة لبيانات التجّار وموظفيهم، {company.legalName} هي الجهة المتحكّمة بالبيانات. أما بيانات زبائن التاجر، فالتاجر هو المتحكّم بها ويعالجها {b} نيابةً عنه لتقديم الخدمة. للتواصل: <a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? <>، <span dir="ltr">{company.phone}</span></> : null}.</p>

      <h2>2. البيانات التي نجمعها</h2>
      <ul>
        <li><strong>بيانات حساب التاجر:</strong> اسم العمل، اسم المسؤول، البريد الإلكتروني، أرقام الهاتف/واتساب، العنوان، نوع العمل، بيانات الدخول (مخزّنة بشكل مُشفّر أحادي الاتجاه)، وسجلات الاشتراك والفوترة.</li>
        <li><strong>بيانات الزبائن الواردة عبر واتساب:</strong> رقم الهاتف، اسم ملف واتساب، الرسائل المتبادلة مع رقم التاجر، تفاصيل الطلبات والحجوزات، عنوان التوصيل أو الموقع المُرسل، اللغة المفضّلة، طريقة الدفع وحالته، والتقييمات.</li>
        <li><strong>بيانات السائقين والموظفين:</strong> الاسم، رقم واتساب، أوقات الدوام، التوصيلات المنجزة والنقد المحصّل.</li>
        <li><strong>بيانات تقنية:</strong> ملفات السجلّ وعناوين IP اللازمة لتشغيل الخدمة وحمايتها.</li>
      </ul>
      <p>لا نجمع أرقام البطاقات: تتم المدفوعات الإلكترونية عبر Whish على صفحاتها الخاصة.</p>

      <h2>3. كيف نستخدمها</h2>
      <ul>
        <li>للردّ على الزبائن وأخذ الطلبات والحجوزات وحساب المجاميع وإرسال تحديثات الطلبات نيابةً عن التاجر؛</li>
        <li>لإرسال الطلبات إلى سائقي التاجر وموظفيه؛</li>
        <li>لتزويد التجّار بتقارير وتحليلات عن أعمالهم؛</li>
        <li>لتشغيل الخدمة وحمايتها ودعمها وفوترتها، والالتزام بالموجبات القانونية.</li>
      </ul>
      <p>لا نبيع البيانات الشخصية ولا نستخدم بيانات الزبائن لإعلاناتنا الخاصة.</p>

      <h2>4. المساعد الآلي (الذكاء الاصطناعي)</h2>
      <p>تتم معالجة رسائل الزبائن وقائمة التاجر بواسطة نموذج لغوي ذكي لفهم الطلبات وصياغة الردود. لا يمكن للمساعد تحديد الأسعار أو الرسوم: تُحسب المجاميع دائمًا من قائمة التاجر. يمكن للزبائن طلب التحدّث مع شخص في أي وقت، ويمكن للتجّار تولّي أي محادثة.</p>

      <h2>5. مزوّدو الخدمات الذين نشارك البيانات معهم</h2>
      <ul>
        <li><strong>Meta Platforms (منصّة واتساب للأعمال)</strong> — لإرسال واستقبال رسائل واتساب.</li>
        <li><strong>OpenAI</strong> — معالجة نصوص المحادثات بالذكاء الاصطناعي (لا تُستخدم لتدريب نماذجهم وفق شروط الـ API).</li>
        <li><strong>Whish Money</strong> — المدفوعات الإلكترونية، عندما يقدّمها التاجر.</li>
        <li><strong>Railway</strong> — الاستضافة السحابية لخوادمنا وقاعدة بياناتنا.</li>
        <li><strong>Cloudflare</strong> — تخزين النسخ الاحتياطية المشفّرة لقاعدة البيانات.</li>
        <li><strong>Google</strong> — تحليلات الموقع، فقط للزوار الذين يوافقون على ملفات تعريف الارتباط.</li>
      </ul>
      <p>يعالج كل مزوّد البيانات فقط لتقديم خدمته لنا. قد تتم معالجة البيانات خارج {p.country}؛ ونعتمد على الضمانات التعاقدية لدى المزوّدين.</p>

      <h2>6. مدّة الاحتفاظ بالبيانات</h2>
      <ul>
        <li>يقتصر سجلّ المحادثة الذي يستخدمه المساعد على أحدث الرسائل في كل محادثة.</li>
        <li>نحتفظ بالطلبات والحجوزات وسجلات الزبائن طوال استخدام التاجر لـ {b} ليتمكّن من خدمة زبائنه، ونحذفها خلال 90 يومًا من إغلاق حساب التاجر.</li>
        <li>تنتهي جلسات الدخول بعد 30 يومًا. ونحتفظ بسجلات الخادم لفترة محدودة لأغراض الأمان.</li>
      </ul>

      <h2 id="analytics">تحليلات الموقع وملفات تعريف الارتباط</h2>
      <p>يستخدم موقعنا العام Google Analytics فقط إذا ضغطت «موافقة» في شريط ملفات تعريف الارتباط. عندها يضع ملفات لقياس الزيارات (الصفحات المشاهدة، الموقع التقريبي، نوع الجهاز) مع إخفاء عنوان IP. إذا رفضت، لا توضع أي ملفات تحليلية. يُحفظ اختيارك في متصفحك؛ امسح بيانات المتصفح لتغييره. لا تستخدم لوحات التحكّم أي ملفات تحليلية.</p>

      <h2>7. حقوقك</h2>
      <p>يمكنك طلب الاطّلاع على بياناتك الشخصية أو تصحيحها أو حذفها، أو الاعتراض على معالجتها. يمكن للزبائن التواصل مع العمل الذي طلبوا منه أو معنا مباشرة. راجع <Link href="/data-deletion">كيفية حذف بياناتك</Link>.</p>

      <h2>8. الأمان</h2>
      <p>تُنقل البيانات عبر HTTPS، والوصول مقيّد حسب الدور، ولا يرى كل عمل إلا بياناته، وتُخزَّن كلمات المرور وبيانات الدفع بشكل مُشفّر، ويتم التحقّق من رسائل واتساب الواردة بتوقيع Meta.</p>

      <h2>9. الأطفال</h2>
      <p>{b} أداة للأعمال وليست موجّهة للأطفال دون 13 عامًا.</p>

      <h2>10. التعديلات</h2>
      <p>سنحدّث هذه الصفحة عند تغيّر ممارساتنا ونعرض تاريخ أحدث نسخة أعلاه.</p>

      <h2>11. التواصل</h2>
      <p>{who} — <a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? <> — <span dir="ltr">{company.phone}</span></> : null}.</p>
    </LegalPage>
  );
}

function PrivacyPageFr({ p }) {
  const b = company.brand;
  const who = `${company.legalName}${p.address ? `, ${p.address}` : ""}, ${p.country}`;
  return (
    <LegalPage title="Politique de confidentialité">
      <p>Cette politique explique comment {b} (« nous »), exploité par {who}, traite les données personnelles. {b} est une plateforme logicielle qui permet aux commerces (« commerçants ») de recevoir commandes et réservations de leurs clients sur WhatsApp grâce à la plateforme WhatsApp Business fournie par Meta.</p>

      <h2>1. Responsable du traitement</h2>
      <p>Pour les données des commerçants et de leur équipe, {company.legalName} est responsable du traitement. Pour les données des clients d&apos;un commerçant, le commerçant est responsable du traitement et {b} les traite pour son compte afin de fournir le service. Contact : <a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? <>, <span dir="ltr">{company.phone}</span></> : null}.</p>

      <h2>2. Données collectées</h2>
      <ul>
        <li><strong>Compte commerçant :</strong> nom du commerce, nom du contact, e-mail, numéros de téléphone/WhatsApp, adresse, type d&apos;activité, identifiants (stockés hachés), abonnement et facturation.</li>
        <li><strong>Données clients reçues via WhatsApp :</strong> numéro de téléphone, nom de profil WhatsApp, messages échangés avec le numéro du commerçant, détails des commandes et réservations, adresse ou position partagée, langue préférée, mode et statut de paiement, évaluations.</li>
        <li><strong>Livreurs et équipe :</strong> nom, numéro WhatsApp, horaires de service, livraisons effectuées et espèces encaissées.</li>
        <li><strong>Données techniques :</strong> journaux et adresses IP nécessaires au fonctionnement et à la sécurité du service.</li>
      </ul>
      <p>Nous ne collectons pas de numéros de carte : les paiements en ligne sont traités par Whish sur ses propres pages.</p>

      <h2>3. Utilisation</h2>
      <ul>
        <li>Répondre aux clients, prendre commandes et réservations, calculer les totaux et envoyer les mises à jour pour le compte du commerçant ;</li>
        <li>transmettre les commandes aux livreurs et à l&apos;équipe du commerçant ;</li>
        <li>fournir aux commerçants des rapports et statistiques sur leur propre activité ;</li>
        <li>exploiter, sécuriser, assister et facturer le service, et respecter nos obligations légales.</li>
      </ul>
      <p>Nous ne vendons pas de données personnelles et n&apos;utilisons pas les données des clients pour notre propre publicité.</p>

      <h2>4. Assistant automatisé (IA)</h2>
      <p>Les messages des clients et le catalogue du commerçant sont traités par un modèle de langage IA pour comprendre les demandes et rédiger les réponses. L&apos;assistant ne peut pas fixer de prix ni de frais : les totaux sont toujours calculés à partir du catalogue du commerçant. Les clients peuvent demander à parler à une personne à tout moment et les commerçants peuvent reprendre toute conversation.</p>

      <h2>5. Prestataires avec lesquels nous partageons des données</h2>
      <ul>
        <li><strong>Meta Platforms (WhatsApp Business Platform)</strong> — envoi et réception des messages WhatsApp.</li>
        <li><strong>OpenAI</strong> — traitement IA du texte des conversations (non utilisé pour entraîner leurs modèles selon les conditions de l&apos;API).</li>
        <li><strong>Whish Money</strong> — paiements en ligne, lorsque le commerçant les propose.</li>
        <li><strong>Railway</strong> — hébergement cloud de nos serveurs et de notre base de données.</li>
        <li><strong>Cloudflare</strong> — stockage des sauvegardes chiffrées de la base de données.</li>
        <li><strong>Google</strong> — statistiques du site, uniquement pour les visiteurs qui acceptent les cookies.</li>
      </ul>
      <p>Chaque prestataire ne traite les données que pour nous fournir son service. Les données peuvent être traitées hors du {p.country} ; nous nous appuyons sur les garanties contractuelles des prestataires.</p>

      <h2>6. Durée de conservation</h2>
      <ul>
        <li>L&apos;historique utilisé par l&apos;assistant se limite aux messages les plus récents de chaque discussion.</li>
        <li>Commandes, réservations et fiches clients sont conservées tant que le commerçant utilise {b}, puis supprimées dans les 90 jours suivant la fermeture de son compte.</li>
        <li>Les sessions expirent après 30 jours. Les journaux serveur sont conservés pour une durée limitée à des fins de sécurité.</li>
      </ul>

      <h2 id="analytics">Statistiques du site et cookies</h2>
      <p>Notre site public utilise Google Analytics uniquement si vous cliquez sur « Accepter » dans le bandeau cookies. Des cookies mesurent alors les visites (pages vues, localisation approximative, type d&apos;appareil) avec anonymisation de l&apos;IP. Si vous refusez, aucun cookie de mesure n&apos;est déposé. Votre choix est enregistré dans votre navigateur ; effacez les données du navigateur pour le modifier. Les tableaux de bord n&apos;utilisent aucun cookie de mesure.</p>

      <h2>7. Vos droits</h2>
      <p>Vous pouvez demander l&apos;accès, la rectification ou la suppression de vos données, ou vous opposer à leur traitement. Les clients peuvent contacter le commerce auprès duquel ils ont commandé ou nous écrire directement. Voir <Link href="/data-deletion">comment supprimer vos données</Link>.</p>

      <h2>8. Sécurité</h2>
      <p>Les données transitent en HTTPS, l&apos;accès est limité par rôle, chaque commerce ne voit que ses propres données, mots de passe et identifiants de paiement sont hachés ou chiffrés, et les webhooks WhatsApp sont vérifiés avec la signature de Meta.</p>

      <h2>9. Enfants</h2>
      <p>{b} est un outil professionnel qui ne s&apos;adresse pas aux enfants de moins de 13 ans.</p>

      <h2>10. Modifications</h2>
      <p>Nous mettrons cette page à jour lorsque nos pratiques changeront et afficherons la date de la dernière version ci-dessus.</p>

      <h2>11. Contact</h2>
      <p>{who} — <a href={`mailto:${company.email}`}>{company.email}</a>{company.phone ? <> — <span dir="ltr">{company.phone}</span></> : null}.</p>
    </LegalPage>
  );
}
