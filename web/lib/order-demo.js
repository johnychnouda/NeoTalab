// The homepage demo: a day of real NeoTalab conversations for several kinds of shops, on the
// phones of the customer, the shop owner and the driver. Every shop goes through the same
// features (SCRIPT below) with its own products; the messages follow the real bot texts.
//
// A message is [chat, text, extras]:
//   lower-case chat = sent by the shop's NeoTalab number, upper-case = sent by that phone's owner
//   c / C  customer      s / S  shop owner or staff      d / D  driver
// extras: buttons (WhatsApp reply buttons), voice ("0:07"), photo (emoji shown in the picture),
// human (a person from the shop answers instead of the bot), day (date chip before the message).

const LBP_RATE = 89500;
// The script's time blocks, grouped into the steps shown above the phones.
const STEP_OF_BLOCK = [0, 1, 1, 2, 2, 2, 3, 4, 4, 5, 6, 7, 7];

const UI = {
  en: {
    title: "See NeoTalab at work",
    lead: "Pick a shop and watch one day on the customer's, the owner's and the driver's WhatsApp.",
    online: "online", typing: "typing…", today: "Today", message: "Message",
    pause: "Pause", play: "Play", replay: "Replay", prev: "Previous step", next: "Next step", voice: "Voice message",
    who: { c: "Customer", s: "Shop owner", d: "Driver" },
    steps: ["Shop opens", "Order", "Whish payment", "Delivery", "Rating & help", "Pickup in lira", "Booking", "End of day"],
  },
  ar: {
    title: "شوف NeoTalab عم يشتغل",
    lead: "اختار محل وتابع نهار كامل على واتساب الزبون وصاحب المحل والسائق.",
    online: "متصل", typing: "يكتب…", today: "اليوم", message: "مراسلة",
    pause: "إيقاف", play: "تشغيل", replay: "إعادة", prev: "الخطوة السابقة", next: "الخطوة التالية", voice: "رسالة صوتية",
    who: { c: "الزبون", s: "صاحب المحل", d: "السائق" },
    steps: ["فتح المحل", "الطلب", "دفع Whish", "التوصيل", "تقييم ومساعدة", "استلام بالليرة", "حجز موعد", "آخر النهار"],
  },
  fr: {
    title: "NeoTalab en action",
    lead: "Choisissez un commerce et suivez une journée sur le WhatsApp du client, du commerçant et du livreur.",
    online: "en ligne", typing: "écrit…", today: "Aujourd'hui", message: "Message",
    pause: "Pause", play: "Lecture", replay: "Rejouer", prev: "Étape précédente", next: "Étape suivante", voice: "Message vocal",
    who: { c: "Client", s: "Commerçant", d: "Livreur" },
    steps: ["Ouverture", "Commande", "Paiement Whish", "Livraison", "Note et aide", "Retrait en livres", "Réservation", "Fin de journée"],
  },
};

// What changes from one shop to another. Text is given as { en, ar, fr }.
const SHOPS = [
  {
    id: "restaurant", icon: "🥙", eta: 25,
    label: { en: "Restaurant", ar: "مطعم", fr: "Restaurant" },
    shop: { en: "Foren Al Hara", ar: "فرن الحارة", fr: "Foren Al Hara" },
    town: { en: "Jbeil", ar: "جبيل", fr: "Jbeil" },
    address: { en: "Main street, near the church, building Haddad, 2nd floor", ar: "الشارع العام حد الكنيسة، بناية حداد، الطابق الثاني", fr: "Rue principale près de l'église, immeuble Haddad, 2e étage" },
    shopAddress: { en: "Jbeil, main street", ar: "جبيل، الشارع العام", fr: "Jbeil, rue principale" },
    voice: [
      [2, 1.0, { en: "Zaatar man'oushe", ar: "منقوشة زعتر", fr: "Man'oushé zaatar" }],
      [1, 2.5, { en: "Cheese man'oushe", ar: "منقوشة جبنة", fr: "Man'oushé fromage" }],
    ],
    category: { en: "🥤 Drinks", ar: "🥤 مشروبات", fr: "🥤 Boissons" },
    add: [1, 0.75, { en: "Ayran", ar: "عيران", fr: "Ayran" }],
    photo: "🍕",
    photoCaption: { en: "Can you make this one? 😍", ar: "فيكن تعملولي هيدي؟ 😍", fr: "Vous pouvez faire celle-ci ? 😍" },
    staffName: { en: "Georges, Foren Al Hara", ar: "جورج، فرن الحارة", fr: "Georges, Foren Al Hara" },
    staffReply: { en: "Yes! That's our lahm b'ajeen, 3.00$ each. Shall I prepare 2 for tonight? 🙂", ar: "أكيد! هيدي لحم بعجين، 3.00$ الوحدة. بحضّرلك 2 لليوم المسا؟ 🙂", fr: "Oui ! C'est notre lahm b'ajeen, 3,00 $ pièce. Je vous en prépare 2 pour ce soir ? 🙂" },
    customerThanks: { en: "Yes please, thank you!", ar: "إيه بليز، شكراً!", fr: "Oui merci !" },
    pickupAsk: { en: "bade 3 man2oushe zaatar, I'll pass to take them", ar: "بدي 3 مناقيش زعتر، بمرق باخدن", fr: "3 man'oushé zaatar à emporter svp, je passe" },
    pickup: [3, 1.0, { en: "Zaatar man'oushe", ar: "منقوشة زعتر", fr: "Man'oushé zaatar" }],
    bookingAsk: { en: "Can I book a table for 4 on Friday evening?", ar: "فيي احجز طاولة لـ 4 نهار الجمعة المسا؟", fr: "Je peux réserver une table pour 4 vendredi soir ?" },
    service: { en: "Table for 4", ar: "طاولة لـ 4", fr: "Table pour 4" },
    servicePrice: 0,
    slots: "19:00 · 20:00 · 21:00 · 22:00", time: "20:00",
    bookingDay: { en: "Fri 17 Oct", ar: "الجمعة 17 تشرين الأول", fr: "ven. 17 oct." },
    report: [42, 238.5, "4.8"],
  },
  {
    id: "pharmacy", icon: "💊", eta: 40,
    label: { en: "Pharmacy", ar: "صيدلية", fr: "Pharmacie" },
    shop: { en: "Pharmacie Al Shifa", ar: "صيدلية الشفاء", fr: "Pharmacie Al Shifa" },
    town: { en: "Achrafieh", ar: "الأشرفية", fr: "Achrafieh" },
    address: { en: "Sursock street, building 12, 3rd floor", ar: "شارع سرسق، بناية 12، الطابق الثالث", fr: "Rue Sursock, immeuble 12, 3e étage" },
    shopAddress: { en: "Achrafieh, Sassine square", ar: "الأشرفية، ساحة ساسين", fr: "Achrafieh, place Sassine" },
    voice: [
      [1, 4.0, { en: "Panadol Extra", ar: "Panadol Extra", fr: "Panadol Extra" }],
      [1, 6.0, { en: "Vitamin C 1000", ar: "فيتامين C 1000", fr: "Vitamine C 1000" }],
    ],
    category: { en: "🧴 Skin care", ar: "🧴 العناية بالبشرة", fr: "🧴 Soins de la peau" },
    add: [1, 12.0, { en: "Sunscreen SPF 50", ar: "كريم واقي SPF 50", fr: "Écran solaire SPF 50" }],
    photo: "💊",
    photoCaption: { en: "Do you have this one?", ar: "عندكن هيدا الدوا؟", fr: "Vous avez celui-ci ?" },
    staffName: { en: "Rita, pharmacist", ar: "ريتا، صيدلانية", fr: "Rita, pharmacienne" },
    staffReply: { en: "Yes, we have it, 8.50$. It needs a prescription, please bring it when you pass 🙂", ar: "إيه موجود، 8.50$. بدو وصفة طبية، جيبها معك لما تمرق 🙂", fr: "Oui, nous l'avons, 8,50 $. Il faut une ordonnance, apportez-la en passant 🙂" },
    customerThanks: { en: "Perfect, thank you!", ar: "تمام، شكراً!", fr: "Parfait, merci !" },
    pickupAsk: { en: "Do you have baby wipes? I'll pass to pick them up", ar: "عندكن محارم للأطفال؟ بمرق باخدن", fr: "Vous avez des lingettes bébé ? Je passe les chercher" },
    pickup: [2, 1.5, { en: "Baby wipes", ar: "محارم أطفال", fr: "Lingettes bébé" }],
    bookingAsk: { en: "Can I book a blood pressure check tomorrow morning?", ar: "فيي احجز فحص ضغط بكرا الصبح؟", fr: "Je peux réserver une prise de tension demain matin ?" },
    service: { en: "Blood pressure check", ar: "فحص ضغط", fr: "Prise de tension" },
    servicePrice: 5,
    slots: "09:00 · 09:30 · 10:30 · 11:00", time: "10:30",
    bookingDay: { en: "Wed 15 Oct", ar: "الأربعاء 15 تشرين الأول", fr: "mer. 15 oct." },
    report: [27, 412, "4.9"],
  },
  {
    id: "salon", icon: "💇", eta: 45,
    label: { en: "Salon", ar: "صالون", fr: "Salon" },
    shop: { en: "Beauty Corner", ar: "Beauty Corner", fr: "Beauty Corner" },
    town: { en: "Jounieh", ar: "جونيه", fr: "Jounieh" },
    address: { en: "Fouad Chehab street, building Nassar, 4th floor", ar: "شارع فؤاد شهاب، بناية نصار، الطابق الرابع", fr: "Rue Fouad Chehab, immeuble Nassar, 4e étage" },
    shopAddress: { en: "Jounieh, old souk", ar: "جونيه، السوق القديم", fr: "Jounieh, vieux souk" },
    voice: [
      [1, 15.0, { en: "Hair mask", ar: "ماسك للشعر", fr: "Masque capillaire" }],
      [1, 9.0, { en: "Shampoo 400 ml", ar: "شامبو 400 مل", fr: "Shampooing 400 ml" }],
    ],
    category: { en: "💅 Nails", ar: "💅 أظافر", fr: "💅 Ongles" },
    add: [1, 6.0, { en: "Nail polish", ar: "طلاء أظافر", fr: "Vernis à ongles" }],
    photo: "💇",
    photoCaption: { en: "Can you do this haircut?", ar: "فيكن تعملولي هالقصة؟", fr: "Vous pouvez faire cette coupe ?" },
    staffName: { en: "Maya, Beauty Corner", ar: "مايا، Beauty Corner", fr: "Maya, Beauty Corner" },
    staffReply: { en: "Of course! It's a layered cut, 25.00$, about 1 hour. You can book it right here 😊", ar: "أكيد! هيدي قصة layers، 25.00$، حوالي ساعة. فيك تحجز من هون 😊", fr: "Bien sûr ! C'est une coupe dégradée, 25,00 $, environ 1 heure. Vous pouvez réserver ici 😊" },
    customerThanks: { en: "Great, thank you!", ar: "حلو، شكراً!", fr: "Super, merci !" },
    pickupAsk: { en: "Can I pick up a hair brush today?", ar: "فيي آخد فرشاية شعر اليوم؟", fr: "Je peux passer prendre une brosse aujourd'hui ?" },
    pickup: [1, 7.0, { en: "Hair brush", ar: "فرشاية شعر", fr: "Brosse à cheveux" }],
    bookingAsk: { en: "Can I book that layered cut tomorrow afternoon?", ar: "فيي احجز قصة الـ layers بكرا بعد الضهر؟", fr: "Je peux réserver cette coupe demain après-midi ?" },
    service: { en: "Layered haircut", ar: "قصة layers", fr: "Coupe dégradée" },
    servicePrice: 25,
    slots: "14:00 · 15:30 · 16:30 · 17:30", time: "16:30",
    bookingDay: { en: "Wed 15 Oct", ar: "الأربعاء 15 تشرين الأول", fr: "mer. 15 oct." },
    report: [18, 520, "5.0"],
  },
  {
    id: "market", icon: "🛒", eta: 30,
    label: { en: "Mini market", ar: "ميني ماركت", fr: "Supérette" },
    shop: { en: "Mini Market Abou Elie", ar: "ميني ماركت أبو إيلي", fr: "Mini Market Abou Elie" },
    town: { en: "Zalka", ar: "الزلقا", fr: "Zalka" },
    address: { en: "Main road, building Khoury, 1st floor", ar: "الطريق العام، بناية خوري، الطابق الأول", fr: "Route principale, immeuble Khoury, 1er étage" },
    shopAddress: { en: "Zalka, main road", ar: "الزلقا، الطريق العام", fr: "Zalka, route principale" },
    voice: [
      [2, 3.5, { en: "Labneh 500 g", ar: "لبنة 500 غ", fr: "Labné 500 g" }],
      [1, 6.5, { en: "Eggs (30)", ar: "بيض (30)", fr: "Œufs (30)" }],
    ],
    category: { en: "🥤 Drinks", ar: "🥤 مشروبات", fr: "🥤 Boissons" },
    add: [1, 3.0, { en: "Water pack 6 × 1.5 L", ar: "كرتونة مي 6 × 1.5 ل", fr: "Pack d'eau 6 × 1,5 L" }],
    photo: "🛒",
    photoCaption: { en: "3andak hayda l brand?", ar: "عندك هالماركة؟", fr: "Vous avez cette marque ?" },
    staffName: { en: "Elie, Mini Market", ar: "إيلي، ميني ماركت", fr: "Elie, Mini Market" },
    staffReply: { en: "Yes, 4.25$. I'll keep one for you 🙂", ar: "إيه موجودة، 4.25$. بخبيلك وحدة 🙂", fr: "Oui, 4,25 $. Je vous en garde un 🙂" },
    customerThanks: { en: "Merci Elie!", ar: "ميرسي إيلي!", fr: "Merci Elie !" },
    pickupAsk: { en: "3andak 2 khebz? I'll pass now", ar: "عندك 2 خبز؟ بمرق هلق", fr: "Vous avez 2 paquets de pain ? Je passe maintenant" },
    pickup: [2, 0.8, { en: "Arabic bread", ar: "خبز عربي", fr: "Pain libanais" }],
    bookingAsk: { en: "Can I order a mezze platter for Saturday noon?", ar: "فيي اطلب صينية مازة لنهار السبت الضهر؟", fr: "Je peux commander un plateau mezzé pour samedi midi ?" },
    service: { en: "Mezze platter (10 people)", ar: "صينية مازة (10 أشخاص)", fr: "Plateau mezzé (10 pers.)" },
    servicePrice: 45,
    slots: "10:00 · 12:00 · 14:00", time: "12:00",
    bookingDay: { en: "Sat 18 Oct", ar: "السبت 18 تشرين الأول", fr: "sam. 18 oct." },
    report: [36, 287.25, "4.7"],
  },
];

// The same day for every shop, in each language. `d` is the shop in that language, `n` formats money.
const SCRIPT = {
  en: (d, n) => [
    ["07:58", [
      ["S", "open"],
      ["s", `🟢 ${d.shop} is OPEN, the bot is taking orders.`],
      ["D", "start"],
      ["d", "✅ Shift started, Karim. New deliveries will come here.\nSend \"stop\" when you finish."],
    ]],
    ["12:30", [
      ["C", "", { voice: "0:07" }],
      ["c", `Sure! 🙂\n${d.voiceLines}\nAnything else?`, { buttons: ["📋 View menu", "🛒 Checkout"] }],
    ]],
    ["12:31", [
      ["C", "📋 View menu"],
      ["c", "What would you like? Choose a category 👇", { buttons: ["📋 Products"] }],
      ["C", d.category],
      ["c", "Choose an item 👇", { buttons: ["📋 See items"] }],
      ["C", `${d.addName} · ${n(d.addPrice)}`],
      ["c", `✅ Added 1× ${d.addName}\n🛒 Cart: ${d.cartCount} items · ${n(d.subtotal)}`, { buttons: ["➕ Add more", "🛒 Checkout"] }],
      ["C", "🛒 Checkout"],
    ]],
    ["12:32", [
      ["c", "Which area should we deliver to?", { buttons: ["Choose"] }],
      ["C", `${d.town} · Delivery ${n(1)}`],
      ["c", "📍 Tap the button to send your location, or type your address (street, building, floor)."],
      ["C", d.address],
      ["c", "How would you like to pay?", { buttons: ["💵 Cash on delivery", "💳 Whish"] }],
      ["C", "💳 Whish"],
      ["c", `Your order\n${d.cartLines}\nDelivery (${d.town})  ${n(1)}\nTotal: ${n(d.total)}\nWhish · Ready in about ${d.eta} min`, { buttons: ["✅ Confirm order", "✏️ Edit order", "❌ Cancel"] }],
      ["C", "✅ Confirm order"],
      ["c", `🧾 Order #128 sent to ${d.shop}.\nTotal: ${n(d.total)}\n💳 You will get the Whish payment link here as soon as the shop accepts your order.`],
    ]],
    ["12:34", [
      ["s", `🔔 New order #128\n${d.cartLines}\nTotal: ${n(d.total)} (Whish)\n📍 ${d.town}, ${d.address}\n📞 +961 71 234 567`, { buttons: [`✅ Accept · ${d.eta} min`, "❌ Can't take it"] }],
      ["S", `✅ Accept · ${d.eta} min`],
      ["s", "👍 Order #128 accepted, the customer got the Whish link. You will get the order here again as soon as it is paid. Don't prepare it before."],
    ]],
    ["12:35", [
      ["c", `✅ ${d.shop} accepted your order #128! Pay ${n(d.total)} with Whish here:\nhttps://whish.money/pay/128xk\nWe start preparing it as soon as the payment is confirmed (link valid 30 minutes).`],
      ["c", `✅ Payment received! Order #128 is confirmed, about ${d.eta} min.`],
      ["s", `🔔 Order #128 is paid ✅ Prepare it now.\n${d.cartLines}\n🛵 Karim will deliver it.`],
    ]],
    ["12:55", [
      ["d", `🛵 New delivery #128\n${d.cartShort}\nCollect: nothing (paid with Whish)\n📍 ${d.town}, ${d.address}\n📞 Customer: +961 71 234 567`, { buttons: ["🚀 Picked up"] }],
      ["D", "🚀 Picked up"],
      ["d", "🛵 Delivery #128, on the way\n💵 Collect: nothing\nTap “I'm outside” when you arrive.", { buttons: ["📍 I'm outside", "✅ Delivered"] }],
      ["c", "🛵 Your order #128 is on its way with Karim, about 10 min."],
      ["D", "📍 I'm outside"],
      ["d", "👍 The customer was told you are outside."],
      ["c", "📍 Karim is outside with your order #128! 📞 +961 70 111 222"],
      ["D", "✅ Delivered"],
      ["d", "✅ Delivery #128 completed. Thanks!"],
      ["c", `✅ Your order #128 was delivered. Thank you for ordering from ${d.shop}!`],
    ]],
    ["13:40", [
      ["c", "⭐ How was it? Reply with a number from 1 (bad) to 5 (excellent)."],
      ["C", "5"],
      ["c", "Thank you for your rating! 🙏"],
    ]],
    ["15:10", [
      ["C", d.photoCaption, { photo: d.photo }],
      ["c", `👋 A member of the ${d.shop} team will reply to you here shortly.`],
      ["s", "📷 +961 71 234 567 sent a photo. The bot is paused in that chat, see it and answer in your WhatsApp Business app.\nTap the button when you're done.", { buttons: ["▶️ Give back to bot"] }],
      ["c", d.staffReply, { human: d.staffName }],
      ["C", d.customerThanks],
      ["S", "▶️ Give back to bot"],
      ["s", "🤖 The bot is handling +961 71 234 567 again."],
    ]],
    ["16:20", [
      ["C", d.pickupAsk],
      ["c", "How would you like to pay?", { buttons: ["💵 Cash at the shop", "💳 Whish"] }],
      ["C", "💵 Cash at the shop"],
      ["c", "Pay cash in dollars or in lira?", { buttons: ["Dollars", "Lira"] }],
      ["C", "Lira"],
      ["c", `Your order\n${d.pickupLine}\nTotal: ${n(d.pickupTotal)} (${d.pickupLbp})\nPickup at ${d.shopAddress} · Cash at the shop\nReady in about 15 min`, { buttons: ["✅ Confirm order", "✏️ Edit order", "❌ Cancel"] }],
      ["C", "✅ Confirm order"],
      ["c", `🧾 Order #129 sent to ${d.shop}.\nTotal: ${n(d.pickupTotal)} (${d.pickupLbp})\nYou will get a message as soon as the shop accepts it.`],
      ["s", `🔔 New PICKUP order #129\n${d.pickupLine}\nTotal: ${d.pickupLbp} (cash)\n📞 +961 71 234 567`, { buttons: ["✅ Accept · 15 min", "❌ Can't take it"] }],
      ["S", "✅ Accept · 15 min"],
      ["s", "👍 Order #129 updated.", { buttons: ["✅ Ready for pickup", "📦 Collected"] }],
      ["c", `✅ ${d.shop} accepted your order #129, about 15 min.`],
      ["S", "✅ Ready for pickup"],
      ["c", `📦 Your order #129 is ready! You can pick it up now at ${d.shopAddress}.`],
      ["S", "📦 Collected"],
      ["c", `✅ Thank you for picking up order #129 from ${d.shop}!`],
    ]],
    ["18:05", [
      ["C", d.bookingAsk],
      ["c", `🗓 Free times for ${d.service} on ${d.bookingDay}:\n${d.slots}\nWhich time suits you?`],
      ["C", d.time],
      ["c", `✅ Booked: ${d.service} on ${d.bookingDay} at ${d.time}${d.servicePrice} at ${d.shop}, ${d.shopAddress}.\nWe'll send you a reminder. Reply "cancel appointment" if you can't make it.`],
      ["s", `🗓 New booking, ${d.shop}\n${d.service}\n${d.bookingDay} at ${d.time}${d.servicePriceShort}\n📞 +961 71 234 567`, { buttons: ["✅ Done", "🚫 No-show"] }],
    ]],
    ["22:00", [
      ["S", "close"],
      ["s", `🔴 ${d.shop} is CLOSED, the bot tells customers you are closed.`],
      ["D", "stop"],
      ["d", `👋 Shift ended, Karim.\nDeliveries: ${d.driverOrders}\nCash to hand over: ${d.driverCash}`],
      ["s", `📊 ${d.shop}, daily report\nOrders delivered: ${d.reportOrders}\nSales: ${n(d.reportSales)}\nAverage rating: ${d.reportRating} ⭐\nLate orders: 0\nTop items: ${d.topItems}`],
    ]],
    [d.reminderAt, [
      ["c", `⏰ Reminder: your ${d.service} at ${d.shop} is on ${d.bookingDay} at ${d.time}. See you soon!`, { day: d.bookingDay }],
      ["C", "See you there 👍"],
      ["S", "✅ Done", { day: d.bookingDay, late: true }],
      ["s", `👍 ${d.service} updated.`],
    ]],
  ],

  ar: (d, n) => [
    ["07:58", [
      ["S", "open"],
      ["s", `🟢 ${d.shop} مفتوح، البوت عم ياخد طلبات.`],
      ["D", "start"],
      ["d", "✅ بدأ دوامك يا كريم. التوصيلات الجديدة رح توصلك هون.\nابعت \"stop\" لما تخلص."],
    ]],
    ["12:30", [
      ["C", "", { voice: "0:07" }],
      ["c", `أكيد! 🙂\n${d.voiceLines}\nبدك شي تاني؟`, { buttons: ["📋 عرض القائمة", "🛒 إتمام الطلب"] }],
    ]],
    ["12:31", [
      ["C", "📋 عرض القائمة"],
      ["c", "شو بتحب؟ اختار فئة 👇", { buttons: ["📋 المنتجات"] }],
      ["C", d.category],
      ["c", "اختار صنف 👇", { buttons: ["📋 شوف الأصناف"] }],
      ["C", `${d.addName} · ${n(d.addPrice)}`],
      ["c", `✅ انضاف 1× ${d.addName}\n🛒 السلة: ${d.cartCount} أصناف · ${n(d.subtotal)}`, { buttons: ["➕ زيد", "🛒 إتمام الطلب"] }],
      ["C", "🛒 إتمام الطلب"],
    ]],
    ["12:32", [
      ["c", "لأي منطقة منوصّل؟", { buttons: ["اختار"] }],
      ["C", `${d.town} · التوصيل ${n(1)}`],
      ["c", "📍 اضغط الزر لتبعت موقعك، أو اكتب العنوان (الشارع، البناية، الطابق)."],
      ["C", d.address],
      ["c", "كيف بتحب تدفع؟", { buttons: ["💵 كاش عند التسليم", "💳 Whish"] }],
      ["C", "💳 Whish"],
      ["c", `طلبك\n${d.cartLines}\nالتوصيل (${d.town})  ${n(1)}\nالمجموع: ${n(d.total)}\nWhish · جاهز بحوالي ${d.eta} دقيقة`, { buttons: ["✅ تأكيد الطلب", "✏️ تعديل", "❌ إلغاء"] }],
      ["C", "✅ تأكيد الطلب"],
      ["c", `🧾 تم إرسال الطلب رقم 128 إلى ${d.shop}.\nالمجموع: ${n(d.total)}\n💳 رح يوصلك رابط الدفع بـ Whish هون أول ما المحل يقبل طلبك.`],
    ]],
    ["12:34", [
      ["s", `🔔 طلب جديد رقم 128\n${d.cartLines}\nالمجموع: ${n(d.total)} (Whish)\n📍 ${d.town}، ${d.address}\n📞 +961 71 234 567`, { buttons: [`✅ قبول · ${d.eta} د`, "❌ ما فينا"] }],
      ["S", `✅ قبول · ${d.eta} د`],
      ["s", "👍 تم قبول الطلب رقم 128، والزبون وصله رابط Whish. رح يرجعلك الطلب هون أول ما ينْدفع. لا تحضّره قبل."],
    ]],
    ["12:35", [
      ["c", `✅ ${d.shop} قبل طلبك رقم 128! ادفع ${n(d.total)} بـ Whish من هون:\nhttps://whish.money/pay/128xk\nمنبلش نحضّره أول ما يتأكد الدفع (الرابط صالح 30 دقيقة).`],
      ["c", `✅ وصل الدفع! الطلب رقم 128 تأكد، حوالي ${d.eta} دقيقة.`],
      ["s", `🔔 الطلب رقم 128 مدفوع ✅ حضّره هلق.\n${d.cartLines}\n🛵 كريم رح يوصّله.`],
    ]],
    ["12:55", [
      ["d", `🛵 توصيل جديد رقم 128\n${d.cartShort}\nالمبلغ: ولا شي (مدفوع بـ Whish)\n📍 ${d.town}، ${d.address}\n📞 الزبون: +961 71 234 567`, { buttons: ["🚀 استلمت"] }],
      ["D", "🚀 استلمت"],
      ["d", "🛵 التوصيل رقم 128، في الطريق\n💵 المبلغ: ولا شي\nاضغط «أنا برا» لما توصل.", { buttons: ["📍 أنا برا", "✅ تم التوصيل"] }],
      ["c", "🛵 طلبك رقم 128 في الطريق مع كريم، حوالي 10 دقائق."],
      ["D", "📍 أنا برا"],
      ["d", "👍 خبّرنا الزبون إنك برا."],
      ["c", "📍 كريم برا مع طلبك رقم 128! 📞 +961 70 111 222"],
      ["D", "✅ تم التوصيل"],
      ["d", "✅ تم التوصيل رقم 128. شكراً!"],
      ["c", `✅ تم توصيل طلبك رقم 128. شكراً لطلبك من ${d.shop}!`],
    ]],
    ["13:40", [
      ["c", "⭐ كيف كان طلبك؟ أجب برقم من 1 (سيء) إلى 5 (ممتاز)."],
      ["C", "5"],
      ["c", "شكراً على تقييمك! 🙏"],
    ]],
    ["15:10", [
      ["C", d.photoCaption, { photo: d.photo }],
      ["c", `👋 حدا من فريق ${d.shop} رح يرد عليك هون بعد شوي.`],
      ["s", "📷 ‎+961 71 234 567 بعت صورة. البوت موقّف بهالمحادثة، شوفها وردّ من تطبيق واتساب للأعمال.\nاضغط الزر لما تخلص.", { buttons: ["▶️ رجّع للبوت"] }],
      ["c", d.staffReply, { human: d.staffName }],
      ["C", d.customerThanks],
      ["S", "▶️ رجّع للبوت"],
      ["s", "🤖 البوت رجع يرد على ‎+961 71 234 567."],
    ]],
    ["16:20", [
      ["C", d.pickupAsk],
      ["c", "كيف بتحب تدفع؟", { buttons: ["💵 كاش بالمحل", "💳 Whish"] }],
      ["C", "💵 كاش بالمحل"],
      ["c", "بتدفع كاش بالدولار أو بالليرة؟", { buttons: ["دولار", "ليرة"] }],
      ["C", "ليرة"],
      ["c", `طلبك\n${d.pickupLine}\nالمجموع: ${n(d.pickupTotal)} (${d.pickupLbp})\nاستلام من ${d.shopAddress} · كاش بالمحل\nجاهز بحوالي 15 دقيقة`, { buttons: ["✅ تأكيد الطلب", "✏️ تعديل", "❌ إلغاء"] }],
      ["C", "✅ تأكيد الطلب"],
      ["c", `🧾 تم إرسال الطلب رقم 129 إلى ${d.shop}.\nالمجموع: ${n(d.pickupTotal)} (${d.pickupLbp})\nستصلك رسالة فور قبول المحل للطلب.`],
      ["s", `🔔 طلب استلام جديد رقم 129\n${d.pickupLine}\nالمجموع: ${d.pickupLbp} (كاش)\n📞 +961 71 234 567`, { buttons: ["✅ قبول · 15 د", "❌ ما فينا"] }],
      ["S", "✅ قبول · 15 د"],
      ["s", "👍 تم تحديث الطلب رقم 129.", { buttons: ["✅ جاهز للاستلام", "📦 تم الاستلام"] }],
      ["c", `✅ قبل ${d.shop} طلبك رقم 129، حوالي 15 دقيقة.`],
      ["S", "✅ جاهز للاستلام"],
      ["c", `📦 طلبك رقم 129 جاهز! فيك تستلمه هلق من ${d.shopAddress}.`],
      ["S", "📦 تم الاستلام"],
      ["c", `✅ شكراً لاستلام الطلب رقم 129 من ${d.shop}!`],
    ]],
    ["18:05", [
      ["C", d.bookingAsk],
      ["c", `🗓 المواعيد الفاضية لـ ${d.service} نهار ${d.bookingDay}:\n${d.slots}\nأي وقت بيناسبك؟`],
      ["C", d.time],
      ["c", `✅ تم الحجز: ${d.service} نهار ${d.bookingDay} الساعة ${d.time}${d.servicePrice} عند ${d.shop}، ${d.shopAddress}.\nرح نبعتلك تذكير. ابعت "إلغاء الموعد" إذا ما فيك تجي.`],
      ["s", `🗓 حجز جديد، ${d.shop}\n${d.service}\n${d.bookingDay} الساعة ${d.time}${d.servicePriceShort}\n📞 +961 71 234 567`, { buttons: ["✅ تم", "🚫 ما إجا"] }],
    ]],
    ["22:00", [
      ["S", "close"],
      ["s", `🔴 ${d.shop} مسكّر، البوت بيخبّر الزباين إنكن مسكرين.`],
      ["D", "stop"],
      ["d", `👋 انتهى دوامك يا كريم.\nالتوصيلات: ${d.driverOrders}\nالكاش يلي لازم تسلمه: ${d.driverCash}`],
      ["s", `📊 ${d.shop}، تقرير اليوم\nالطلبات المسلّمة: ${d.reportOrders}\nالمبيعات: ${n(d.reportSales)}\nمعدل التقييم: ${d.reportRating} ⭐\nطلبات متأخرة: 0\nالأكثر طلباً: ${d.topItems}`],
    ]],
    [d.reminderAt, [
      ["c", `⏰ تذكير: موعدك لـ ${d.service} عند ${d.shop} نهار ${d.bookingDay} الساعة ${d.time}. منشوفك قريباً!`, { day: d.bookingDay }],
      ["C", "منشوفكن 👍"],
      ["S", "✅ تم", { day: d.bookingDay, late: true }],
      ["s", `👍 تم تحديث ${d.service}.`],
    ]],
  ],

  fr: (d, n) => [
    ["07:58", [
      ["S", "open"],
      ["s", `🟢 ${d.shop} est OUVERT, le bot prend les commandes.`],
      ["D", "start"],
      ["d", "✅ Service commencé, Karim. Les nouvelles livraisons arriveront ici.\nEnvoyez \"stop\" à la fin."],
    ]],
    ["12:30", [
      ["C", "", { voice: "0:07" }],
      ["c", `Bien sûr ! 🙂\n${d.voiceLines}\nAutre chose ?`, { buttons: ["📋 Voir le menu", "🛒 Commander"] }],
    ]],
    ["12:31", [
      ["C", "📋 Voir le menu"],
      ["c", "Que souhaitez-vous ? Choisissez une catégorie 👇", { buttons: ["📋 Produits"] }],
      ["C", d.category],
      ["c", "Choisissez un article 👇", { buttons: ["📋 Voir les articles"] }],
      ["C", `${d.addName} · ${n(d.addPrice)}`],
      ["c", `✅ Ajouté 1× ${d.addName}\n🛒 Panier : ${d.cartCount} articles · ${n(d.subtotal)}`, { buttons: ["➕ Ajouter", "🛒 Commander"] }],
      ["C", "🛒 Commander"],
    ]],
    ["12:32", [
      ["c", "Dans quelle zone livrer ?", { buttons: ["Choisir"] }],
      ["C", `${d.town} · Livraison ${n(1)}`],
      ["c", "📍 Touchez le bouton pour envoyer votre position, ou écrivez l'adresse (rue, immeuble, étage)."],
      ["C", d.address],
      ["c", "Comment souhaitez-vous payer ?", { buttons: ["💵 Espèces à la livraison", "💳 Whish"] }],
      ["C", "💳 Whish"],
      ["c", `Votre commande\n${d.cartLines}\nLivraison (${d.town})  ${n(1)}\nTotal : ${n(d.total)}\nWhish · Prête dans ${d.eta} min environ`, { buttons: ["✅ Confirmer", "✏️ Modifier", "❌ Annuler"] }],
      ["C", "✅ Confirmer"],
      ["c", `🧾 Commande n° 128 envoyée à ${d.shop}.\nTotal : ${n(d.total)}\n💳 Vous recevrez ici le lien de paiement Whish dès que le commerce accepte votre commande.`],
    ]],
    ["12:34", [
      ["s", `🔔 Nouvelle commande n° 128\n${d.cartLines}\nTotal : ${n(d.total)} (Whish)\n📍 ${d.town}, ${d.address}\n📞 +961 71 234 567`, { buttons: [`✅ Accepter · ${d.eta} min`, "❌ Impossible"] }],
      ["S", `✅ Accepter · ${d.eta} min`],
      ["s", "👍 Commande n° 128 acceptée, le client a reçu le lien Whish. Vous recevrez la commande ici dès qu'elle est payée. Ne la préparez pas avant."],
    ]],
    ["12:35", [
      ["c", `✅ ${d.shop} a accepté votre commande n° 128 ! Payez ${n(d.total)} avec Whish ici :\nhttps://whish.money/pay/128xk\nNous la préparons dès que le paiement est confirmé (lien valable 30 minutes).`],
      ["c", `✅ Paiement reçu ! La commande n° 128 est confirmée, environ ${d.eta} min.`],
      ["s", `🔔 Commande n° 128 payée ✅ Préparez-la.\n${d.cartLines}\n🛵 Karim va la livrer.`],
    ]],
    ["12:55", [
      ["d", `🛵 Nouvelle livraison n° 128\n${d.cartShort}\nÀ encaisser : rien (payé avec Whish)\n📍 ${d.town}, ${d.address}\n📞 Client : +961 71 234 567`, { buttons: ["🚀 Récupérée"] }],
      ["D", "🚀 Récupérée"],
      ["d", "🛵 Livraison n° 128, en route\n💵 À encaisser : rien\nTouchez « Je suis devant » en arrivant.", { buttons: ["📍 Je suis devant", "✅ Livrée"] }],
      ["c", "🛵 Votre commande n° 128 est en route avec Karim, environ 10 min."],
      ["D", "📍 Je suis devant"],
      ["d", "👍 Le client sait que vous êtes devant."],
      ["c", "📍 Karim est devant chez vous avec la commande n° 128 ! 📞 +961 70 111 222"],
      ["D", "✅ Livrée"],
      ["d", "✅ Livraison n° 128 terminée. Merci !"],
      ["c", `✅ Votre commande n° 128 a été livrée. Merci d'avoir commandé chez ${d.shop} !`],
    ]],
    ["13:40", [
      ["c", "⭐ Qu'en avez-vous pensé ? Répondez avec un chiffre de 1 (mauvais) à 5 (excellent)."],
      ["C", "5"],
      ["c", "Merci pour votre note ! 🙏"],
    ]],
    ["15:10", [
      ["C", d.photoCaption, { photo: d.photo }],
      ["c", `👋 Un membre de l'équipe ${d.shop} va vous répondre ici très vite.`],
      ["s", "📷 +961 71 234 567 a envoyé une photo. Le bot est en pause dans cette discussion, voyez-la et répondez depuis WhatsApp Business.\nTouchez le bouton quand vous avez fini.", { buttons: ["▶️ Rendre au bot"] }],
      ["c", d.staffReply, { human: d.staffName }],
      ["C", d.customerThanks],
      ["S", "▶️ Rendre au bot"],
      ["s", "🤖 Le bot reprend la discussion avec +961 71 234 567."],
    ]],
    ["16:20", [
      ["C", d.pickupAsk],
      ["c", "Comment souhaitez-vous payer ?", { buttons: ["💵 Espèces au magasin", "💳 Whish"] }],
      ["C", "💵 Espèces au magasin"],
      ["c", "Payer en dollars ou en livres ?", { buttons: ["Dollars", "Livres"] }],
      ["C", "Livres"],
      ["c", `Votre commande\n${d.pickupLine}\nTotal : ${n(d.pickupTotal)} (${d.pickupLbp})\nRetrait à ${d.shopAddress} · Espèces au magasin\nPrête dans 15 min environ`, { buttons: ["✅ Confirmer", "✏️ Modifier", "❌ Annuler"] }],
      ["C", "✅ Confirmer"],
      ["c", `🧾 Commande n° 129 envoyée à ${d.shop}.\nTotal : ${n(d.pickupTotal)} (${d.pickupLbp})\nVous recevrez un message dès que le commerce l'accepte.`],
      ["s", `🔔 Nouvelle commande À RETIRER n° 129\n${d.pickupLine}\nTotal : ${d.pickupLbp} (espèces)\n📞 +961 71 234 567`, { buttons: ["✅ Accepter · 15 min", "❌ Impossible"] }],
      ["S", "✅ Accepter · 15 min"],
      ["s", "👍 Commande n° 129 mise à jour.", { buttons: ["✅ Prête à retirer", "📦 Récupérée"] }],
      ["c", `✅ ${d.shop} a accepté votre commande n° 129, environ 15 min.`],
      ["S", "✅ Prête à retirer"],
      ["c", `📦 Votre commande n° 129 est prête ! Vous pouvez la retirer à ${d.shopAddress}.`],
      ["S", "📦 Récupérée"],
      ["c", `✅ Merci d'avoir retiré la commande n° 129 chez ${d.shop} !`],
    ]],
    ["18:05", [
      ["C", d.bookingAsk],
      ["c", `🗓 Créneaux libres pour ${d.service} le ${d.bookingDay} :\n${d.slots}\nQuel horaire vous convient ?`],
      ["C", d.time],
      ["c", `✅ Réservé : ${d.service} le ${d.bookingDay} à ${d.time}${d.servicePrice} chez ${d.shop}, ${d.shopAddress}.\nNous vous enverrons un rappel. Répondez "annuler le rendez-vous" si vous ne pouvez pas venir.`],
      ["s", `🗓 Nouvelle réservation, ${d.shop}\n${d.service}\n${d.bookingDay} à ${d.time}${d.servicePriceShort}\n📞 +961 71 234 567`, { buttons: ["✅ Terminé", "🚫 Absent"] }],
    ]],
    ["22:00", [
      ["S", "close"],
      ["s", `🔴 ${d.shop} est FERMÉ, le bot prévient les clients.`],
      ["D", "stop"],
      ["d", `👋 Service terminé, Karim.\nLivraisons : ${d.driverOrders}\nEspèces à remettre : ${d.driverCash}`],
      ["s", `📊 ${d.shop}, rapport du jour\nCommandes livrées : ${d.reportOrders}\nVentes : ${n(d.reportSales)}\nNote moyenne : ${d.reportRating} ⭐\nCommandes en retard : 0\nLes plus demandés : ${d.topItems}`],
    ]],
    [d.reminderAt, [
      ["c", `⏰ Rappel : votre ${d.service} chez ${d.shop} est le ${d.bookingDay} à ${d.time}. À bientôt !`, { day: d.bookingDay }],
      ["C", "À tout à l'heure 👍"],
      ["S", "✅ Terminé", { day: d.bookingDay, late: true }],
      ["s", `👍 ${d.service} mis à jour.`],
    ]],
  ],
};

const MONEY = {
  en: (v) => `${v.toFixed(2)}$`,
  ar: (v) => `⁦${v.toFixed(2)}$⁩`,
  fr: (v) => `${v.toFixed(2).replace(".", ",")} $`,
};

function lbp(value, lang) {
  const amount = Math.round(value * LBP_RATE);
  if (lang === "fr") return `${amount.toLocaleString("fr-FR").replace(/\s/g, " ")} LBP`;
  return `${amount.toLocaleString("en-US")} ${lang === "ar" ? "ل.ل" : "LBP"}`;
}

function shopIn(shop, lang) {
  const t = (v) => v[lang];
  const n = MONEY[lang];
  const line = ([qty, price, name]) => `${qty}× ${t(name)}  ${n(qty * price)}`;
  const cart = [...shop.voice, shop.add];
  const subtotal = cart.reduce((sum, [qty, price]) => sum + qty * price, 0);
  const pickupTotal = shop.pickup[0] * shop.pickup[1];
  const [hour] = shop.time.split(":").map(Number);
  const [orders, sales, rating] = shop.report;
  return {
    shop: t(shop.shop), town: t(shop.town), address: t(shop.address), shopAddress: t(shop.shopAddress), eta: shop.eta,
    voiceLines: shop.voice.map(line).join("\n"),
    category: t(shop.category), addName: t(shop.add[2]), addPrice: shop.add[1],
    cartCount: cart.reduce((sum, [qty]) => sum + qty, 0), subtotal, total: subtotal + 1,
    cartLines: cart.map(line).join("\n"),
    cartShort: cart.map(([qty, , name]) => `${qty}× ${t(name)}`).join("\n"),
    photo: shop.photo, photoCaption: t(shop.photoCaption), staffName: t(shop.staffName),
    staffReply: lang === "ar" ? t(shop.staffReply).replace(/(\d[\d.,]*\$)/g, "⁦$1⁩") : t(shop.staffReply),
    customerThanks: t(shop.customerThanks),
    pickupAsk: t(shop.pickupAsk), pickupLine: line(shop.pickup), pickupTotal, pickupLbp: lbp(pickupTotal, lang),
    bookingAsk: t(shop.bookingAsk), service: t(shop.service), slots: shop.slots, time: shop.time, bookingDay: t(shop.bookingDay),
    servicePrice: shop.servicePrice ? ` (${n(shop.servicePrice)})` : "",
    servicePriceShort: shop.servicePrice ? ` · ${n(shop.servicePrice)}` : "",
    // The reminder goes out two hours before the booking.
    reminderAt: `${String(Math.max(hour - 2, 7)).padStart(2, "0")}:00`,
    reportOrders: orders, reportSales: sales, reportRating: lang === "fr" ? rating.replace(".", ",") : rating,
    // Karim is one of two drivers and brings back the cash of his share of the cash orders.
    driverOrders: Math.ceil(orders / 2),
    driverCash: lbp(sales * 0.3, lang),
    topItems: shop.voice.map(([, , name]) => t(name)).join(lang === "ar" ? "، " : ", "),
  };
}

function build(shop, lang) {
  const ui = UI[lang];
  const d = shopIn(shop, lang);
  const messages = [];
  SCRIPT[lang](d, MONEY[lang]).forEach(([at, list], block) => {
    const step = STEP_OF_BLOCK[block];
    const [h, m] = at.split(":").map(Number);
    list.forEach(([who, text, extra = {}], k) => {
      // The booked visit itself is later in the day than the reminder.
      const minutes = extra.late ? (Number(shop.time.split(":")[0]) + 1) * 60 : h * 60 + m + Math.floor(k / 3);
      messages.push({
        chat: who.toLowerCase(),
        out: who !== who.toLowerCase(),
        step,
        time: `${Math.floor(minutes / 60) % 24}:${String(minutes % 60).padStart(2, "0")}`,
        text,
        ...extra,
      });
    });
  });
  return { id: shop.id, icon: shop.icon, label: shop.label[lang], shop: d.shop, chats: ["c", "s", "d"], steps: ui.steps, messages };
}

export function orderDemo(lang) {
  const key = UI[lang] ? lang : "en";
  return { ...UI[key], scenarios: SHOPS.map((shop) => build(shop, key)) };
}
