// The homepage demo: real NeoTalab conversations for each kind of shop, on the phones of the
// customer, the shop and the driver. The bot's messages are its real texts; each kind of shop
// follows its own flow: food and groceries in minutes with a driver, flowers and water in hours,
// shops counting in days deliver themselves, and salons take bookings.
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
    menuButtons: { menu: "📋 Menu", products: "📋 Products", catalogue: "📋 Catalogue", price_list: "📋 Price list", services: "📋 Services" },
    steps: { order: "Order", accept: "Shop accepts", whish: "Whish payment", delivery: "Delivery", pickup: "Pickup", rating: "Rating", book: "Booking", remind: "Reminder", visit: "Visit" },
    captions: { order: "The customer orders on WhatsApp", accept: "The shop taps ✅ to accept", whish: "The customer pays with Whish", delivery: "The order is on its way", pickup: "The customer picks it up at the shop", rating: "The customer rates the order", book: "The customer books a time on WhatsApp", remind: "NeoTalab reminds them before the visit", visit: "The visit, then a rating" },
    bookingNote: "🗓 By appointment",
    choose: { shop: "Your business", mode: "Delivery or pickup", pay: "Payment", booking: "How it works" },
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
    menuButtons: { menu: "📋 القائمة", products: "📋 المنتجات", catalogue: "📋 الكتالوج", price_list: "📋 لائحة الأسعار", services: "📋 الخدمات" },
    steps: { order: "الطلب", accept: "المحل يقبل", whish: "دفع Whish", delivery: "التوصيل", pickup: "الاستلام", rating: "التقييم", book: "الحجز", remind: "التذكير", visit: "الزيارة" },
    captions: { order: "الزبون يطلب عبر واتساب", accept: "المحل يضغط ✅ لقبول الطلب", whish: "الزبون يدفع عبر Whish", delivery: "الطلب في الطريق", pickup: "الزبون يستلم طلبه من المحل", rating: "الزبون يقيّم الطلب", book: "الزبون يحجز موعدًا عبر واتساب", remind: "NeoTalab يذكّره قبل الموعد", visit: "الزيارة، ثم التقييم" },
    bookingNote: "🗓 بالمواعيد",
    choose: { shop: "نوع عملك", mode: "توصيل أو استلام", pay: "الدفع", booking: "طريقة العمل" },
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
    menuButtons: { menu: "📋 Menu", products: "📋 Produits", catalogue: "📋 Catalogue", price_list: "📋 Tarifs", services: "📋 Services" },
    steps: { order: "Commande", accept: "Le commerce accepte", whish: "Paiement Whish", delivery: "Livraison", pickup: "Retrait", rating: "Note", book: "Réservation", remind: "Rappel", visit: "Visite" },
    captions: { order: "Le client commande sur WhatsApp", accept: "Le commerce appuie sur ✅ pour accepter", whish: "Le client paie avec Whish", delivery: "La commande est en route", pickup: "Le client récupère sa commande", rating: "Le client note la commande", book: "Le client réserve sur WhatsApp", remind: "NeoTalab lui rappelle le rendez-vous", visit: "La visite, puis la note" },
    bookingNote: "🗓 Sur rendez-vous",
    choose: { shop: "Votre commerce", mode: "Livraison ou retrait", pay: "Paiement", booking: "Fonctionnement" },
    pays: { whish: "💳 Whish", cash: "💵 Espèces" },
  },
};

// What changes from one shop to another. Text is given as { en, ar, fr }.
const L = (en, ar, fr) => ({ en, ar, fr });

// kind: minutes / hours (prep time, then a driver), days (the shop delivers within prep days),
// booking (appointments). travel: driving minutes. fee: delivery fee in dollars.
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
    label: { en: "Supermarket", ar: "سوبرماركت", fr: "Supérette" },
    shop: { en: "Mini Market Abou Elie", ar: "ميني ماركت أبو إيلي", fr: "Mini Market Abou Elie" },
    town: { en: "Zalka", ar: "الزلقا", fr: "Zalka" },
    address: { en: "Main road, building Khoury, 1st floor", ar: "الطريق العام، بناية خوري، الطابق الأول", fr: "Route principale, immeuble Khoury, 1er étage" },
    shopAddress: { en: "Zalka, main road", ar: "الزلقا، الطريق العام", fr: "Zalka, route principale" },
    items: [
      [2, 3.5, L("Labneh 500 g", "لبنة 500 غ", "Labné 500 g")],
      [1, 6.5, L("Eggs", "بيض", "Œufs"), L("30 pieces", "30 حبة", "30 pièces")],
    ],
  },
  {
    id: "butcher", icon: "🥩", menu: "products", kind: "minutes", prep: 20, travel: 15, fee: 1,
    label: L("Butcher & produce", "ملحمة وخضرة", "Boucherie et primeur"),
    shop: L("Boucherie Al Wadi", "ملحمة الوادي", "Boucherie Al Wadi"),
    town: L("Zahle", "زحلة", "Zahlé"),
    address: L("Boulevard, building Maalouf, ground floor", "البولفار، بناية معلوف، الطابق الأرضي", "Boulevard, immeuble Maalouf, rez-de-chaussée"),
    shopAddress: L("Zahle, Berdawni", "زحلة، البردوني", "Zahlé, Berdawni"),
    items: [
      [1, 14.0, L("Kafta", "كفتة", "Kafta"), L("1 kg", "1 كغ", "1 kg")],
      [1, 9.0, L("Chicken breast", "صدر دجاج", "Blanc de poulet"), L("1 kg", "1 كغ", "1 kg")],
    ],
  },
  {
    id: "clothing", icon: "👗", menu: "catalogue", kind: "days", prep: [1, 2], fee: 3,
    label: L("Clothing & shoes", "ألبسة وأحذية", "Vêtements"),
    shop: L("Boutique Lina", "بوتيك لينا", "Boutique Lina"),
    town: L("Hamra", "الحمرا", "Hamra"),
    address: L("Makdessi street, building Saab, 5th floor", "شارع المقدسي، بناية صعب، الطابق الخامس", "Rue Makdessi, immeuble Saab, 5e étage"),
    shopAddress: L("Beirut, Hamra street", "بيروت، شارع الحمرا", "Beyrouth, rue Hamra"),
    items: [
      [1, 35.0, L("Black jeans", "جينز أسود", "Jean noir"), L("38", "38", "38")],
      [1, 18.0, L("White t-shirt", "تيشرت أبيض", "T-shirt blanc"), L("M", "M", "M")],
    ],
  },
  {
    id: "electronics", icon: "📱", menu: "catalogue", kind: "days", prep: [1, 2], fee: 3,
    label: L("Electronics & phones", "إلكترونيات وخلوي", "Électronique"),
    shop: L("Phone City", "فون سيتي", "Phone City"),
    town: L("Dekwaneh", "الدكوانة", "Dekwaneh"),
    address: L("Main road, building Saliba, 2nd floor", "الطريق العام، بناية صليبا، الطابق الثاني", "Route principale, immeuble Saliba, 2e étage"),
    shopAddress: L("Dora, highway", "الدورة، الأوتوستراد", "Dora, autoroute"),
    items: [
      [1, 25.0, L("Fast charger 25W", "شاحن سريع 25W", "Chargeur rapide 25W")],
      [1, 8.0, L("iPhone 15 case", "كفر iPhone 15", "Coque iPhone 15"), L("Black", "أسود", "Noir")],
    ],
  },
  {
    id: "flowers", icon: "💐", menu: "products", kind: "hours", prep: 1, travel: 30, fee: 2,
    label: L("Flowers & gifts", "ورد وهدايا", "Fleurs et cadeaux"),
    shop: L("Fleurs de Lys", "Fleurs de Lys", "Fleurs de Lys"),
    town: L("Batroun", "البترون", "Batroun"),
    address: L("Old souk, building Rahme, 1st floor", "السوق القديم، بناية رحمة، الطابق الأول", "Vieux souk, immeuble Rahmé, 1er étage"),
    shopAddress: L("Batroun, old souk", "البترون، السوق القديم", "Batroun, vieux souk"),
    items: [
      [1, 40.0, L("Red roses bouquet", "بوكيه ورد أحمر", "Bouquet de roses rouges"), L("20 roses", "20 وردة", "20 roses")],
      [1, 12.0, L("Card and chocolates", "كرت وشوكولا", "Carte et chocolats")],
    ],
  },
  {
    id: "water", icon: "💧", menu: "products", kind: "hours", prep: 1, travel: 30, fee: 1,
    label: L("Water & gas", "مي وغاز", "Eau et gaz"),
    shop: L("Abou Fadi Gas", "غاز أبو فادي", "Abou Fadi Gaz"),
    town: L("Aley", "عاليه", "Aley"),
    address: L("Main road, building Haidar, 3rd floor", "الطريق العام، بناية حيدر، الطابق الثالث", "Route principale, immeuble Haidar, 3e étage"),
    shopAddress: L("Aley, main road", "عاليه، الطريق العام", "Aley, route principale"),
    items: [
      [2, 3.0, L("Water gallon 18.9 L", "غالون مي 18.9 ل", "Bonbonne d'eau 18,9 L")],
      [1, 15.0, L("Gas cylinder 10 kg", "قنينة غاز 10 كغ", "Bouteille de gaz 10 kg")],
    ],
  },
  {
    id: "laundry", icon: "👔", menu: "price_list", kind: "days", prep: [2, 2], fee: 2,
    label: L("Laundry", "مصبغة", "Pressing"),
    shop: L("Clean Express", "كلين إكسبرس", "Clean Express"),
    town: L("Baabda", "بعبدا", "Baabda"),
    address: L("Main street, building Aoun, 2nd floor", "الشارع العام، بناية عون، الطابق الثاني", "Rue principale, immeuble Aoun, 2e étage"),
    shopAddress: L("Baabda, main street", "بعبدا، الشارع العام", "Baabda, rue principale"),
    items: [
      [3, 2.0, L("Shirt", "قميص", "Chemise"), L("Wash & iron", "غسيل وكوي", "Lavage et repassage")],
      [1, 8.0, L("Suit", "بدلة", "Costume"), L("Dry cleaning", "تنظيف ناشف", "Nettoyage à sec")],
    ],
  },
  {
    id: "salon", icon: "💇", menu: "services", kind: "booking",
    label: { en: "Salon & beauty", ar: "صالون وتجميل", fr: "Salon et beauté" },
    shop: { en: "Beauty Corner", ar: "Beauty Corner", fr: "Beauty Corner" },
    town: { en: "Jounieh", ar: "جونيه", fr: "Jounieh" },
    address: { en: "Fouad Chehab street, building Nassar, 4th floor", ar: "شارع فؤاد شهاب، بناية نصار، الطابق الرابع", fr: "Rue Fouad Chehab, immeuble Nassar, 4e étage" },
    shopAddress: { en: "Jounieh, old souk", ar: "جونيه، السوق القديم", fr: "Jounieh, vieux souk" },
    booking: { service: L("Layered haircut", "قصة layers", "Coupe dégradée"), price: 25, slots: ["14:00", "15:30", "16:30", "17:30"], time: "16:30" },
  },
  {
    id: "pets", icon: "🐾", menu: "catalogue", kind: "days", prep: [1, 2], fee: 3,
    label: L("Pet shop", "محل حيوانات", "Animalerie"),
    shop: L("Pet Corner", "Pet Corner", "Pet Corner"),
    town: L("Mansourieh", "المنصورية", "Mansourieh"),
    address: L("Main road, building Abboud, 4th floor", "الطريق العام، بناية عبود، الطابق الرابع", "Route principale, immeuble Abboud, 4e étage"),
    shopAddress: L("Mansourieh, main road", "المنصورية، الطريق العام", "Mansourieh, route principale"),
    items: [
      [1, 28.0, L("Cat food 4 kg", "أكل قطط 4 كغ", "Croquettes chat 4 kg")],
      [1, 9.0, L("Cat litter 10 L", "رمل قطط 10 ل", "Litière 10 L")],
    ],
  },
  {
    id: "home", icon: "🛋", menu: "catalogue", kind: "days", prep: [2, 4], fee: 5,
    label: L("Home & furniture", "مفروشات", "Maison et meubles"),
    shop: L("Casa Deco", "كازا ديكو", "Casa Deco"),
    town: L("Kaslik", "الكسليك", "Kaslik"),
    address: L("Main road, building Feghali, 6th floor", "الطريق العام، بناية فغالي، الطابق السادس", "Route principale, immeuble Feghali, 6e étage"),
    shopAddress: L("Kaslik, main road", "الكسليك، الطريق العام", "Kaslik, route principale"),
    items: [
      [4, 12.0, L("Cushion", "مخدة كنباية", "Coussin"), L("Beige", "بيج", "Beige")],
      [1, 60.0, L("Table lamp", "لمبة طاولة", "Lampe de table")],
    ],
  },
  {
    id: "retail", icon: "🛍", menu: "catalogue", kind: "days", prep: [1, 3], fee: 3,
    label: L("General retail", "محل عام", "Commerce"),
    shop: L("Bazar Rizk", "بازار رزق", "Bazar Rizk"),
    town: L("Saida", "صيدا", "Saïda"),
    address: L("Riad Al Solh street, building Bizri, 2nd floor", "شارع رياض الصلح، بناية البزري، الطابق الثاني", "Rue Riad Al Solh, immeuble Bizri, 2e étage"),
    shopAddress: L("Saida, old souk", "صيدا، السوق القديم", "Saïda, vieux souk"),
    items: [
      [1, 9.0, L("Umbrella", "شمسية", "Parapluie")],
      [2, 4.0, L("A4 notebook", "دفتر A4", "Cahier A4")],
    ],
  },
];

// The bot's own texts (backend/lang/*/whatsapp.php), plus what the customer, the owner and the
// driver type. #128 is the order number, +961 71 234 567 the customer, +961 70 111 222 the driver.
const T = {
  en: {
    hi: "Hi 👋", seeYou: "See you there 👍", driver: "Karim",
    welcome: (shop, book) => `Hi! 👋 Welcome to ${shop}.\n${book ? "Book right here: tap Services" : "Order right here: tap the menu"}, or just type or send a voice note 🎤`,
    sure: (lines) => `Sure! 🙂\n${lines}\nAnything else?`,
    viewMenu: "📋 View menu", checkout: "🛒 Checkout",
    how: "How would you like to get your order?", btnDelivery: "🛵 Delivery", btnPickup: "🏪 Pickup",
    location: "📍 Tap the button to send your location, or type your address (street, building, floor).",
    pay: "How would you like to pay?", btnCash: "💵 Cash on delivery", btnCashShop: "💵 Cash at the shop",
    currency: (usd, lbp) => `Total: ${usd} = ${lbp}\nPay cash in dollars, in lira, or both?`, btnSplit: "🔀 $ + LBP",
    splitAsk: "How many dollars will you pay? Type the amount (for example 5). The rest is paid in lira at the shop's rate.", split: (p) => ` — you pay ${p}`,
    estDaysPickup: (r) => `Ready for pickup in ${r} working days`, yourOrder: "Your order", subtotal: "Subtotal", deliveryFee: (zone) => `Delivery (${zone})`, total: "Total",
    pickupAt: (address) => `Pickup at ${address}`, cash: "Cash on delivery", cashShop: "Cash at the shop",
    btnConfirm: "✅ Confirm order", btnEdit: "✏️ Edit order", btnCancel: "❌ Cancel",
    estArrival: (d) => `At your door in ${d}`, estReady: (d) => `Ready in ${d}`, estDays: (r) => `Delivered in ${r} working days`,
    aboutMins: (n) => `about ${n} min`, aboutHours: (n) => `about ${n} hours`,
    minsShort: (n) => `${n} min`, hoursShort: (n) => `${n} h`, daysShort: (r) => `${r} days`,
    arriving: (t) => `arriving around ${t}`, readyAround: (t) => `ready for pickup around ${t}`,
    byDate: (d) => `delivered by ${d}`, readyByDate: (d) => `ready for pickup on ${d}`,
    placed: (shop, total) => `🧾 Order #128 sent to ${shop}.\nTotal: ${total}\nYou will get a message as soon as the shop accepts it.`,
    whishAfter: "💳 You will get the Whish payment link here as soon as the shop accepts your order.",
    newOrder: (shop, pickup) => `🔔 New ${pickup ? "PICKUP " : ""}order #128 — ${shop}`, totalLine: (t, p) => `Total: ${t} (${p})`,
    payWhish: "💳 Whish", payCash: "💵 cash",
    accept: (t) => `✅ Accept · ${t}`, reject: "❌ Can't take it",
    awaiting: "👍 Order #128 accepted — the customer got the Whish link. You will get the order here again as soon as it is paid. Don't prepare it before.",
    whishAccepted: (shop, amount) => `✅ ${shop} accepted your order #128! Pay ${amount} to their Whish number, and write 128 in the note. Tap "I paid" when done. We start once ${shop} confirms the payment.`,
    whishPaid: (when) => `✅ Payment received! Order #128 is confirmed — ${when}.`,
    confirmed: (shop, when) => `✅ ${shop} accepted your order #128 — ${when}.`,
    paidGo: "✅ Order #128 is paid with Whish — prepare it now.", confirmedGo: "✅ Order #128 is confirmed — prepare it now.",
    pickupNote: "🏪 The customer picks it up at the shop.", driverTakes: (d) => `🛵 ${d} will deliver it.`,
    btnOut: "🛵 Out for delivery", btnDelivered: "✅ Delivered", btnReady: "✅ Ready for pickup", btnCollected: "📦 Collected", btnDelay: "📅 Delay 1 day",
    done: "👍 Order #128 updated.", dueToday: "📦 Order #128 is due today",
    drvNew: (shop) => `🛵 New delivery #128 — ${shop}`, customer: "📞 Customer: +961 71 234 567",
    drvWay: "🛵 Delivery #128 — on the way", drvTap: "Tap “I'm outside” when you arrive.",
    btnPickedUp: "🚀 Picked up", btnOutside: "📍 I'm outside", told: "👍 The customer was told you are outside.", drvDone: "✅ Delivery #128 completed. Thanks!",
    collectCash: (a) => `💵 Collect: ${a} (cash)`, paidWhish: "💳 Paid with Whish — collect nothing from the customer",
    onWay: (d, when) => `🛵 Your order #128 is on its way with ${d} — ${when}.`, outside: (d) => `📍 ${d} is outside with your order #128! 📞 +961 70 111 222`,
    delivered: (shop) => `✅ Your order #128 was delivered. Thank you for ordering from ${shop}!`, outForDelivery: "🛵 Your order #128 is on its way!",
    readyPickup: (a) => `📦 Your order #128 is ready! You can pick it up now at ${a}.`, collected: (shop) => `✅ Thank you for picking up order #128 from ${shop}!`,
    rateAsk: "⭐ How was it? Reply with a number from 1 (bad) to 5 (excellent).", rateThanks: "Thank you for your rating! 🙏",
    slots: (s, d, list) => `🗓 Free times for ${s} on ${d}:\n${list}\nWhich time suits you?`,
    booked: (s, d, t, p, shop, a) => `✅ Booked: ${s} on ${d} at ${t} (${p}) at ${shop} — ${a}.\nWe'll send you a reminder. Reply "cancel appointment" if you can't make it.`,
    staffBooking: (shop, s, d, t, p) => `🗓 New booking — ${shop}\n${s}\n${d} at ${t} · ${p}\n📞 +961 71 234 567`,
    reminder: (s, shop, d, t) => `⏰ Reminder: your ${s} at ${shop} is on ${d} at ${t}. See you soon!`,
    btnDone: "✅ Done", btnNoShow: "🚫 No-show", staffUpdated: (s) => `👍 ${s} updated.`,
  },
  ar: {
    hi: "مرحبا 👋", seeYou: "منشوفكن 👍", driver: "كريم",
    welcome: (shop, book) => `أهلا! 👋 أهلا وسهلا بـ ${shop}.\n${book ? "احجز من هون: كبس على الخدمات" : "اطلب من هون: كبس على القائمة"}، أو اكتب أو ابعت رسالة صوتية 🎤`,
    sure: (lines) => `أكيد! 🙂\n${lines}\nبدك شي تاني؟`,
    viewMenu: "📋 عرض القائمة", checkout: "🛒 إتمام الطلب",
    how: "كيف تريد استلام طلبك؟", btnDelivery: "🛵 توصيل", btnPickup: "🏪 استلام من المحل",
    location: "📍 اضغط الزر لإرسال موقعك، أو اكتب عنوانك (الشارع، البناية، الطابق).",
    pay: "كيف تريد أن تدفع؟", btnCash: "💵 نقدًا عند التسليم", btnCashShop: "💵 نقدًا في المحل",
    currency: (usd, lbp) => `المجموع: ${usd} = ${lbp}\nتدفع نقدًا بالدولار أم بالليرة أم بالاثنين؟`, btnSplit: "🔀 دولار + ليرة",
    splitAsk: "كم دولارًا ستدفع؟ اكتب المبلغ (مثلًا 5)، والباقي تدفعه بالليرة حسب سعر صرف المحل.", split: (p) => ` — تدفع ${p}`,
    estDaysPickup: (r) => `جاهز للاستلام خلال ${r} أيام عمل`, yourOrder: "طلبك", subtotal: "المجموع", deliveryFee: (zone) => `التوصيل (${zone})`, total: "الإجمالي",
    pickupAt: (address) => `الاستلام من ${address}`, cash: "نقدًا عند التسليم", cashShop: "نقدًا في المحل",
    btnConfirm: "✅ تأكيد الطلب", btnEdit: "✏️ تعديل الطلب", btnCancel: "❌ إلغاء",
    estArrival: (d) => `يصل إليك خلال ${d}`, estReady: (d) => `جاهز خلال ${d}`, estDays: (r) => `التوصيل خلال ${r} أيام عمل`,
    aboutMins: (n) => `حوالي ${n} دقيقة`, aboutHours: (n) => `حوالي ${n} ساعات`,
    minsShort: (n) => `${n} د`, hoursShort: (n) => `${n} س`, daysShort: (r) => `${r} أيام`,
    arriving: (t) => `يصل حوالي ${t}`, readyAround: (t) => `جاهز للاستلام حوالي ${t}`,
    byDate: (d) => `يصل قبل ${d}`, readyByDate: (d) => `جاهز للاستلام يوم ${d}`,
    placed: (shop, total) => `🧾 تم إرسال الطلب رقم 128 إلى ${shop}.\nالمجموع: ${total}\nستصلك رسالة فور قبول المحل للطلب.`,
    whishAfter: "💳 سيصلك رابط الدفع عبر Whish هنا فور قبول المحل لطلبك.",
    newOrder: (shop, pickup) => `🔔 ${pickup ? "طلب استلام جديد" : "طلب جديد"} رقم 128 — ${shop}`, totalLine: (t, p) => `المجموع: ${t} (${p})`,
    payWhish: "💳 Whish", payCash: "💵 كاش",
    accept: (t) => `✅ قبول · ${t}`, reject: "❌ لا يمكن",
    awaiting: "👍 تم قبول الطلب رقم 128 — وصل الزبون رابط Whish. سيصلك الطلب هنا مرة أخرى فور دفعه. لا تحضّره قبل ذلك.",
    whishAccepted: (shop, amount) => `✅ قبل ${shop} طلبك رقم 128! ادفع ${amount} إلى رقم Whish الخاص بالمحل، واكتب 128 في الملاحظة. اضغط «دفعت» عند الانتهاء. نبدأ فور تأكيد ${shop} للدفعة.`,
    whishPaid: (when) => `✅ تم استلام الدفع! الطلب رقم 128 مؤكد — ${when}.`,
    confirmed: (shop, when) => `✅ قبل ${shop} طلبك رقم 128 — ${when}.`,
    paidGo: "✅ الطلب رقم 128 مدفوع عبر Whish — حضّره الآن.", confirmedGo: "✅ الطلب رقم 128 مؤكد — حضّره الآن.",
    pickupNote: "🏪 الزبون سيستلمه من المحل.", driverTakes: (d) => `🛵 ${d} سيوصله.`,
    btnOut: "🛵 خرج للتوصيل", btnDelivered: "✅ تم التوصيل", btnReady: "✅ جاهز للاستلام", btnCollected: "📦 تم الاستلام", btnDelay: "📅 تأجيل يوم",
    done: "👍 تم تحديث الطلب رقم 128.", dueToday: "📦 الطلب رقم 128 موعده اليوم",
    drvNew: (shop) => `🛵 توصيل جديد رقم 128 — ${shop}`, customer: "📞 الزبون: +961 71 234 567",
    drvWay: "🛵 التوصيل رقم 128 — في الطريق", drvTap: "اضغط «أنا في الخارج» عند الوصول.",
    btnPickedUp: "🚀 تم الاستلام", btnOutside: "📍 أنا في الخارج", told: "👍 تم إبلاغ الزبون أنك في الخارج.", drvDone: "✅ تم التوصيل رقم 128. شكراً!",
    collectCash: (a) => `💵 المبلغ: ${a} (كاش)`, paidWhish: "💳 مدفوع عبر Whish — لا تأخذ أي مبلغ من الزبون",
    onWay: (d, when) => `🛵 طلبك رقم 128 في الطريق مع ${d} — ${when}.`, outside: (d) => `📍 ${d} في الخارج مع طلبك رقم 128! 📞 +961 70 111 222`,
    delivered: (shop) => `✅ تم توصيل طلبك رقم 128. شكراً لطلبك من ${shop}!`, outForDelivery: "🛵 طلبك رقم 128 في الطريق إليك!",
    readyPickup: (a) => `📦 طلبك رقم 128 جاهز! يمكنك استلامه الآن من ${a}.`, collected: (shop) => `✅ شكراً لاستلامك الطلب رقم 128 من ${shop}!`,
    rateAsk: "⭐ كيف كان طلبك؟ أجب برقم من 1 (سيء) إلى 5 (ممتاز).", rateThanks: "شكراً على تقييمك! 🙏",
    slots: (s, d, list) => `🗓 الأوقات المتاحة لـ ${s} يوم ${d}:\n${list}\nأي وقت يناسبك؟`,
    booked: (s, d, t, p, shop, a) => `✅ تم الحجز: ${s} يوم ${d} الساعة ${t} (${p}) في ${shop} — ${a}.\nسنرسل لك تذكيراً. أرسل «إلغاء الموعد» إذا لم تتمكن من الحضور.`,
    staffBooking: (shop, s, d, t, p) => `🗓 حجز جديد — ${shop}\n${s}\n${d} الساعة ${t} · ${p}\n📞 +961 71 234 567`,
    reminder: (s, shop, d, t) => `⏰ تذكير: موعد ${s} في ${shop} يوم ${d} الساعة ${t}. نراك قريباً!`,
    btnDone: "✅ تم", btnNoShow: "🚫 لم يحضر", staffUpdated: (s) => `👍 تم تحديث ${s}.`,
  },
  fr: {
    hi: "Bonjour 👋", seeYou: "À tout à l'heure 👍", driver: "Karim",
    welcome: (shop, book) => `Bonjour ! 👋 Bienvenue chez ${shop}.\n${book ? "Réservez ici : touchez Services" : "Commandez ici : touchez le menu"}, écrivez ou envoyez un vocal 🎤`,
    sure: (lines) => `Bien sûr ! 🙂\n${lines}\nAutre chose ?`,
    viewMenu: "📋 Voir le menu", checkout: "🛒 Commander",
    how: "Comment souhaitez-vous recevoir votre commande ?", btnDelivery: "🛵 Livraison", btnPickup: "🏪 À emporter",
    location: "📍 Touchez le bouton pour envoyer votre position, ou écrivez votre adresse (rue, immeuble, étage).",
    pay: "Comment souhaitez-vous payer ?", btnCash: "💵 À la livraison", btnCashShop: "💵 Au commerce",
    currency: (usd, lbp) => `Total : ${usd} = ${lbp}\nPayer en dollars, en livres, ou les deux ?`, btnSplit: "🔀 $ + livres",
    splitAsk: "Combien de dollars payez-vous ? Écrivez le montant (par exemple 5), le reste en livres au taux du commerce.", split: (p) => ` — vous payez ${p}`,
    estDaysPickup: (r) => `Prête à emporter sous ${r} jours ouvrés`, yourOrder: "Votre commande", subtotal: "Sous-total", deliveryFee: (zone) => `Livraison (${zone})`, total: "Total",
    pickupAt: (address) => `Retrait : ${address}`, cash: "Espèces à la livraison", cashShop: "Espèces au commerce",
    btnConfirm: "✅ Confirmer", btnEdit: "✏️ Modifier", btnCancel: "❌ Annuler",
    estArrival: (d) => `Chez vous dans ${d}`, estReady: (d) => `Prête dans ${d}`, estDays: (r) => `Livrée sous ${r} jours ouvrés`,
    aboutMins: (n) => `environ ${n} min`, aboutHours: (n) => `environ ${n} heures`,
    minsShort: (n) => `${n} min`, hoursShort: (n) => `${n} h`, daysShort: (r) => `${r} j`,
    arriving: (t) => `arrivée vers ${t}`, readyAround: (t) => `prête à emporter vers ${t}`,
    byDate: (d) => `livrée d'ici ${d}`, readyByDate: (d) => `prête à emporter le ${d}`,
    placed: (shop, total) => `🧾 Commande n° 128 envoyée à ${shop}.\nTotal : ${total}\nVous recevrez un message dès que le commerce l'accepte.`,
    whishAfter: "💳 Vous recevrez ici le lien de paiement Whish dès que le commerce aura accepté votre commande.",
    newOrder: (shop, pickup) => `🔔 Nouvelle commande ${pickup ? "À EMPORTER " : ""}n° 128 — ${shop}`, totalLine: (t, p) => `Total : ${t} (${p})`,
    payWhish: "💳 Whish", payCash: "💵 espèces",
    accept: (t) => `✅ Accepter · ${t}`, reject: "❌ Impossible",
    awaiting: "👍 Commande n° 128 acceptée — le client a reçu le lien Whish. Vous recevrez la commande ici dès qu'elle est payée. Ne la préparez pas avant.",
    whishAccepted: (shop, amount) => `✅ ${shop} a accepté votre commande n° 128 ! Payez ${amount} au numéro Whish du commerce, en écrivant 128 dans la note. Appuyez sur « J'ai payé » ensuite. Nous commençons dès que ${shop} confirme le paiement.`,
    whishPaid: (when) => `✅ Paiement reçu ! Commande n° 128 confirmée — ${when}.`,
    confirmed: (shop, when) => `✅ ${shop} a accepté votre commande n° 128 — ${when}.`,
    paidGo: "✅ La commande n° 128 est payée avec Whish — préparez-la.", confirmedGo: "✅ La commande n° 128 est confirmée — préparez-la.",
    pickupNote: "🏪 Le client la récupère au magasin.", driverTakes: (d) => `🛵 ${d} va la livrer.`,
    btnOut: "🛵 En livraison", btnDelivered: "✅ Livrée", btnReady: "✅ Prête", btnCollected: "📦 Récupérée", btnDelay: "📅 Reporter d'1 jour",
    done: "👍 Commande n° 128 mise à jour.", dueToday: "📦 La commande n° 128 est prévue aujourd'hui",
    drvNew: (shop) => `🛵 Nouvelle livraison n° 128 — ${shop}`, customer: "📞 Client : +961 71 234 567",
    drvWay: "🛵 Livraison n° 128 — en route", drvTap: "Touchez « Je suis devant » en arrivant.",
    btnPickedUp: "🚀 Récupérée", btnOutside: "📍 Je suis devant", told: "👍 Le client a été prévenu que vous êtes devant.", drvDone: "✅ Livraison n° 128 terminée. Merci !",
    collectCash: (a) => `💵 À encaisser : ${a} (espèces)`, paidWhish: "💳 Payé avec Whish — rien à encaisser auprès du client",
    onWay: (d, when) => `🛵 Votre commande n° 128 est en route avec ${d} — ${when}.`, outside: (d) => `📍 ${d} est devant chez vous avec la commande n° 128 ! 📞 +961 70 111 222`,
    delivered: (shop) => `✅ Votre commande n° 128 a été livrée. Merci d'avoir commandé chez ${shop} !`, outForDelivery: "🛵 Votre commande n° 128 est en route !",
    readyPickup: (a) => `📦 Votre commande n° 128 est prête ! Vous pouvez la récupérer à ${a}.`, collected: (shop) => `✅ Merci d'avoir récupéré la commande n° 128 chez ${shop} !`,
    rateAsk: "⭐ Qu’en avez-vous pensé ? Répondez avec un chiffre de 1 (mauvais) à 5 (excellent).", rateThanks: "Merci pour votre note ! 🙏",
    slots: (s, d, list) => `🗓 Créneaux libres pour ${s} le ${d} :\n${list}\nQuelle heure vous convient ?`,
    booked: (s, d, t, p, shop, a) => `✅ Réservé : ${s} le ${d} à ${t} (${p}) chez ${shop} — ${a}.\nNous vous enverrons un rappel. Répondez « annuler le rendez-vous » si vous ne pouvez pas venir.`,
    staffBooking: (shop, s, d, t, p) => `🗓 Nouvelle réservation — ${shop}\n${s}\n${d} à ${t} · ${p}\n📞 +961 71 234 567`,
    reminder: (s, shop, d, t) => `⏰ Rappel : votre ${s} chez ${shop} est le ${d} à ${t}. À bientôt !`,
    btnDone: "✅ Terminé", btnNoShow: "🚫 Absent", staffUpdated: (s) => `👍 ${s} mis à jour.`,
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

  if (shop.kind === "booking") {
    const { service: s, price, slots, time } = shop.booking;
    const service = name(s);
    const day = workingDay(lang, 1);
    const [h] = time.split(":").map(Number);
    return [
      ["18:05", "book", [
        ["C", t.hi],
        ["c", t.welcome(shopName, true), { buttons: [UI[lang].menuButtons.services] }],
        ["C", "", { voice: "0:05" }],
        ["c", t.slots(service, day, slots.join(" · "))],
        ["C", time],
        ["c", t.booked(service, day, time, money(lang, price), shopName, shopAddress)],
        ["s", t.staffBooking(shopName, service, day, time, money(lang, price)), { buttons: [t.btnDone, t.btnNoShow] }],
      ]],
      [`${String(h - 2).padStart(2, "0")}:00`, "remind", [
        ["c", t.reminder(service, shopName, day, time), { day }],
        ["C", t.seeYou],
      ]],
      [`${String(h + 1).padStart(2, "0")}:00`, "visit", [
        ["S", t.btnDone],
        ["s", t.staffUpdated(service)],
      ]],
    ];
  }

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
  const bullets = shop.items.map((i) => `• ${item(i)}`).join("\n");
  const payTag = whish ? t.payWhish : t.payCash;
  const cashButton = delivery ? t.btnCash : t.btnCashShop;
  const mins = prepMins + (delivery ? shop.travel : 0);
  const duration = mins >= 90 ? t.aboutHours(Math.round(mins / 6) / 10) : t.aboutMins(mins);
  const estimate = days ? (delivery ? t.estDays(range) : t.estDaysPickup(range)) : delivery ? t.estArrival(duration) : t.estReady(duration);
  // Cash is paid split: round dollars, the rest in lira at the shop's rate.
  const dollars = [Math.floor(total / 10) * 10, Math.floor(total), total - 5].find((d) => d > 0 && d < total);
  const paid = whish ? money(lang, total) : `${money(lang, dollars)} + ${lira(lang, total - dollars)}`;
  const colon = lang === "fr" ? " : " : ": "; // French puts a space before the colon
  const receipt = `🧾 *${t.yourOrder}*\n${lines}\n\n${t.subtotal}${colon}${money(lang, subtotal)}\n`
    + (delivery ? `${t.deliveryFee(town)}${colon}${money(lang, shop.fee)}\n` : "")
    + `*${t.total}${colon}${money(lang, total)}*${whish ? "" : ` (${lira(lang, total)})`}\n\n`
    + (delivery ? `🛵 ${address}` : `🏪 ${t.pickupAt(shopAddress)}`)
    + `\n${whish ? "💳 Whish" : `💵 ${delivery ? t.cash : t.cashShop} · ${paid}`}\n⏱ ${estimate}`;

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
  const where = delivery ? `📍 ${address}\n📞 +961 71 234 567` : "📞 +961 71 234 567";
  const ticket = (head) => `${head}\n${bullets}\n${t.totalLine(paid, payTag)}\n${where}`;
  const goButtons = delivery ? [t.btnOut, t.btnDelivered] : [t.btnReady, t.btnCollected];
  const goAhead = (head) => [
    "s", `${ticket(head)}${driver ? `\n${t.driverTakes(t.driver)}` : delivery ? "" : `\n${t.pickupNote}`}`, { buttons: goButtons },
  ];
  const collect = whish ? t.paidWhish : t.collectCash(paid);
  const assigned = ["d", `${t.drvNew(shopName)}\n${bullets}\n${collect}\n📍 ${address}\n${t.customer}`, { buttons: [t.btnPickedUp, t.btnDelivered] }];

  // The order leaves the shop (or is ready), then reaches the customer (or is collected) later.
  const dueTicket = ["s", `${t.dueToday}\n${bullets}\n${t.totalLine(paid, payTag)}\n${where}`, { buttons: [goButtons[0], t.btnDelay], day: dueDay }];
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
      ["c", t.delivered(shopName)],
    ]]
    : delivery
      ? [[["S", t.btnOut], ["s", t.done], ["c", t.outForDelivery]], [["S", t.btnDelivered], ["s", t.done], ["c", t.delivered(shopName)]]]
      : [[["S", t.btnReady], ["s", t.done], ["c", t.readyPickup(shopAddress)]], [["S", t.btnCollected], ["s", t.done], ["c", t.collected(shopName)]]];
  // Shops counting in days: the order comes back to the shop on its day (10:00), arrives at 11:30.
  const [leaveAt, arriveAt] = days ? [10 * 60, 11 * 60 + 30] : driver ? [ready, arrive - 1] : [ready, ready + 15];

  return [
    ["12:30", "order", [
      ["C", t.hi],
      ["c", t.welcome(shopName, false), { buttons: [UI[lang].menuButtons[shop.menu]] }],
      ["C", "", { voice: "0:07" }],
      ["c", t.sure(lines), { buttons: [t.viewMenu, t.checkout] }],
      ["C", t.checkout],
      ...(delivery ? [
        ["c", t.how, { buttons: [t.btnDelivery, t.btnPickup] }],
        ["C", t.btnDelivery],
        ["c", t.location],
        ["C", name(shop.address)],
      ] : []),
      ["c", t.pay, { buttons: [cashButton, "💳 Whish"] }],
      ["C", whish ? "💳 Whish" : cashButton],
      ...(whish ? [] : [
        ["c", t.currency(money(lang, total), lira(lang, total)), { buttons: [`💵 ${money(lang, total)}`, `🇱🇧 ${lira(lang, total)}`, t.btnSplit] }],
        ["C", t.btnSplit],
        ["c", t.splitAsk],
        ["C", String(dollars)],
      ]),
      ["c", receipt, { buttons: [t.btnConfirm, t.btnEdit, t.btnCancel] }],
      ["C", t.btnConfirm],
      ["c", t.placed(shopName, money(lang, total) + (whish ? "" : t.split(paid))) + (whish ? `\n${t.whishAfter}` : "")],
    ]],
    [at(accepted), "accept", [
      ["s", ticket(t.newOrder(shopName, !delivery)), { buttons: [t.accept(label), t.reject] }],
      ["S", t.accept(label)],
      ...(whish
        ? [["s", t.awaiting], ["c", t.whishAccepted(shopName, money(lang, total))]]
        : [["c", t.confirmed(shopName, when)], goAhead(t.confirmedGo), ...(driver ? [assigned] : [])]),
    ]],
    ...(whish ? [[at(go), "whish", [
      ["c", t.whishPaid(when)],
      goAhead(t.paidGo),
      ...(driver ? [assigned] : []),
    ]]] : []),
    [at(leaveAt), delivery ? "delivery" : "pickup", days ? [dueTicket, ...leaves] : leaves],
    [at(arriveAt), delivery ? "delivery" : "pickup", arrives],
    [at(arriveAt + 20), "rating", [
      ["c", t.rateAsk],
      ["C", "5"],
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
// The demo offers four families of shops; each plays one example and names the shops it stands for.
const FAMILIES = [
  { id: "food", icon: "🍽", label: L("Food", "أكل", "Restauration"), example: "restaurant", members: ["restaurant", "bakery", "butcher"] },
  { id: "shops", icon: "🛒", label: L("Shops", "محلات", "Boutiques"), example: "market", members: ["market", "clothing", "electronics", "home", "pets", "retail"] },
  { id: "delivery", icon: "💐", label: L("Gifts & delivery", "هدايا وتوصيل", "Cadeaux et livraison"), example: "flowers", members: ["flowers", "water"] },
  { id: "services", icon: "💇", label: L("Services", "خدمات", "Services"), example: "salon", members: ["salon", "laundry"] },
];

export function orderDemo(lang) {
  const key = UI[lang] ? lang : "en";
  return {
    ...UI[key],
    scenarios: FAMILIES.map((family) => {
      const shop = SHOPS.find((s) => s.id === family.example);
      const members = family.members.map((id) => SHOPS.find((s) => s.id === id).label[key]).join(" · ");
      const base = { id: family.id, icon: family.icon, label: family.label[key], members };
      // Salons take bookings; every other shop takes orders, delivered or picked up.
      if (shop.kind === "booking") return { ...base, defaultMode: "pickup", booking: build(shop, key, false, false) };
      return {
        ...base,
        defaultMode: "delivery",
        delivery: { whish: build(shop, key, true, true), cash: build(shop, key, true, false) },
        pickup: { whish: build(shop, key, false, true), cash: build(shop, key, false, false) },
      };
    }),
  };
}
