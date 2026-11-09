// The homepage demo: real NeoTalab conversations for each kind of shop, on the phones of the
// customer, the shop and the driver. The bot's messages are its real texts; each kind of shop
// follows its own flow: food and groceries in minutes with a driver, online shops in days (the shop delivers itself).
//
// A message is [chat, text, extras]:
//   lower-case chat = sent by the shop's NeoTalab number, upper-case = sent by that phone's owner
//   c / C  customer      s / S  shop owner or staff      d / D  driver
// extras: buttons (WhatsApp reply buttons), voice ("0:07"), day (date chip: a new day starts).

const UI = {
  en: {
    title: "See NeoTalab at work",
    lead: "Pick a shop and follow one order on the customer's, the shop's and the driver's WhatsApp.",
    online: "online", typing: "typing…", today: "Today", message: "Message",
    modes: { delivery: "🛵 Delivery", pickup: "🏪 Pickup" }, pause: "Pause", play: "Play", replay: "Replay", prev: "Previous step", next: "Next step", prevShop: "Previous shop", nextShop: "Next shop", voice: "Voice message", sendLabel: "Send",
    who: { c: "Customer", s: "Shop owner", d: "Driver" },
    waiting: { s: "🔔 New orders arrive here", d: "🛵 Deliveries arrive here" },
    bot: "NeoTalab",
    steps: { order: "Order", accept: "Shop accepts", whish: "Whish payment", delivery: "Delivery", pickup: "Pickup", rating: "Rating" },
    captions: { order: "The customer orders on WhatsApp", accept: "The shop taps ✅ to accept", whish: "The customer pays with Whish", delivery: "The order is on its way", pickup: "The customer picks it up at the shop", rating: "The customer rates the order" },
    choose: { shop: "Your business", mode: "Delivery or pickup", pay: "Payment" },
    pays: { whish: "💳 Whish", cash: "💵 Cash" },
  },
  ar: {
    title: "شاهد NeoTalab أثناء العمل",
    lead: "اختر نوع المحل وتابع طلبًا من البداية إلى النهاية على واتساب الزبون وصاحب المحل والسائق.",
    online: "متصل", typing: "يكتب…", today: "اليوم", message: "مراسلة",
    modes: { delivery: "🛵 توصيل", pickup: "🏪 استلام من المحل" }, pause: "إيقاف", play: "تشغيل", replay: "إعادة", prev: "الخطوة السابقة", next: "الخطوة التالية", prevShop: "المحل السابق", nextShop: "المحل التالي", voice: "رسالة صوتية", sendLabel: "إرسال",
    who: { c: "الزبون", s: "صاحب المحل", d: "السائق" },
    waiting: { s: "🔔 تصل الطلبات الجديدة هنا", d: "🛵 تصل التوصيلات هنا" },
    bot: "NeoTalab",
    steps: { order: "الطلب", accept: "المحل يقبل", whish: "دفع Whish", delivery: "التوصيل", pickup: "الاستلام", rating: "التقييم" },
    captions: { order: "الزبون يطلب عبر واتساب", accept: "المحل يضغط ✅ لقبول الطلب", whish: "الزبون يدفع عبر Whish", delivery: "الطلب في الطريق", pickup: "الزبون يستلم طلبه من المحل", rating: "الزبون يقيّم الطلب" },
    choose: { shop: "نوع عملك", mode: "توصيل أو استلام", pay: "الدفع" },
    pays: { whish: "💳 Whish", cash: "💵 نقدًا" },
  },
  fr: {
    title: "NeoTalab en action",
    lead: "Choisissez un commerce et suivez une commande sur le WhatsApp du client, du commerçant et du livreur.",
    online: "en ligne", typing: "écrit…", today: "Aujourd'hui", message: "Message",
    modes: { delivery: "🛵 Livraison", pickup: "🏪 À emporter" }, pause: "Pause", play: "Lecture", replay: "Rejouer", prev: "Étape précédente", next: "Étape suivante", prevShop: "Commerce précédent", nextShop: "Commerce suivant", voice: "Message vocal", sendLabel: "Envoyer",
    who: { c: "Client", s: "Commerçant", d: "Livreur" },
    waiting: { s: "🔔 Les nouvelles commandes arrivent ici", d: "🛵 Les livraisons arrivent ici" },
    bot: "NeoTalab",
    steps: { order: "Commande", accept: "Le commerce accepte", whish: "Paiement Whish", delivery: "Livraison", pickup: "Retrait", rating: "Note" },
    captions: { order: "Le client commande sur WhatsApp", accept: "Le commerce appuie sur ✅ pour accepter", whish: "Le client paie avec Whish", delivery: "La commande est en route", pickup: "Le client récupère sa commande", rating: "Le client note la commande" },
    choose: { shop: "Votre commerce", mode: "Livraison ou retrait", pay: "Paiement" },
    pays: { whish: "💳 Whish", cash: "💵 Espèces" },
  },
};

// What changes from one shop to another. Text is given as { en, ar, fr }.
const L = (en, ar, fr) => ({ en, ar, fr });

// kind: minutes / hours (prep time, then a driver), days (the shop delivers within prep days),
// travel: driving minutes. fee: delivery fee in dollars.
const SHOPS = [
  {
    id: "restaurant", icon: "🥙", menu: "menu", kind: "minutes", prep: 15, travel: 10, fee: 1,
    label: { en: "Restaurant", ar: "مطعم", fr: "Restaurant" },
    shop: { en: "Snack Al Hara", ar: "سناك الحارة", fr: "Snack Al Hara" },
    town: { en: "Jbeil", ar: "جبيل", fr: "Jbeil" },
    address: { en: "Main street, near the church, building Haddad, 2nd floor", ar: "الشارع العام حد الكنيسة، بناية حداد، الطابق الثاني", fr: "Rue principale près de l'église, immeuble Haddad, 2e étage" },
    shopAddress: { en: "Jbeil, main street", ar: "جبيل، الشارع العام", fr: "Jbeil, rue principale" },
    items: [
      [2, 3.5, L("Chicken shawarma", "شاورما دجاج", "Chawarma poulet")],
      [1, 2.0, L("Fries", "بطاطا مقلية", "Frites")],
    ],
  },
  {
    id: "bakery", icon: "🥐", menu: "menu", kind: "minutes", prep: 20, travel: 15, fee: 1,
    label: L("Bakery & sweets", "حلويات وأفران", "Pâtisserie"),
    shop: L("Patisserie Nour", "حلويات نور", "Pâtisserie Nour"),
    town: L("Tripoli", "طرابلس", "Tripoli"),
    address: L("Azmi street, building Karam, 3rd floor", "شارع عزمي، بناية كرم، الطابق الثالث", "Rue Azmi, immeuble Karam, 3e étage"),
    shopAddress: L("Tripoli, Tall square", "طرابلس، ساحة التل", "Tripoli, place du Tall"),
    items: [
      [1, 12.0, L("Maamoul", "معمول", "Maamoul"), L("1 kg", "1 كغ", "1 kg")],
      [2, 3.5, L("Knefeh", "كنافة", "Knéfé")],
    ],
  },
  {
    id: "market", icon: "🛒", menu: "products", kind: "minutes", prep: 15, travel: 15, fee: 1,
    label: { en: "Supermarket · Butcher · Fruits & vegetables", ar: "سوبرماركت · ملحمة · خضار وفواكه", fr: "Supermarché · Boucherie · Fruits et légumes" },
    shop: { en: "Mini Market Abou Elie", ar: "ميني ماركت أبو إيلي", fr: "Mini Market Abou Elie" },
    town: { en: "Zalka", ar: "الزلقا", fr: "Zalka" },
    address: { en: "Main road, building Khoury, 1st floor", ar: "الطريق العام، بناية خوري، الطابق الأول", fr: "Route principale, immeuble Khoury, 1er étage" },
    shopAddress: { en: "Zalka, main road", ar: "الزلقا، الطريق العام", fr: "Zalka, route principale" },
    items: [
      [2, 3.5, L("Labneh 500 g", "لبنة 500 غ", "Labné 500 g")],
      [1, 6.5, L("Eggs", "بيض", "Œufs"), L("30 pieces", "30 حبة", "30 pièces"), L("10 pieces (2.50$), 30 pieces (6.50$)", "10 حبات (2.50$)، 30 حبة (6.50$)", "10 pièces (2,50$), 30 pièces (6,50$)")],
    ],
  },
  {
    id: "online", icon: "🛍", menu: "catalogue", kind: "days", prep: [1, 2], fee: 3,
    label: { en: "Online shop · Clothes · Beauty · Electronics · Toys · Home", ar: "متجر إلكتروني · ألبسة · تجميل · إلكترونيات · ألعاب · منزل", fr: "Boutique en ligne · Vêtements · Beauté · Électronique · Jouets · Maison" },
    shop: L("Boutique Lina", "بوتيك لينا", "Boutique Lina"),
    town: L("Hamra", "الحمرا", "Hamra"),
    address: L("Makdessi street, building Saab, 5th floor", "شارع المقدسي، بناية صعب، الطابق الخامس", "Rue Makdessi, immeuble Saab, 5e étage"),
    shopAddress: L("Beirut, Hamra street", "بيروت، شارع الحمرا", "Beyrouth, rue Hamra"),
    items: [
      [1, 35.0, L("Black jeans", "جينز أسود", "Jean noir"), L("38", "38", "38"), L("36 (35.00$), 38 (35.00$), 40 (35.00$)", "36 (35.00$)، 38 (35.00$)، 40 (35.00$)", "36 (35,00$), 38 (35,00$), 40 (35,00$)")],
      [1, 18.0, L("Silk scarf", "إيشارب حرير", "Foulard en soie")],
    ],
  },
];

// The bot's own texts (backend/lang/*/whatsapp.php), plus what the customer, the owner and the
// driver type. #128 is the order number, +961 71 234 567 the customer, +961 70 111 222 the driver.
const T = {
  en: {
    hi: "Hi 👋", driver: "Karim",
    welcome: (shop, menu, item) => `Hi! 👋 Welcome to ${shop}. What would you like?

${menu}

Tell me what you would like, for example: 2 ${item}. You can also send a voice note.`,
    askSize: (item, opts) => `${item} comes in: ${opts}. Which one would you like?`,
    sure: (recap) => `Sure! 🙂\n\n${recap}`,
    thatsAll: "That's all, thanks", typeReady: "ready", typeOut: "out", typeDone: "done",
    yourCart: "Your cart:", changeHint: "To change your items, just write what you want.", btnAnother: "🕐 Another time", btnPaid: "✅ I paid",
    checkingNow: (shop) => `Thank you! ${shop} is checking the payment now. We confirm your order as soon as it arrives.`,
    how: "How would you like to get your order?", btnDelivery: "🛵 Delivery", btnPickup: "🏪 Pickup",
    location: "📍 Tap the button to send your location. You can add the building and floor right after.",
    pinShared: (town) => `📍 Location: ${town}`, details: "📍 Got your location. Add details for the driver (building, floor, landmark), or tap Skip.", btnSkip: "⏭️ Skip",
    pay: "How would you like to pay?", btnCash: "💵 Cash on delivery", btnCashShop: "💵 Cash at the shop",
    currency: (usd, lbp) => `Total: ${usd} = ${lbp}\nPay cash in dollars, in lira, or both?`, btnSplit: "🔀 $ + LBP",
    splitAsk: "How many dollars will you pay? Type the amount (for example 5). The rest is paid in lira at the shop's rate.", split: (p) => ` — you pay ${p}`,
    estDaysPickup: (r) => `Ready for pickup in ${r} working days`, yourOrder: "Your order", subtotal: "Subtotal", deliveryFee: () => "Delivery", total: "Total",
    pickupAt: (address) => `Pickup at ${address}`, cash: "Cash on delivery", cashShop: "Cash at the shop",
    btnConfirm: "✅ Confirm order",  btnCancel: "❌ Cancel",
    estArrival: (d) => `Preparation ${d}. We will message you the arrival time when it is on its way.`, estReady: (d) => `Ready in ${d}`, estDays: (r) => `Delivered in ${r} working days`,
    aboutMins: (n) => `about ${n} min`, aboutHours: (n) => `about ${n} hours`,
    minsShort: (n) => `${n} min`, hoursShort: (n) => `${n} h`, daysShort: (r) => `${r} days`,
    arriving: (t) => `arriving around ${t}`, readyAround: (t) => `ready for pickup around ${t}`,
    byDate: (d) => `delivered by ${d}`, readyByDate: (d) => `ready for pickup on ${d}`,
    placed: (shop, total) => `🧾 Order #128 sent to ${shop}.\nTotal: ${total}\nYou will get a message as soon as the shop accepts it.`,
    whishAfter: "💳 You will get the Whish payment link here as soon as the shop accepts your order.",
    payWhish: "💳 Whish", payCash: "💵 cash",
    
    whishAccepted: (shop, amount) => `✅ ${shop} accepted your order #128! Pay ${amount} to their Whish number, and write 128 in the note. Tap "I paid" when done. We start once ${shop} confirms the payment.`,
    whishPaid: (when) => `✅ Payment received! Order #128 is confirmed — ${when}.`,
    confirmed: (shop, when) => `✅ ${shop} accepted your order #128 — ${when}.`,
    
    btnDelivered: "✅ Delivered", 
    drvNew: (shop) => `🛵 New delivery #128 — ${shop}`, customer: "📞 Customer: +961 71 234 567",
    drvWay: "🛵 Delivery #128 — on the way", drvTap: "Tap “I'm outside” when you arrive.",
    btnPickedUp: "🚀 Picked up", btnOutside: "📍 I'm outside", told: "👍 The customer was told you are outside.", drvDone: "✅ Delivery #128 completed. Thanks!",
    collectCash: (a) => `💵 Collect: ${a} (cash)`, paidWhish: "💳 Paid with Whish — collect nothing from the customer",
    onWay: (d, when) => `🛵 Your order #128 is on its way with ${d} — ${when}.`, outside: (d) => `📍 ${d} is outside with your order #128! 📞 +961 70 111 222`,
    delivered: (shop) => `✅ Your order #128 was delivered. Thank you for ordering from ${shop}!`, outForDelivery: "🛵 Your order #128 is on its way!",
    readyPickup: (a) => `📦 Your order #128 is ready! You can pick it up now at ${a}.`, collected: (shop) => `✅ Thank you for picking up order #128 from ${shop}!`,
    rateGreat: "👍 Great", rateOkay: "😐 Okay", rateBad: "👎 Bad",
    qOrder: "How was your order?", rateThanks: "Thank you for your rating! 🙏",
  },
  ar: {
    hi: "مرحبا 👋", driver: "كريم",
    welcome: (shop, menu, item) => `أهلا! 👋 أهلا وسهلا بـ ${shop}. شو بتحب تطلب؟

${menu}

اكتب لي شو بدك، مثلاً: ٢ ${item}. فيك كمان تبعت رسالة صوتية.`,
    askSize: (item, opts) => `${item} متوفر بـ: ${opts}. أي واحد بتحب؟`,
    sure: (recap) => `أكيد! 🙂\n\n${recap}`,
    thatsAll: "هيدا كل شي، شكراً", typeReady: "جاهز", typeOut: "طالع", typeDone: "تم",
    yourCart: "سلّتك:", changeHint: "لتغيير الأصناف، اكتب ما تريده فقط.", btnAnother: "🕐 وقت آخر", btnPaid: "✅ دفعت",
    checkingNow: (shop) => `شكراً! ${shop} يتحقق من الدفعة الآن. سنؤكد طلبك فور وصولها.`,
    how: "كيف تريد استلام طلبك؟", btnDelivery: "🛵 توصيل", btnPickup: "🏪 استلام من المحل",
    location: "📍 اضغط الزر لإرسال موقعك، وبعدها يمكنك إضافة البناية والطابق.",
    pinShared: (town) => `📍 الموقع: ${town}`, details: "📍 وصلنا موقعك. أضف تفاصيل للسائق (البناية، الطابق، علامة مميزة)، أو اضغط تخطٍّ.", btnSkip: "⏭️ تخطٍّ",
    pay: "كيف تريد أن تدفع؟", btnCash: "💵 نقدًا عند التسليم", btnCashShop: "💵 نقدًا في المحل",
    currency: (usd, lbp) => `المجموع: ${usd} = ${lbp}\nتدفع نقدًا بالدولار أم بالليرة أم بالاثنين؟`, btnSplit: "🔀 دولار + ليرة",
    splitAsk: "كم دولارًا ستدفع؟ اكتب المبلغ (مثلًا 5)، والباقي تدفعه بالليرة حسب سعر صرف المحل.", split: (p) => ` — تدفع ${p}`,
    estDaysPickup: (r) => `جاهز للاستلام خلال ${r} أيام عمل`, yourOrder: "طلبك", subtotal: "المجموع", deliveryFee: () => "التوصيل", total: "الإجمالي",
    pickupAt: (address) => `الاستلام من ${address}`, cash: "نقدًا عند التسليم", cashShop: "نقدًا في المحل",
    btnConfirm: "✅ تأكيد الطلب",  btnCancel: "❌ إلغاء",
    estArrival: (d) => `التحضير ${d}. سنرسل لك وقت الوصول عندما يكون الطلب في الطريق.`, estReady: (d) => `جاهز خلال ${d}`, estDays: (r) => `التوصيل خلال ${r} أيام عمل`,
    aboutMins: (n) => `حوالي ${n} دقيقة`, aboutHours: (n) => `حوالي ${n} ساعات`,
    minsShort: (n) => `${n} د`, hoursShort: (n) => `${n} س`, daysShort: (r) => `${r} أيام`,
    arriving: (t) => `يصل حوالي ${t}`, readyAround: (t) => `جاهز للاستلام حوالي ${t}`,
    byDate: (d) => `يصل قبل ${d}`, readyByDate: (d) => `جاهز للاستلام يوم ${d}`,
    placed: (shop, total) => `🧾 تم إرسال الطلب رقم 128 إلى ${shop}.\nالمجموع: ${total}\nستصلك رسالة فور قبول المحل للطلب.`,
    whishAfter: "💳 سيصلك رابط الدفع عبر Whish هنا فور قبول المحل لطلبك.",
    payWhish: "💳 Whish", payCash: "💵 كاش",
    
    whishAccepted: (shop, amount) => `✅ قبل ${shop} طلبك رقم 128! ادفع ${amount} إلى رقم Whish الخاص بالمحل، واكتب 128 في الملاحظة. اضغط «دفعت» عند الانتهاء. نبدأ فور تأكيد ${shop} للدفعة.`,
    whishPaid: (when) => `✅ تم استلام الدفع! الطلب رقم 128 مؤكد — ${when}.`,
    confirmed: (shop, when) => `✅ قبل ${shop} طلبك رقم 128 — ${when}.`,
    
    btnDelivered: "✅ تم التوصيل", 
    drvNew: (shop) => `🛵 توصيل جديد رقم 128 — ${shop}`, customer: "📞 الزبون: +961 71 234 567",
    drvWay: "🛵 التوصيل رقم 128 — في الطريق", drvTap: "اضغط «أنا في الخارج» عند الوصول.",
    btnPickedUp: "🚀 تم الاستلام", btnOutside: "📍 أنا في الخارج", told: "👍 تم إبلاغ الزبون أنك في الخارج.", drvDone: "✅ تم التوصيل رقم 128. شكراً!",
    collectCash: (a) => `💵 المبلغ: ${a} (كاش)`, paidWhish: "💳 مدفوع عبر Whish — لا تأخذ أي مبلغ من الزبون",
    onWay: (d, when) => `🛵 طلبك رقم 128 في الطريق مع ${d} — ${when}.`, outside: (d) => `📍 ${d} في الخارج مع طلبك رقم 128! 📞 +961 70 111 222`,
    delivered: (shop) => `✅ تم توصيل طلبك رقم 128. شكراً لطلبك من ${shop}!`, outForDelivery: "🛵 طلبك رقم 128 في الطريق إليك!",
    readyPickup: (a) => `📦 طلبك رقم 128 جاهز! يمكنك استلامه الآن من ${a}.`, collected: (shop) => `✅ شكراً لاستلامك الطلب رقم 128 من ${shop}!`,
    rateGreat: "👍 ممتاز", rateOkay: "😐 عادي", rateBad: "👎 سيء",
    qOrder: "كيف كان طلبك؟", rateThanks: "شكراً على تقييمك! 🙏",
  },
  fr: {
    hi: "Bonjour 👋", driver: "Karim",
    welcome: (shop, menu, item) => `Bonjour ! 👋 Bienvenue chez ${shop}. Que souhaitez-vous ?

${menu}

Dites-moi ce que vous souhaitez, par exemple : 2 ${item}. Vous pouvez aussi envoyer un vocal.`,
    askSize: (item, opts) => `${item} existe en : ${opts}. Lequel souhaitez-vous ?`,
    sure: (recap) => `Bien sûr ! 🙂\n\n${recap}`,
    thatsAll: "C'est tout, merci", typeReady: "prêt", typeOut: "en route", typeDone: "livré",
    yourCart: "Votre panier :", changeHint: "Pour changer vos articles, écrivez simplement ce que vous voulez.", btnAnother: "🕐 Autre heure", btnPaid: "✅ J'ai payé",
    checkingNow: (shop) => `Merci ! ${shop} vérifie le paiement maintenant. Nous confirmons votre commande dès qu'il arrive.`,
    how: "Comment souhaitez-vous recevoir votre commande ?", btnDelivery: "🛵 Livraison", btnPickup: "🏪 À emporter",
    location: "📍 Touchez le bouton pour envoyer votre position. Vous pourrez ajouter l'immeuble et l'étage juste après.",
    pinShared: (town) => `📍 Position : ${town}`, details: "📍 Position reçue. Ajoutez des détails pour le livreur (immeuble, étage, repère) ou touchez Passer.", btnSkip: "⏭️ Passer",
    pay: "Comment souhaitez-vous payer ?", btnCash: "💵 À la livraison", btnCashShop: "💵 Au commerce",
    currency: (usd, lbp) => `Total : ${usd} = ${lbp}\nPayer en dollars, en livres, ou les deux ?`, btnSplit: "🔀 $ + livres",
    splitAsk: "Combien de dollars payez-vous ? Écrivez le montant (par exemple 5), le reste en livres au taux du commerce.", split: (p) => ` — vous payez ${p}`,
    estDaysPickup: (r) => `Prête à emporter sous ${r} jours ouvrés`, yourOrder: "Votre commande", subtotal: "Sous-total", deliveryFee: () => "Livraison", total: "Total",
    pickupAt: (address) => `Retrait : ${address}`, cash: "Espèces à la livraison", cashShop: "Espèces au commerce",
    btnConfirm: "✅ Confirmer",  btnCancel: "❌ Annuler",
    estArrival: (d) => `Préparation : ${d}. Nous vous enverrons l'heure d'arrivée dès que la commande sera en route.`, estReady: (d) => `Prête dans ${d}`, estDays: (r) => `Livrée sous ${r} jours ouvrés`,
    aboutMins: (n) => `environ ${n} min`, aboutHours: (n) => `environ ${n} heures`,
    minsShort: (n) => `${n} min`, hoursShort: (n) => `${n} h`, daysShort: (r) => `${r} j`,
    arriving: (t) => `arrivée vers ${t}`, readyAround: (t) => `prête à emporter vers ${t}`,
    byDate: (d) => `livrée d'ici ${d}`, readyByDate: (d) => `prête à emporter le ${d}`,
    placed: (shop, total) => `🧾 Commande n° 128 envoyée à ${shop}.\nTotal : ${total}\nVous recevrez un message dès que le commerce l'accepte.`,
    whishAfter: "💳 Vous recevrez ici le lien de paiement Whish dès que le commerce aura accepté votre commande.",
    payWhish: "💳 Whish", payCash: "💵 espèces",
    
    whishAccepted: (shop, amount) => `✅ ${shop} a accepté votre commande n° 128 ! Payez ${amount} au numéro Whish du commerce, en écrivant 128 dans la note. Appuyez sur « J'ai payé » ensuite. Nous commençons dès que ${shop} confirme le paiement.`,
    whishPaid: (when) => `✅ Paiement reçu ! Commande n° 128 confirmée — ${when}.`,
    confirmed: (shop, when) => `✅ ${shop} a accepté votre commande n° 128 — ${when}.`,
    
    btnDelivered: "✅ Livrée", 
    drvNew: (shop) => `🛵 Nouvelle livraison n° 128 — ${shop}`, customer: "📞 Client : +961 71 234 567",
    drvWay: "🛵 Livraison n° 128 — en route", drvTap: "Touchez « Je suis devant » en arrivant.",
    btnPickedUp: "🚀 Récupérée", btnOutside: "📍 Je suis devant", told: "👍 Le client a été prévenu que vous êtes devant.", drvDone: "✅ Livraison n° 128 terminée. Merci !",
    collectCash: (a) => `💵 À encaisser : ${a} (espèces)`, paidWhish: "💳 Payé avec Whish — rien à encaisser auprès du client",
    onWay: (d, when) => `🛵 Votre commande n° 128 est en route avec ${d} — ${when}.`, outside: (d) => `📍 ${d} est devant chez vous avec la commande n° 128 ! 📞 +961 70 111 222`,
    delivered: (shop) => `✅ Votre commande n° 128 a été livrée. Merci d'avoir commandé chez ${shop} !`, outForDelivery: "🛵 Votre commande n° 128 est en route !",
    readyPickup: (a) => `📦 Votre commande n° 128 est prête ! Vous pouvez la récupérer à ${a}.`, collected: (shop) => `✅ Merci d'avoir récupéré la commande n° 128 chez ${shop} !`,
    rateGreat: "👍 Super", rateOkay: "😐 Correct", rateBad: "👎 Mauvais",
    qOrder: "Comment était votre commande ?", rateThanks: "Merci pour votre note ! 🙏",
  },
};

const LBP_RATE = 89500;
// The order is placed on Wednesday 14 October; shops counting in days deliver on a later day.
const ORDER_DAY = new Date(2026, 9, 14);
const LOCALES = { en: "en-GB", ar: "ar-LB-u-nu-latn", fr: "fr-FR" };
// Arabic text keeps dollar amounts and phone numbers the right way round.
const iso = (lang, text) => (lang === "ar" ? `⁦${text}⁩` : text);

// Amounts as the bot writes them: "5.50$" and "493,000 LBP".
function money(lang, v) {
  return iso(lang, `${v.toFixed(2)}$`);
}

function lira(lang, v) {
  return iso(lang, `${(Math.ceil((v * LBP_RATE) / 1000) * 1000).toLocaleString("en-US")} LBP`);
}

function clock(lang, mins) {
  const h = Math.floor(mins / 60) % 24;
  const m = String(mins % 60).padStart(2, "0");
  return lang === "en" ? `${h % 12 || 12}:${m} ${h < 12 ? "AM" : "PM"}` : `${String(h).padStart(2, "0")}:${m}`;
}

function workingDay(lang, days) {
  const d = new Date(ORDER_DAY);
  for (let left = days; left > 0;) {
    d.setDate(d.getDate() + 1);
    if (d.getDay() !== 0) left--;
  }
  return new Intl.DateTimeFormat(LOCALES[lang], { weekday: "long", day: "numeric", month: "long" }).format(d);
}

/** One shop's conversation: [time, step, messages], each message [chat, text, extras]. */
function script(shop, lang, delivery, whish) {
  const t = T[lang];
  const name = (v) => v[lang];
  const shopName = name(shop.shop);
  const shopAddress = name(shop.shopAddress);

  const days = shop.kind === "days";
  const hours = shop.kind === "hours";
  const range = days ? (shop.prep[0] === shop.prep[1] ? `${shop.prep[1]}` : `${shop.prep[0]}–${shop.prep[1]}`) : "";
  const prepMins = hours ? shop.prep * 60 : shop.prep;
  const driver = delivery && !days; // shops counting in days deliver themselves
  const town = name(shop.town);
  const address = `${town} — ${name(shop.address)}`;
  const item = ([qty, , n, variant]) => `${qty}× ${name(n)}${variant ? ` (${name(variant)})` : ""}`;
  const subtotal = shop.items.reduce((sum, [qty, price]) => sum + qty * price, 0);
  const total = subtotal + (delivery ? shop.fee : 0);
  const lines = shop.items.map((i) => `${item(i)} — ${money(lang, i[0] * i[1])}`).join("\n");
  const asked = shop.items.find((i) => i[3]); // a size or option the assistant asks for in words
  const menuLines = shop.items.map((i) => `• ${name(i[2])} — ${money(lang, i[1])}`).join("\n");
  const bullets = shop.items.map((i) => `• ${item(i)}`).join("\n");
  const payTag = whish ? t.payWhish : t.payCash;
  const cashButton = delivery ? t.btnCash : t.btnCashShop;
  const mins = prepMins + (delivery ? shop.travel : 0);
  const duration = mins >= 90 ? t.aboutHours(Math.round(mins / 6) / 10) : t.aboutMins(mins);
  const prepDuration = prepMins >= 90 ? t.aboutHours(Math.round(prepMins / 6) / 10) : t.aboutMins(prepMins);
  const estimate = days ? (delivery ? t.estDays(range) : t.estDaysPickup(range)) : delivery ? t.estArrival(prepDuration) : t.estReady(duration);
  // Cash is paid split: round dollars, the rest in lira at the shop's rate.
  const dollars = [Math.floor(total / 10) * 10, Math.floor(total), total - 5].find((d) => d > 0 && d < total);
  const paid = whish ? money(lang, total) : `${money(lang, dollars)} + ${lira(lang, total - dollars)}`;
  const colon = lang === "fr" ? " : " : ": "; // French puts a space before the colon
  const rateButtons = { buttons: [t.rateGreat, t.rateOkay, t.rateBad] };
  const cartRecap = `${t.yourCart}\n${shop.items.map((i) => `• ${item(i)} — ${money(lang, i[0] * i[1])}`).join("\n")}\n${t.subtotal}${colon}${money(lang, subtotal)}`;
  const receipt = `🧾 *${t.yourOrder}*\n${lines}\n\n${t.subtotal}${colon}${money(lang, subtotal)}\n`
    + (delivery ? `${t.deliveryFee(town)}${colon}${money(lang, shop.fee)}\n` : "")
    + `*${t.total}${colon}${money(lang, total)}*${whish ? "" : ` (${lira(lang, total)})`}\n\n`
    + (delivery ? `🛵 ${address}` : `🏪 ${t.pickupAt(shopAddress)}`)
    + `\n${whish ? "💳 Whish" : `💵 ${delivery ? t.cash : t.cashShop} · ${paid}`}\n⏱ ${estimate}\n\n${t.changeHint}`;

  // Times of day in minutes: the shop accepts at 12:36, Whish is paid at 12:39.
  const accepted = 12 * 60 + 36;
  const go = whish ? accepted + 3 : accepted;
  const ready = go + prepMins;
  const arrive = ready + (shop.travel || 0);
  const dueDay = days ? workingDay(lang, shop.prep[1]) : null;
  const when = days ? (delivery ? t.byDate(dueDay) : t.readyByDate(dueDay))
    : delivery ? t.arriving(clock(lang, arrive)) : t.readyAround(clock(lang, ready));
  const at = (m) => `${String(Math.floor(m / 60)).padStart(2, "0")}:${String(m % 60).padStart(2, "0")}`;
  const label = days ? t.daysShort(range) : hours ? t.hoursShort(shop.prep) : t.minsShort(shop.prep);
  const where = delivery ? `📍 ${address}\n📍 https://www.google.com/maps?q=34.129,35.648\n📞 +961 71 234 567` : "📞 +961 71 234 567";
  // The shop phone sees the customer's chat: what the bot says to the customer shows there too, and the shop types its answers.
  const mirror = (text) => [["c", text], ["s", text]];
  const collect = whish ? t.paidWhish : t.collectCash(paid);
  const assigned = ["d", `${t.drvNew(shopName)}\n${bullets}\n${collect}\n📍 ${address}\n${t.customer}`, { buttons: [t.btnPickedUp, t.btnDelivered] }];

  // The order leaves the shop (or is ready), then reaches the customer (or is collected) later.
  const [leaves, arrives] = driver
    ? [[
      ["D", t.btnPickedUp],
      ["d", `${t.drvWay}\n📍 ${address}\n${t.customer}\n${collect}\n${t.drvTap}`, { buttons: [t.btnOutside, t.btnDelivered] }],
      ["c", t.onWay(t.driver, t.arriving(clock(lang, arrive)))],
    ], [
      ["D", t.btnOutside],
      ["d", t.told],
      ["c", t.outside(t.driver)],
      ["D", t.btnDelivered],
      ["d", t.drvDone],
      ["c", `${t.delivered(shopName)}\n\n${t.qOrder}`, rateButtons],
    ]]
    : delivery
      ? [[["S", t.typeOut], ...mirror(t.outForDelivery)], [["S", t.typeDone], ...mirror(`${t.delivered(shopName)}\n\n${t.qOrder}`)]]
      : [[["S", t.typeReady], ...mirror(t.readyPickup(shopAddress))], [["S", t.typeDone], ...mirror(`${t.collected(shopName)}\n\n${t.qOrder}`)]];
  // Shops counting in days: the order comes back to the shop on its day (10:00), arrives at 11:30.
  const [leaveAt, arriveAt] = days ? [10 * 60, 11 * 60 + 30] : driver ? [ready, arrive - 1] : [ready, ready + 15];

  return [
    ["12:30", "order", [
      ["C", t.hi],
      ["c", t.welcome(shopName, menuLines, name(shop.items[0][2]))],
      ["C", "", { voice: "0:07" }],
      ...(asked ? [
        ["c", t.askSize(name(asked[2]), name(asked[4]))],
        ["C", name(asked[3])],
      ] : []),
      ["c", t.sure(cartRecap)],
      ["C", t.thatsAll],
      ...(delivery ? [
        ["c", t.how, { buttons: [t.btnDelivery, t.btnPickup] }],
        ["C", t.btnDelivery],
        ["c", t.location],
        ["C", t.pinShared(name(shop.town))],
        ["c", t.details, { buttons: [t.btnSkip] }],
        ["C", name(shop.address)],
      ] : []),
      ["c", t.pay, { buttons: [cashButton, "💳 Whish"] }],
      ["C", whish ? "💳 Whish" : cashButton],
      ...(whish ? [
        ["c", t.currency(money(lang, total), lira(lang, total)), { buttons: [`💵 ${money(lang, total)}`, `🇱🇧 ${lira(lang, total)}`] }],
        ["C", `💵 ${money(lang, total)}`],
      ] : [
        ["c", t.currency(money(lang, total), lira(lang, total)), { buttons: [`💵 ${money(lang, total)}`, `🇱🇧 ${lira(lang, total)}`, t.btnSplit] }],
        ["C", t.btnSplit],
        ["c", t.splitAsk],
        ["C", String(dollars)],
      ]),
      ["c", receipt, { buttons: [t.btnConfirm, t.btnAnother, t.btnCancel] }],
      ["C", t.btnConfirm],
      ["c", t.placed(shopName, money(lang, total) + (whish ? "" : t.split(paid))) + (whish ? `\n${t.whishAfter}` : "")],
    ]],
    [at(accepted), "accept", [
      ["s", receipt],
      ["s", t.placed(shopName, money(lang, total) + (whish ? "" : t.split(paid))) + (whish ? `\n${t.whishAfter}` : "")],
      ["S", "✅"],
      ...(whish
        ? [["c", t.whishAccepted(shopName, money(lang, total)), { buttons: [t.btnPaid] }], ["s", t.whishAccepted(shopName, money(lang, total))]]
        : [...mirror(t.confirmed(shopName, when)), ...(driver ? [assigned] : [])]),
    ]],
    ...(whish ? [[at(go), "whish", [
      ["C", t.btnPaid],
      ["s", t.btnPaid],
      ...mirror(t.checkingNow(shopName)),
      ["S", "✅"],
      ...mirror(t.whishPaid(when)),
      ...(driver ? [assigned] : []),
    ]]] : []),
    [at(leaveAt), delivery ? "delivery" : "pickup", leaves],
    [at(arriveAt), delivery ? "delivery" : "pickup", arrives],
    [at(arriveAt + 5), "rating", [
      ["C", t.rateGreat],
      ["c", t.rateThanks],
    ]],
  ];
}

function build(shop, lang, delivery, whish) {
  const ui = UI[lang];
  const messages = [];
  const steps = [];
  const dayOf = {}; // the day each phone last showed
  let day = null;
  let now = 0;
  script(shop, lang, delivery, whish).forEach(([time, key, list]) => {
    const [h, m] = time.split(":").map(Number);
    if (steps[steps.length - 1] !== key) steps.push(key);
    list.forEach(([who, text, { day: newDay, ...extra } = {}], k) => {
      // A new day resets the clock and puts its date on each phone's next message.
      const mins = h * 60 + m + Math.floor(k / 3);
      now = newDay ? mins : Math.max(mins, now);
      if (newDay) day = newDay;
      const chat = who.toLowerCase();
      const chip = day && dayOf[chat] !== day ? day : undefined;
      dayOf[chat] = day;
      messages.push({
        chat,
        out: who !== chat,
        step: steps.length - 1,
        time: `${Math.floor(now / 60) % 24}:${String(now % 60).padStart(2, "0")}`,
        // Phone numbers stay left to right in Arabic, as the bot sends them.
        text: lang === "ar" ? text.replace(/\+961[\d ]*\d/g, (phone) => iso(lang, phone)) : text,
        ...(chip && { day: chip }),
        ...extra,
      });
    });
  });
  const chats = [...new Set(messages.map((msg) => msg.chat))].sort((a, b) => "csd".indexOf(a) - "csd".indexOf(b));
  return { id: shop.id, icon: shop.icon, label: shop.label[lang], shop: shop.shop[lang], steps: steps.map((key) => ui.steps[key]), captions: steps.map((key) => ui.captions[key]), chats, messages };
}

// Same order as the business types on the sign-up form.
// The demo offers two families of shops; each plays one example and names the shops it stands for.
const FAMILIES = [
  { id: "food", icon: "🍽", label: L("Food", "أكل", "Restauration"), example: "restaurant", members: ["restaurant", "bakery"] },
  { id: "shops", icon: "🛒", label: L("Groceries", "بقالة", "Épicerie"), example: "market", members: ["market"] },
  { id: "online", icon: "🛍", label: L("Online shop", "متجر إلكتروني", "Boutique en ligne"), example: "online", members: ["online"] },
];

export function orderDemo(lang) {
  const key = UI[lang] ? lang : "en";
  return {
    ...UI[key],
    scenarios: FAMILIES.map((family) => {
      const shop = SHOPS.find((s) => s.id === family.example);
      const members = family.members.map((id) => SHOPS.find((s) => s.id === id).label[key]).join(" · ");
      const base = { id: family.id, icon: family.icon, label: family.label[key], members };
      return {
        ...base,
        defaultMode: "delivery",
        delivery: { whish: build(shop, key, true, true), cash: build(shop, key, true, false) },
        pickup: { whish: build(shop, key, false, true), cash: build(shop, key, false, false) },
      };
    }),
  };
}
