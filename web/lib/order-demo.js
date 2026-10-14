// The homepage demo: real NeoTalab conversations for several kinds of shops, on the
// phones of the customer, the shop owner and the driver. Every shop goes through the same
// features (SCRIPT below) with its own products; the messages follow the real bot texts.
//
// A message is [chat, text, extras]:
//   lower-case chat = sent by the shop's NeoTalab number, upper-case = sent by that phone's owner
//   c / C  customer      s / S  shop owner or staff      d / D  driver
// extras: buttons (WhatsApp reply buttons), voice ("0:07"), only ("delivery" or "pickup"),
// pay ("whish" or "cash").

const UI = {
  en: {
    title: "See NeoTalab at work",
    lead: "Pick a shop and follow one order on the customer's, the owner's and the driver's WhatsApp.",
    online: "online", typing: "typing…", today: "Today", message: "Message",
    modes: { delivery: "🛵 Delivery", pickup: "🏪 Pickup" }, pause: "Pause", play: "Play", replay: "Replay", prev: "Previous step", next: "Next step", prevShop: "Previous shop", nextShop: "Next shop", voice: "Voice message", sendLabel: "Send",
    who: { c: "Customer", s: "Shop owner", d: "Driver" },
    bot: "NeoTalab",
    menuButtons: { menu: "📋 Menu", products: "📋 Products", catalogue: "📋 Catalogue", price_list: "📋 Price list", services: "📋 Services" },
    steps: { order: "Order", accept: "Shop accepts", whish: "Whish payment", delivery: "Delivery", pickup: "Pickup", rating: "Rating" },
    choose: { mode: "How the customer gets it", pay: "How the customer pays" },
    pays: { whish: "💳 Whish", cash: "💵 Cash" },
  },
  ar: {
    title: "شوف NeoTalab عم يشتغل",
    lead: "اختار محل وتابع طلب من أوله لآخره على واتساب الزبون وصاحب المحل والسائق.",
    online: "متصل", typing: "يكتب…", today: "اليوم", message: "مراسلة",
    modes: { delivery: "🛵 توصيل", pickup: "🏪 استلام من المحل" }, pause: "إيقاف", play: "تشغيل", replay: "إعادة", prev: "الخطوة السابقة", next: "الخطوة التالية", prevShop: "المحل السابق", nextShop: "المحل التالي", voice: "رسالة صوتية", sendLabel: "إرسال",
    who: { c: "الزبون", s: "صاحب المحل", d: "السائق" },
    bot: "NeoTalab",
    menuButtons: { menu: "📋 القائمة", products: "📋 المنتجات", catalogue: "📋 الكتالوج", price_list: "📋 لائحة الأسعار", services: "📋 الخدمات" },
    steps: { order: "الطلب", accept: "المحل بيقبل", whish: "دفع Whish", delivery: "التوصيل", pickup: "الاستلام", rating: "التقييم" },
    choose: { mode: "كيف بياخد الزبون طلبه", pay: "كيف بيدفع الزبون" },
    pays: { whish: "💳 Whish", cash: "💵 كاش" },
  },
  fr: {
    title: "NeoTalab en action",
    lead: "Choisissez un commerce et suivez une commande sur le WhatsApp du client, du commerçant et du livreur.",
    online: "en ligne", typing: "écrit…", today: "Aujourd'hui", message: "Message",
    modes: { delivery: "🛵 Livraison", pickup: "🏪 À emporter" }, pause: "Pause", play: "Lecture", replay: "Rejouer", prev: "Étape précédente", next: "Étape suivante", prevShop: "Commerce précédent", nextShop: "Commerce suivant", voice: "Message vocal", sendLabel: "Envoyer",
    who: { c: "Client", s: "Commerçant", d: "Livreur" },
    bot: "NeoTalab",
    menuButtons: { menu: "📋 Menu", products: "📋 Produits", catalogue: "📋 Catalogue", price_list: "📋 Tarifs", services: "📋 Services" },
    steps: { order: "Commande", accept: "Le commerce accepte", whish: "Paiement Whish", delivery: "Livraison", pickup: "Retrait", rating: "Note" },
    choose: { mode: "Comment le client la reçoit", pay: "Comment le client paie" },
    pays: { whish: "💳 Whish", cash: "💵 Espèces" },
  },
};

// What changes from one shop to another. Text is given as { en, ar, fr }.
const L = (en, ar, fr) => ({ en, ar, fr });

const SHOPS = [
  {
    id: "restaurant", icon: "🥙", eta: 25, menu: "menu",
    label: { en: "Restaurant", ar: "مطعم", fr: "Restaurant" },
    shop: { en: "Foren Al Hara", ar: "فرن الحارة", fr: "Foren Al Hara" },
    town: { en: "Jbeil", ar: "جبيل", fr: "Jbeil" },
    address: { en: "Main street, near the church, building Haddad, 2nd floor", ar: "الشارع العام حد الكنيسة، بناية حداد، الطابق الثاني", fr: "Rue principale près de l'église, immeuble Haddad, 2e étage" },
    shopAddress: { en: "Jbeil, main street", ar: "جبيل، الشارع العام", fr: "Jbeil, rue principale" },
    voice: [
      [2, 1.0, { en: "Zaatar man'oushe", ar: "منقوشة زعتر", fr: "Man'oushé zaatar" }],
      [1, 2.5, { en: "Cheese man'oushe", ar: "منقوشة جبنة", fr: "Man'oushé fromage" }],
    ],
  },
  {
    id: "pharmacy", icon: "💊", eta: 40, menu: "products",
    label: { en: "Pharmacy", ar: "صيدلية", fr: "Pharmacie" },
    shop: { en: "Pharmacie Al Shifa", ar: "صيدلية الشفاء", fr: "Pharmacie Al Shifa" },
    town: { en: "Achrafieh", ar: "الأشرفية", fr: "Achrafieh" },
    address: { en: "Sursock street, building 12, 3rd floor", ar: "شارع سرسق، بناية 12، الطابق الثالث", fr: "Rue Sursock, immeuble 12, 3e étage" },
    shopAddress: { en: "Achrafieh, Sassine square", ar: "الأشرفية، ساحة ساسين", fr: "Achrafieh, place Sassine" },
    voice: [
      [1, 4.0, { en: "Panadol Extra", ar: "Panadol Extra", fr: "Panadol Extra" }],
      [1, 6.0, { en: "Vitamin C 1000", ar: "فيتامين C 1000", fr: "Vitamine C 1000" }],
    ],
  },
  {
    id: "salon", icon: "💇", eta: 45, menu: "services", delivery: false,
    label: { en: "Salon & beauty", ar: "صالون وتجميل", fr: "Salon et beauté" },
    shop: { en: "Beauty Corner", ar: "Beauty Corner", fr: "Beauty Corner" },
    town: { en: "Jounieh", ar: "جونيه", fr: "Jounieh" },
    address: { en: "Fouad Chehab street, building Nassar, 4th floor", ar: "شارع فؤاد شهاب، بناية نصار، الطابق الرابع", fr: "Rue Fouad Chehab, immeuble Nassar, 4e étage" },
    shopAddress: { en: "Jounieh, old souk", ar: "جونيه، السوق القديم", fr: "Jounieh, vieux souk" },
    voice: [
      [1, 15.0, { en: "Hair mask", ar: "ماسك للشعر", fr: "Masque capillaire" }],
      [1, 9.0, { en: "Shampoo 400 ml", ar: "شامبو 400 مل", fr: "Shampooing 400 ml" }],
    ],
  },
  {
    id: "market", icon: "🛒", eta: 30, menu: "products",
    label: { en: "Supermarket", ar: "سوبرماركت", fr: "Supérette" },
    shop: { en: "Mini Market Abou Elie", ar: "ميني ماركت أبو إيلي", fr: "Mini Market Abou Elie" },
    town: { en: "Zalka", ar: "الزلقا", fr: "Zalka" },
    address: { en: "Main road, building Khoury, 1st floor", ar: "الطريق العام، بناية خوري، الطابق الأول", fr: "Route principale, immeuble Khoury, 1er étage" },
    shopAddress: { en: "Zalka, main road", ar: "الزلقا، الطريق العام", fr: "Zalka, route principale" },
    voice: [
      [2, 3.5, { en: "Labneh 500 g", ar: "لبنة 500 غ", fr: "Labné 500 g" }],
      [1, 6.5, { en: "Eggs (30)", ar: "بيض (30)", fr: "Œufs (30)" }],
    ],
  },
  {
    id: "bakery", icon: "🥐", eta: 30, menu: "menu",
    label: L("Bakery & sweets", "حلويات وأفران", "Pâtisserie"),
    shop: L("Patisserie Nour", "حلويات نور", "Pâtisserie Nour"),
    town: L("Tripoli", "طرابلس", "Tripoli"),
    address: L("Azmi street, building Karam, 3rd floor", "شارع عزمي، بناية كرم، الطابق الثالث", "Rue Azmi, immeuble Karam, 3e étage"),
    shopAddress: L("Tripoli, Tall square", "طرابلس، ساحة التل", "Tripoli, place du Tall"),
    voice: [
      [1, 12.0, L("Maamoul (1 kg)", "معمول (1 كغ)", "Maamoul (1 kg)")],
      [2, 3.5, L("Knefeh", "كنافة", "Knéfé")],
    ],
  },
  {
    id: "butcher", icon: "🥩", eta: 35, menu: "products",
    label: L("Butcher & produce", "ملحمة وخضرة", "Boucherie et primeur"),
    shop: L("Boucherie Al Wadi", "ملحمة الوادي", "Boucherie Al Wadi"),
    town: L("Zahle", "زحلة", "Zahlé"),
    address: L("Boulevard, building Maalouf, ground floor", "البولفار، بناية معلوف، الطابق الأرضي", "Boulevard, immeuble Maalouf, rez-de-chaussée"),
    shopAddress: L("Zahle, Berdawni", "زحلة، البردوني", "Zahlé, Berdawni"),
    voice: [
      [1, 14.0, L("Kafta (1 kg)", "كفتة (1 كغ)", "Kafta (1 kg)")],
      [1, 9.0, L("Chicken breast (1 kg)", "صدر دجاج (1 كغ)", "Blanc de poulet (1 kg)")],
    ],
  },
  {
    id: "clothing", icon: "👗", eta: 60, menu: "catalogue",
    label: L("Clothing & shoes", "ألبسة وأحذية", "Vêtements"),
    shop: L("Boutique Lina", "بوتيك لينا", "Boutique Lina"),
    town: L("Hamra", "الحمرا", "Hamra"),
    address: L("Makdessi street, building Saab, 5th floor", "شارع المقدسي، بناية صعب، الطابق الخامس", "Rue Makdessi, immeuble Saab, 5e étage"),
    shopAddress: L("Beirut, Hamra street", "بيروت، شارع الحمرا", "Beyrouth, rue Hamra"),
    voice: [
      [1, 35.0, L("Black jeans, size 38", "جينز أسود، قياس 38", "Jean noir, taille 38")],
      [1, 18.0, L("White t-shirt, M", "تيشرت أبيض، M", "T-shirt blanc, M")],
    ],
  },
  {
    id: "electronics", icon: "📱", eta: 45, menu: "catalogue",
    label: L("Electronics & phones", "إلكترونيات وخلوي", "Électronique"),
    shop: L("Phone City", "فون سيتي", "Phone City"),
    town: L("Dekwaneh", "الدكوانة", "Dekwaneh"),
    address: L("Main road, building Saliba, 2nd floor", "الطريق العام، بناية صليبا، الطابق الثاني", "Route principale, immeuble Saliba, 2e étage"),
    shopAddress: L("Dora, highway", "الدورة، الأوتوستراد", "Dora, autoroute"),
    voice: [
      [1, 25.0, L("Fast charger 25W", "شاحن سريع 25W", "Chargeur rapide 25W")],
      [1, 8.0, L("iPhone 15 case", "كفر iPhone 15", "Coque iPhone 15")],
    ],
  },
  {
    id: "flowers", icon: "💐", eta: 60, menu: "products",
    label: L("Flowers & gifts", "ورد وهدايا", "Fleurs et cadeaux"),
    shop: L("Fleurs de Lys", "Fleurs de Lys", "Fleurs de Lys"),
    town: L("Batroun", "البترون", "Batroun"),
    address: L("Old souk, building Rahme, 1st floor", "السوق القديم، بناية رحمة، الطابق الأول", "Vieux souk, immeuble Rahmé, 1er étage"),
    shopAddress: L("Batroun, old souk", "البترون، السوق القديم", "Batroun, vieux souk"),
    voice: [
      [1, 40.0, L("Bouquet of 20 red roses", "بوكيه 20 وردة حمرا", "Bouquet de 20 roses rouges")],
      [1, 12.0, L("Card and chocolates", "كرت وشوكولا", "Carte et chocolats")],
    ],
  },
  {
    id: "water", icon: "💧", eta: 40, menu: "products",
    label: L("Water & gas", "مي وغاز", "Eau et gaz"),
    shop: L("Abou Fadi Gas", "غاز أبو فادي", "Abou Fadi Gaz"),
    town: L("Aley", "عاليه", "Aley"),
    address: L("Main road, building Haidar, 3rd floor", "الطريق العام، بناية حيدر، الطابق الثالث", "Route principale, immeuble Haidar, 3e étage"),
    shopAddress: L("Aley, main road", "عاليه، الطريق العام", "Aley, route principale"),
    voice: [
      [2, 3.0, L("Water gallon 18.9 L", "غالون مي 18.9 ل", "Bonbonne d'eau 18,9 L")],
      [1, 15.0, L("Gas cylinder 10 kg", "قنينة غاز 10 كغ", "Bouteille de gaz 10 kg")],
    ],
  },
  {
    id: "laundry", icon: "👔", eta: 60, menu: "price_list",
    label: L("Laundry", "مصبغة", "Pressing"),
    shop: L("Clean Express", "كلين إكسبرس", "Clean Express"),
    town: L("Baabda", "بعبدا", "Baabda"),
    address: L("Main street, building Aoun, 2nd floor", "الشارع العام، بناية عون، الطابق الثاني", "Rue principale, immeuble Aoun, 2e étage"),
    shopAddress: L("Baabda, main street", "بعبدا، الشارع العام", "Baabda, rue principale"),
    voice: [
      [3, 2.0, L("Shirt, wash and iron", "قميص، غسيل وكوي", "Chemise, lavage et repassage")],
      [1, 8.0, L("Suit, dry cleaning", "بدلة، تنظيف ناشف", "Costume, nettoyage à sec")],
    ],
  },
  {
    id: "clinic", icon: "🦷", eta: 60, menu: "services", delivery: false,
    label: L("Clinic & dentist", "عيادة وطبيب أسنان", "Clinique et dentiste"),
    shop: L("Smile Dental", "سمايل دنتال", "Smile Dental"),
    town: L("Jdeideh", "الجديدة", "Jdeideh"),
    address: L("Main road, building Nasr, 1st floor", "الطريق العام، بناية نصر، الطابق الأول", "Route principale, immeuble Nasr, 1er étage"),
    shopAddress: L("Jdeideh, main road", "الجديدة، الطريق العام", "Jdeideh, route principale"),
    voice: [
      [1, 6.0, L("Whitening toothpaste", "معجون تبييض", "Dentifrice blanchissant")],
      [1, 12.0, L("Toothbrush heads (4)", "رؤوس فرشاية (4)", "Têtes de brosse (4)")],
    ],
  },
  {
    id: "pets", icon: "🐾", eta: 45, menu: "products",
    label: L("Pet shop", "محل حيوانات", "Animalerie"),
    shop: L("Pet Corner", "Pet Corner", "Pet Corner"),
    town: L("Mansourieh", "المنصورية", "Mansourieh"),
    address: L("Main road, building Abboud, 4th floor", "الطريق العام، بناية عبود، الطابق الرابع", "Route principale, immeuble Abboud, 4e étage"),
    shopAddress: L("Mansourieh, main road", "المنصورية، الطريق العام", "Mansourieh, route principale"),
    voice: [
      [1, 28.0, L("Cat food 4 kg", "أكل قطط 4 كغ", "Croquettes chat 4 kg")],
      [1, 9.0, L("Cat litter 10 L", "رمل قطط 10 ل", "Litière 10 L")],
    ],
  },
  {
    id: "home", icon: "🛋", eta: 120, menu: "catalogue",
    label: L("Home & furniture", "مفروشات", "Maison et meubles"),
    shop: L("Casa Deco", "كازا ديكو", "Casa Deco"),
    town: L("Kaslik", "الكسليك", "Kaslik"),
    address: L("Main road, building Feghali, 6th floor", "الطريق العام، بناية فغالي، الطابق السادس", "Route principale, immeuble Feghali, 6e étage"),
    shopAddress: L("Kaslik, main road", "الكسليك، الطريق العام", "Kaslik, route principale"),
    voice: [
      [4, 12.0, L("Cushion", "مخدة كنباية", "Coussin")],
      [1, 60.0, L("Table lamp", "لمبة طاولة", "Lampe de table")],
    ],
  },
  {
    id: "retail", icon: "🛍", eta: 45, menu: "catalogue",
    label: L("General retail", "محل عام", "Commerce"),
    shop: L("Bazar Rizk", "بازار رزق", "Bazar Rizk"),
    town: L("Saida", "صيدا", "Saïda"),
    address: L("Riad Al Solh street, building Bizri, 2nd floor", "شارع رياض الصلح، بناية البزري، الطابق الثاني", "Rue Riad Al Solh, immeuble Bizri, 2e étage"),
    shopAddress: L("Saida, old souk", "صيدا، السوق القديم", "Saïda, vieux souk"),
    voice: [
      [1, 9.0, L("Umbrella", "شمسية", "Parapluie")],
      [2, 4.0, L("A4 notebook", "دفتر A4", "Cahier A4")],
    ],
  },
];

// The same day for every shop, in each language. `d` is the shop in that language, `n` formats money.
// One order from start to end: [time, step, messages]. A message only shows for one way of
// getting the order (only: "delivery" | "pickup") or of paying (pay: "whish" | "cash").
const SCRIPT = {
  en: (d, n) => {
    const total = `Total: ${n(d.total)} (${d.whish ? "💳 Whish" : "💵 cash"})`;
    const where = d.delivery ? `📍 ${d.town}, ${d.address}\n📞 +961 71 234 567` : "📞 +961 71 234 567";
    const cash = d.delivery ? "💵 Cash on delivery" : "💵 Cash at the shop";
    const go = d.delivery ? ["🛵 Out for delivery", "✅ Delivered"] : ["✅ Ready for pickup", "📦 Collected"];
    const goAhead = (head) => `${head}\n${d.cartLines}\n${total}\n${where}\n${d.delivery ? "🛵 Karim will deliver it." : "🏪 The customer picks it up at the shop."}`;
    const collect = d.whish ? "💳 Paid with Whish — collect nothing from the customer" : `💵 Collect: ${n(d.total)} (cash)`;
    return [
      ["12:30", "order", [
        ["C", "Hi 👋"],
        ["c", `Hi! 👋 Welcome to ${d.shop}.\nOrder right here: tap the menu, or just type or send a voice note 🎤`, { buttons: [d.menuButton] }],
        ["C", "", { voice: "0:07" }],
        ["c", `Sure! 🙂\n${d.voiceLines}\nAnything else?`, { buttons: ["📋 View menu", "🛒 Checkout"] }],
        ["C", "🛒 Checkout"],
        ["c", "How would you like to get your order?", { buttons: ["🛵 Delivery", "🏪 Pickup"], only: "delivery" }],
        ["C", "🛵 Delivery", { only: "delivery" }],
        ["c", "📍 Tap the button to send your location, or type your address (street, building, floor).", { only: "delivery" }],
        ["C", d.address, { only: "delivery" }],
        ["c", "How would you like to pay?", { buttons: [cash, "💳 Whish"] }],
        ["C", "💳 Whish", { pay: "whish" }],
        ["C", cash, { pay: "cash" }],
        ["c", "Pay cash in dollars or in lira?", { buttons: ["Dollars", "Lira"], pay: "cash" }],
        ["C", "Dollars", { pay: "cash" }],
        ["c", `Your order\n${d.cartLines}\n${d.delivery ? `Delivery (${d.town})  ${n(1)}\n` : ""}Total: ${n(d.total)}\n${d.delivery ? "" : `Pickup at ${d.shopAddress} · `}${d.whish ? "Whish" : cash.slice(3)} · Ready in about ${d.eta} min`, { buttons: ["✅ Confirm order", "✏️ Edit order", "❌ Cancel"] }],
        ["C", "✅ Confirm order"],
        ["c", `🧾 Order #128 sent to ${d.shop}.\nTotal: ${n(d.total)}\n${d.whish ? "💳 You will get the Whish payment link here as soon as the shop accepts your order." : "You will get a message as soon as the shop accepts it."}`],
      ]],
      ["12:34", "accept", [
        ["s", `🔔 New ${d.delivery ? "" : "PICKUP "}order #128 — ${d.shop}\n${d.cartLines}\n${total}\n${where}`, { buttons: [`✅ Accept · ${d.eta} min`, "❌ Can't take it"] }],
        ["S", `✅ Accept · ${d.eta} min`],
        ["s", "👍 Order #128 accepted — the customer got the Whish link. You will get the order here again as soon as it is paid. Don't prepare it before.", { pay: "whish" }],
        ["c", `✅ ${d.shop} accepted your order #128! Pay ${n(d.total)} with Whish here:\nhttps://whish.money/pay/128xk\nWe start preparing it as soon as the payment is confirmed (link valid 30 minutes).`, { pay: "whish" }],
        ["c", `✅ ${d.shop} accepted your order #128 — about ${d.eta} min.`, { pay: "cash" }],
        ["s", goAhead("✅ Order #128 is confirmed — prepare it now."), { buttons: go, pay: "cash" }],
      ]],
      ["12:36", "whish", [
        ["c", `✅ Payment received! Order #128 is confirmed — about ${d.eta} min.`, { pay: "whish" }],
        ["s", goAhead("✅ Order #128 is paid with Whish — prepare it now."), { buttons: go, pay: "whish" }],
      ]],
      ["12:55", d.delivery ? "delivery" : "pickup", [
        ["d", `🛵 New delivery #128 — ${d.shop}\n${d.cartShort}\n${collect}\n📍 ${d.town}, ${d.address}\n📞 Customer: +961 71 234 567`, { buttons: ["🚀 Picked up", "✅ Delivered"] }],
        ["D", "🚀 Picked up"],
        ["d", `🛵 Delivery #128 — on the way\n${collect}\nTap “I'm outside” when you arrive.`, { buttons: ["📍 I'm outside", "✅ Delivered"] }],
        ["c", "🛵 Your order #128 is on its way with Karim — about 10 min.", { only: "delivery" }],
        ["D", "📍 I'm outside"],
        ["c", "📍 Karim is outside with your order #128! 📞 +961 70 111 222", { only: "delivery" }],
        ["D", "✅ Delivered"],
        ["c", `✅ Your order #128 was delivered. Thank you for ordering from ${d.shop}!`, { only: "delivery" }],
        ["S", "✅ Ready for pickup", { only: "pickup" }],
        ["c", `📦 Your order #128 is ready! You can pick it up now at ${d.shopAddress}.`, { only: "pickup" }],
        ["S", "📦 Collected", { only: "pickup" }],
        ["c", `✅ Thank you for picking up order #128 from ${d.shop}!`, { only: "pickup" }],
      ]],
      ["13:40", "rating", [
        ["c", "⭐ How was it? Reply with a number from 1 (bad) to 5 (excellent)."],
        ["C", "5"],
        ["c", "Thank you for your rating! 🙏"],
      ]],
    ];
  },

  ar: (d, n) => {
    const total = `المجموع: ${n(d.total)} (${d.whish ? "💳 Whish" : "💵 كاش"})`;
    const where = d.delivery ? `📍 ${d.town}، ${d.address}\n📞 +961 71 234 567` : "📞 +961 71 234 567";
    const cash = d.delivery ? "💵 كاش عند التسليم" : "💵 كاش بالمحل";
    const go = d.delivery ? ["🛵 خرج للتوصيل", "✅ تم التوصيل"] : ["✅ جاهز للاستلام", "📦 تم الاستلام"];
    const goAhead = (head) => `${head}\n${d.cartLines}\n${total}\n${where}\n${d.delivery ? "🛵 كريم رح يوصّله." : "🏪 الزبون رح ياخده من المحل."}`;
    const collect = d.whish ? "💳 مدفوع بـ Whish — لا تأخذ أي مبلغ من الزبون" : `💵 المبلغ: ${n(d.total)} (كاش)`;
    return [
      ["12:30", "order", [
        ["C", "مرحبا 👋"],
        ["c", `أهلا! 👋 أهلا وسهلا بـ ${d.shop}.\nاطلب من هون: كبس على القائمة، أو اكتب أو ابعت رسالة صوتية 🎤`, { buttons: [d.menuButton] }],
        ["C", "", { voice: "0:07" }],
        ["c", `أكيد! 🙂\n${d.voiceLines}\nبدك شي تاني؟`, { buttons: ["📋 عرض القائمة", "🛒 إتمام الطلب"] }],
        ["C", "🛒 إتمام الطلب"],
        ["c", "كيف تريد استلام طلبك؟", { buttons: ["🛵 توصيل", "🏪 استلام من المحل"], only: "delivery" }],
        ["C", "🛵 توصيل", { only: "delivery" }],
        ["c", "📍 اضغط الزر لتبعت موقعك، أو اكتب العنوان (الشارع، البناية، الطابق).", { only: "delivery" }],
        ["C", d.address, { only: "delivery" }],
        ["c", "كيف بتحب تدفع؟", { buttons: [cash, "💳 Whish"] }],
        ["C", "💳 Whish", { pay: "whish" }],
        ["C", cash, { pay: "cash" }],
        ["c", "بتدفع كاش بالدولار أو بالليرة؟", { buttons: ["دولار", "ليرة"], pay: "cash" }],
        ["C", "دولار", { pay: "cash" }],
        ["c", `طلبك\n${d.cartLines}\n${d.delivery ? `التوصيل (${d.town})  ${n(1)}\n` : ""}المجموع: ${n(d.total)}\n${d.delivery ? "" : `استلام من ${d.shopAddress} · `}${d.whish ? "Whish" : cash.slice(3)} · جاهز بحوالي ${d.eta} دقيقة`, { buttons: ["✅ تأكيد الطلب", "✏️ تعديل", "❌ إلغاء"] }],
        ["C", "✅ تأكيد الطلب"],
        ["c", `🧾 تم إرسال الطلب رقم 128 إلى ${d.shop}.\nالمجموع: ${n(d.total)}\n${d.whish ? "💳 رح يوصلك رابط الدفع بـ Whish هون أول ما المحل يقبل طلبك." : "رح توصلك رسالة أول ما المحل يقبل الطلب."}`],
      ]],
      ["12:34", "accept", [
        ["s", `🔔 ${d.delivery ? "طلب جديد" : "طلب استلام جديد"} رقم 128 — ${d.shop}\n${d.cartLines}\n${total}\n${where}`, { buttons: [`✅ قبول · ${d.eta} د`, "❌ لا يمكن"] }],
        ["S", `✅ قبول · ${d.eta} د`],
        ["s", "👍 تم قبول الطلب رقم 128، والزبون وصله رابط Whish. رح يرجعلك الطلب هون أول ما ينْدفع. لا تحضّره قبل.", { pay: "whish" }],
        ["c", `✅ ${d.shop} قبل طلبك رقم 128! ادفع ${n(d.total)} بـ Whish من هون:\nhttps://whish.money/pay/128xk\nمنبلش نحضّره أول ما يتأكد الدفع (الرابط صالح 30 دقيقة).`, { pay: "whish" }],
        ["c", `✅ ${d.shop} قبل طلبك رقم 128 — حوالي ${d.eta} دقيقة.`, { pay: "cash" }],
        ["s", goAhead("✅ الطلب رقم 128 تأكد — حضّره هلق."), { buttons: go, pay: "cash" }],
      ]],
      ["12:36", "whish", [
        ["c", `✅ وصل الدفع! الطلب رقم 128 تأكد — حوالي ${d.eta} دقيقة.`, { pay: "whish" }],
        ["s", goAhead("✅ الطلب رقم 128 مدفوع بـ Whish — حضّره هلق."), { buttons: go, pay: "whish" }],
      ]],
      ["12:55", d.delivery ? "delivery" : "pickup", [
        ["d", `🛵 توصيل جديد رقم 128 — ${d.shop}\n${d.cartShort}\n${collect}\n📍 ${d.town}، ${d.address}\n📞 الزبون: +961 71 234 567`, { buttons: ["🚀 تم الاستلام", "✅ تم التوصيل"] }],
        ["D", "🚀 تم الاستلام"],
        ["d", `🛵 التوصيل رقم 128 — في الطريق\n${collect}\nاضغط «أنا برا» لما توصل.`, { buttons: ["📍 أنا برا", "✅ تم التوصيل"] }],
        ["c", "🛵 طلبك رقم 128 في الطريق مع كريم — حوالي 10 دقائق.", { only: "delivery" }],
        ["D", "📍 أنا برا"],
        ["c", "📍 كريم برا مع طلبك رقم 128! 📞 +961 70 111 222", { only: "delivery" }],
        ["D", "✅ تم التوصيل"],
        ["c", `✅ تم توصيل طلبك رقم 128. شكراً لطلبك من ${d.shop}!`, { only: "delivery" }],
        ["S", "✅ جاهز للاستلام", { only: "pickup" }],
        ["c", `📦 طلبك رقم 128 جاهز! فيك تستلمه هلق من ${d.shopAddress}.`, { only: "pickup" }],
        ["S", "📦 تم الاستلام", { only: "pickup" }],
        ["c", `✅ شكراً لاستلام الطلب رقم 128 من ${d.shop}!`, { only: "pickup" }],
      ]],
      ["13:40", "rating", [
        ["c", "⭐ كيف كان طلبك؟ أجب برقم من 1 (سيء) إلى 5 (ممتاز)."],
        ["C", "5"],
        ["c", "شكراً على تقييمك! 🙏"],
      ]],
    ];
  },

  fr: (d, n) => {
    const total = `Total : ${n(d.total)} (${d.whish ? "💳 Whish" : "💵 espèces"})`;
    const where = d.delivery ? `📍 ${d.town}, ${d.address}\n📞 +961 71 234 567` : "📞 +961 71 234 567";
    const cash = d.delivery ? "💵 Espèces à la livraison" : "💵 Espèces au magasin";
    const go = d.delivery ? ["🛵 En livraison", "✅ Livrée"] : ["✅ Prête", "📦 Récupérée"];
    const goAhead = (head) => `${head}\n${d.cartLines}\n${total}\n${where}\n${d.delivery ? "🛵 Karim va la livrer." : "🏪 Le client la récupère au magasin."}`;
    const collect = d.whish ? "💳 Payé avec Whish — rien à encaisser auprès du client" : `💵 À encaisser : ${n(d.total)} (espèces)`;
    return [
      ["12:30", "order", [
        ["C", "Bonjour 👋"],
        ["c", `Bonjour ! 👋 Bienvenue chez ${d.shop}.\nCommandez ici : touchez le menu, écrivez ou envoyez un vocal 🎤`, { buttons: [d.menuButton] }],
        ["C", "", { voice: "0:07" }],
        ["c", `Bien sûr ! 🙂\n${d.voiceLines}\nAutre chose ?`, { buttons: ["📋 Voir le menu", "🛒 Commander"] }],
        ["C", "🛒 Commander"],
        ["c", "Comment souhaitez-vous recevoir votre commande ?", { buttons: ["🛵 Livraison", "🏪 À emporter"], only: "delivery" }],
        ["C", "🛵 Livraison", { only: "delivery" }],
        ["c", "📍 Touchez le bouton pour envoyer votre position, ou écrivez l'adresse (rue, immeuble, étage).", { only: "delivery" }],
        ["C", d.address, { only: "delivery" }],
        ["c", "Comment souhaitez-vous payer ?", { buttons: [cash, "💳 Whish"] }],
        ["C", "💳 Whish", { pay: "whish" }],
        ["C", cash, { pay: "cash" }],
        ["c", "Payer en dollars ou en livres ?", { buttons: ["Dollars", "Livres"], pay: "cash" }],
        ["C", "Dollars", { pay: "cash" }],
        ["c", `Votre commande\n${d.cartLines}\n${d.delivery ? `Livraison (${d.town})  ${n(1)}\n` : ""}Total : ${n(d.total)}\n${d.delivery ? "" : `Retrait à ${d.shopAddress} · `}${d.whish ? "Whish" : cash.slice(3)} · Prête dans ${d.eta} min environ`, { buttons: ["✅ Confirmer", "✏️ Modifier", "❌ Annuler"] }],
        ["C", "✅ Confirmer"],
        ["c", `🧾 Commande n° 128 envoyée à ${d.shop}.\nTotal : ${n(d.total)}\n${d.whish ? "💳 Vous recevrez ici le lien de paiement Whish dès que le commerce accepte votre commande." : "Vous recevrez un message dès que le commerce l'accepte."}`],
      ]],
      ["12:34", "accept", [
        ["s", `🔔 Nouvelle commande ${d.delivery ? "" : "À RETIRER "}n° 128 — ${d.shop}\n${d.cartLines}\n${total}\n${where}`, { buttons: [`✅ Accepter · ${d.eta} min`, "❌ Impossible"] }],
        ["S", `✅ Accepter · ${d.eta} min`],
        ["s", "👍 Commande n° 128 acceptée, le client a reçu le lien Whish. Vous recevrez la commande ici dès qu'elle est payée. Ne la préparez pas avant.", { pay: "whish" }],
        ["c", `✅ ${d.shop} a accepté votre commande n° 128 ! Payez ${n(d.total)} avec Whish ici :\nhttps://whish.money/pay/128xk\nNous la préparons dès que le paiement est confirmé (lien valable 30 minutes).`, { pay: "whish" }],
        ["c", `✅ ${d.shop} a accepté votre commande n° 128 — environ ${d.eta} min.`, { pay: "cash" }],
        ["s", goAhead("✅ La commande n° 128 est confirmée — préparez-la."), { buttons: go, pay: "cash" }],
      ]],
      ["12:36", "whish", [
        ["c", `✅ Paiement reçu ! La commande n° 128 est confirmée — environ ${d.eta} min.`, { pay: "whish" }],
        ["s", goAhead("✅ La commande n° 128 est payée avec Whish — préparez-la."), { buttons: go, pay: "whish" }],
      ]],
      ["12:55", d.delivery ? "delivery" : "pickup", [
        ["d", `🛵 Nouvelle livraison n° 128 — ${d.shop}\n${d.cartShort}\n${collect}\n📍 ${d.town}, ${d.address}\n📞 Client : +961 71 234 567`, { buttons: ["🚀 Récupérée", "✅ Livrée"] }],
        ["D", "🚀 Récupérée"],
        ["d", `🛵 Livraison n° 128 — en route\n${collect}\nTouchez « Je suis devant » en arrivant.`, { buttons: ["📍 Je suis devant", "✅ Livrée"] }],
        ["c", "🛵 Votre commande n° 128 est en route avec Karim — environ 10 min.", { only: "delivery" }],
        ["D", "📍 Je suis devant"],
        ["c", "📍 Karim est devant chez vous avec la commande n° 128 ! 📞 +961 70 111 222", { only: "delivery" }],
        ["D", "✅ Livrée"],
        ["c", `✅ Votre commande n° 128 a été livrée. Merci d'avoir commandé chez ${d.shop} !`, { only: "delivery" }],
        ["S", "✅ Prête", { only: "pickup" }],
        ["c", `📦 Votre commande n° 128 est prête ! Vous pouvez la retirer à ${d.shopAddress}.`, { only: "pickup" }],
        ["S", "📦 Récupérée", { only: "pickup" }],
        ["c", `✅ Merci d'avoir retiré la commande n° 128 chez ${d.shop} !`, { only: "pickup" }],
      ]],
      ["13:40", "rating", [
        ["c", "⭐ Qu'en avez-vous pensé ? Répondez avec un chiffre de 1 (mauvais) à 5 (excellent)."],
        ["C", "5"],
        ["c", "Merci pour votre note ! 🙏"],
      ]],
    ];
  },
};

const MONEY = {
  en: (v) => `${v.toFixed(2)}$`,
  ar: (v) => `⁦${v.toFixed(2)}$⁩`,
  fr: (v) => `${v.toFixed(2).replace(".", ",")} $`,
};

function shopIn(shop, lang, delivery, whish) {
  const t = (v) => v[lang];
  const n = MONEY[lang];
  const line = ([qty, price, name]) => `${qty}× ${t(name)}  ${n(qty * price)}`;
  const cart = shop.voice;
  const subtotal = cart.reduce((sum, [qty, price]) => sum + qty * price, 0);
  return {
    delivery, whish,
    menuButton: UI[lang].menuButtons[shop.menu], shop: t(shop.shop), town: t(shop.town), address: t(shop.address), shopAddress: t(shop.shopAddress), eta: shop.eta,
    voiceLines: shop.voice.map(line).join("\n"),
    total: subtotal + (delivery ? 1 : 0),
    cartLines: cart.map(line).join("\n"),
    cartShort: cart.map(([qty, , name]) => `${qty}× ${t(name)}`).join("\n"),
  };
}

function build(shop, lang, delivery, whish) {
  const ui = UI[lang];
  const d = shopIn(shop, lang, delivery, whish);
  const pay = whish ? "whish" : "cash";
  const messages = [];
  const steps = [];
  let clock = 0;
  SCRIPT[lang](d, MONEY[lang]).forEach(([at, key, list]) => {
    const [h, m] = at.split(":").map(Number);
    list.filter(([who, , extra = {}]) => (delivery || who.toLowerCase() !== "d")
      && (!extra.only || (extra.only === "delivery") === delivery) && (!extra.pay || extra.pay === pay))
      .forEach(([who, text, { only, pay: _, ...extra } = {}], k) => {
      if (!steps.includes(key)) steps.push(key);
      // The clock never goes back.
      clock = Math.max(h * 60 + m + Math.floor(k / 3), clock);
      messages.push({
        chat: who.toLowerCase(),
        out: who !== who.toLowerCase(),
        step: steps.length - 1,
        time: `${Math.floor(clock / 60) % 24}:${String(clock % 60).padStart(2, "0")}`,
        text,
        ...extra,
      });
    });
  });
  return { id: shop.id, icon: shop.icon, label: shop.label[lang], shop: d.shop, steps: steps.map((key) => ui.steps[key]), chats: delivery ? ["c", "s", "d"] : ["c", "s"], messages };
}

// Same order as the business types on the sign-up form.
const ORDER = ["restaurant", "bakery", "market", "butcher", "pharmacy", "clothing", "electronics", "flowers", "water", "laundry", "salon", "clinic", "pets", "home", "retail"];

export function orderDemo(lang) {
  const key = UI[lang] ? lang : "en";
  return {
    ...UI[key],
    scenarios: ORDER.map((id) => {
      const shop = SHOPS.find((s) => s.id === id);
      // Each shop chooses delivery or pickup only in its settings; the demo can show both.
      return {
        id, icon: shop.icon, label: shop.label[key],
        defaultMode: shop.delivery === false ? "pickup" : "delivery",
        delivery: { whish: build(shop, key, true, true), cash: build(shop, key, true, false) },
        pickup: { whish: build(shop, key, false, true), cash: build(shop, key, false, false) },
      };
    }),
  };
}
