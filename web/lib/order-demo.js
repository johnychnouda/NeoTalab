// The homepage demo: real NeoTalab conversations for a few kinds of shops, shown on the phones of
// the people involved. Each message is [chat, text, extras]:
//   lower-case chat = sent by the shop's NeoTalab number, upper-case = sent by that phone's owner
//   c / C  customer      s / S  shop owner or staff      d / D  driver
// extras: buttons (WhatsApp reply buttons), voice ("0:07"), photo (caption shown under it),
// human (a person from the shop answers instead of the bot), day (date chip before the message).

const UI = {
  en: {
    title: "See NeoTalab at work",
    lead: "Pick a kind of shop. Every message below is what NeoTalab really sends, on the customer's, the shop's and the driver's own WhatsApp.",
    online: "online",
    typing: "typing…",
    today: "Today",
    message: "Message",
    shows: "Shows",
    pause: "Pause",
    play: "Play",
    replay: "Replay",
    voice: "Voice message",
    who: { c: "Customer", s: "Shop owner", d: "Driver" },
  },
  ar: {
    title: "شوف NeoTalab عم يشتغل",
    lead: "اختار نوع المحل. كل رسالة هون هي يلي بيبعتها NeoTalab فعلياً، على واتساب الزبون والمحل والسائق.",
    online: "متصل",
    typing: "يكتب…",
    today: "اليوم",
    message: "مراسلة",
    shows: "بيورجي",
    pause: "إيقاف",
    play: "تشغيل",
    replay: "إعادة",
    voice: "رسالة صوتية",
    who: { c: "الزبون", s: "صاحب المحل", d: "السائق" },
  },
  fr: {
    title: "NeoTalab en action",
    lead: "Choisissez un type de commerce. Chaque message ci-dessous est ce que NeoTalab envoie vraiment, sur le WhatsApp du client, du commerce et du livreur.",
    online: "en ligne",
    typing: "écrit…",
    today: "Aujourd'hui",
    message: "Message",
    shows: "Montre",
    pause: "Pause",
    play: "Lecture",
    replay: "Rejouer",
    voice: "Message vocal",
    who: { c: "Client", s: "Commerçant", d: "Livreur" },
  },
};

const SCENARIOS = {
  en: [
    {
      id: "restaurant", icon: "🥙", label: "Restaurant", shop: "Foren Al Hara", chats: ["c", "s", "d"],
      shows: "menu inside the chat, cart, cash in dollars or lira, shop accepts, driver shift, “I'm outside”, rating, nightly report",
      steps: [
        { label: "Driver starts", at: "11:58", messages: [
          ["D", "start"],
          ["d", "✅ Shift started, Karim. New deliveries will come here.\nSend \"stop\" when you finish."],
        ] },
        { label: "Order", at: "12:30", messages: [
          ["C", "Marhaba 👋"],
          ["c", "Ahla w sahla at Foren Al Hara! 😊 What would you like? Choose a category 👇", { buttons: ["📋 View menu"] }],
          ["C", "🥙 Man'oushe"],
          ["c", "Choose an item 👇", { buttons: ["📋 See items"] }],
          ["C", "Zaatar man'oushe · 1.00$"],
          ["c", "How many Zaatar man'oushe?"],
          ["C", "2"],
          ["c", "✅ Added 2× Zaatar man'oushe\n🛒 Cart: 2 items · 2.00$", { buttons: ["➕ Add more", "🛒 Checkout"] }],
          ["C", "w zid 1 jebne please"],
          ["c", "✅ Added 1× Cheese man'oushe\n🛒 Cart: 3 items · 4.50$", { buttons: ["➕ Add more", "🛒 Checkout"] }],
          ["C", "🛒 Checkout"],
          ["c", "Which area should we deliver to?", { buttons: ["Choose"] }],
          ["C", "Jbeil · Delivery 1.00$"],
          ["c", "📍 Tap the button to send your location, or type your address (street, building, floor)."],
          ["C", "Main street, near the church, building Haddad, 2nd floor"],
          ["c", "How would you like to pay?", { buttons: ["💵 Cash on delivery", "💳 Whish"] }],
          ["C", "💵 Cash on delivery"],
          ["c", "Pay cash in dollars or in lira?", { buttons: ["Dollars", "Lira"] }],
          ["C", "Lira"],
          ["c", "Your order\n2× Zaatar man'oushe  2.00$\n1× Cheese man'oushe  2.50$\nDelivery (Jbeil)  1.00$\nTotal: 5.50$ (492,250 LBP)\nCash on delivery · Ready in about 25 min", { buttons: ["✅ Confirm order", "✏️ Edit order", "❌ Cancel"] }],
          ["C", "✅ Confirm order"],
          ["c", "🧾 Order #128 sent to Foren Al Hara.\nTotal: 5.50$ (492,250 LBP)\nYou will get a message as soon as the shop accepts it."],
        ] },
        { label: "Shop accepts", at: "12:34", messages: [
          ["s", "🔔 New order #128\n2× Zaatar man'oushe\n1× Cheese man'oushe\nTotal: 492,250 LBP (cash)\n📍 Jbeil, main street, near the church\n📞 +961 71 234 567", { buttons: ["✅ Accept · 25 min", "❌ Can't take it"] }],
          ["S", "✅ Accept · 25 min"],
          ["s", "👍 Order #128 updated."],
          ["c", "✅ Foren Al Hara accepted your order #128, about 25 min."],
        ] },
        { label: "On the way", at: "12:52", messages: [
          ["d", "🛵 New delivery #128\n2× Zaatar, 1× Cheese\nCollect: 492,250 LBP (cash)\n📍 Jbeil, main street, near the church\n📞 Customer: +961 71 234 567", { buttons: ["🚀 Picked up"] }],
          ["D", "🚀 Picked up"],
          ["d", "🛵 Delivery #128, on the way\n💵 Collect: 492,250 LBP\nTap “I'm outside” when you arrive.", { buttons: ["📍 I'm outside", "✅ Delivered"] }],
          ["c", "🛵 Your order #128 is on its way with Karim, about 10 min."],
        ] },
        { label: "At the door", at: "13:03", messages: [
          ["D", "📍 I'm outside"],
          ["d", "👍 The customer was told you are outside."],
          ["c", "📍 Karim is outside with your order #128! 📞 +961 70 111 222"],
          ["D", "✅ Delivered"],
          ["d", "✅ Delivery #128 completed. Thanks!"],
          ["c", "✅ Your order #128 was delivered. Thank you for ordering from Foren Al Hara!"],
        ] },
        { label: "Rating", at: "13:35", messages: [
          ["c", "⭐ How was it? Reply with a number from 1 (bad) to 5 (excellent)."],
          ["C", "5"],
          ["c", "Thank you for your rating! 🙏"],
        ] },
        { label: "End of day", at: "22:01", messages: [
          ["D", "stop"],
          ["d", "👋 Shift ended, Karim.\nDeliveries: 14\nCash to hand over: 3,850,000 LBP"],
          ["s", "📊 Foren Al Hara, daily report\nOrders delivered: 14\nSales: 81.00$\nAverage rating: 4.8 ⭐\nLate orders: 0\nTop items: Zaatar man'oushe, Cheese man'oushe"],
        ] },
      ],
    },
    {
      id: "pharmacy", icon: "💊", label: "Pharmacy", shop: "Pharmacie Al Shifa", chats: ["c", "s"],
      shows: "ordering by voice note, Whish payment link after the shop accepts, a photo handed to a real person, delivery by the shop",
      steps: [
        { label: "Voice order", at: "18:10", messages: [
          ["C", "", { voice: "0:07" }],
          ["c", "Sure! 🙂\n1× Panadol Extra  4.00$\n1× Vitamin C 1000  6.00$\nDelivery or pickup?"],
          ["C", "Delivery, Achrafieh, Sursock street, building 12, 3rd floor"],
          ["c", "How would you like to pay?", { buttons: ["💵 Cash on delivery", "💳 Whish"] }],
          ["C", "💳 Whish"],
          ["c", "Your order\n1× Panadol Extra  4.00$\n1× Vitamin C 1000  6.00$\nDelivery (Achrafieh)  1.00$\nTotal: 11.00$\nWhish · Ready in about 40 min", { buttons: ["✅ Confirm order", "✏️ Edit order", "❌ Cancel"] }],
          ["C", "✅ Confirm order"],
          ["c", "🧾 Order #57 sent to Pharmacie Al Shifa.\nTotal: 11.00$\n💳 You will get the Whish payment link here as soon as the shop accepts your order."],
        ] },
        { label: "Shop accepts", at: "18:12", messages: [
          ["s", "🔔 New order #57\n1× Panadol Extra\n1× Vitamin C 1000\nTotal: 11.00$ (Whish)\n📍 Achrafieh, Sursock street, building 12\n📞 +961 3 456 789", { buttons: ["✅ Accept · 40 min", "❌ Can't take it"] }],
          ["S", "✅ Accept · 40 min"],
          ["s", "👍 Order #57 accepted, the customer got the Whish link. You will get the order here again as soon as it is paid. Don't prepare it before."],
        ] },
        { label: "Whish payment", at: "18:13", messages: [
          ["c", "✅ Pharmacie Al Shifa accepted your order #57! Pay 11.00$ with Whish here:\nhttps://whish.money/pay/57xk\nWe start preparing it as soon as the payment is confirmed (link valid 30 minutes)."],
          ["c", "✅ Payment received! Order #57 is confirmed, about 40 min."],
          ["s", "🔔 Order #57 is paid ✅\n1× Panadol Extra\n1× Vitamin C 1000\nTotal: 11.00$ (Whish)\n📍 Achrafieh, Sursock street, building 12", { buttons: ["🛵 Out for delivery", "✅ Delivered"] }],
          ["S", "🛵 Out for delivery"],
          ["c", "🛵 Your order #57 is on its way!"],
        ] },
        { label: "Talk to a person", at: "18:31", messages: [
          ["C", "Do you also have this one?", { photo: true }],
          ["c", "👋 A member of the Pharmacie Al Shifa team will reply to you here shortly."],
          ["s", "📷 +961 3 456 789 sent a photo. The bot is paused in that chat, see it and answer in your WhatsApp Business app.\nTap the button when you're done.", { buttons: ["▶️ Give back to bot"] }],
          ["c", "Yes, we have it, 8.50$. The driver will bring it with your order 🙂", { human: "Rita, pharmacist" }],
          ["C", "Perfect, thank you!"],
          ["S", "▶️ Give back to bot"],
          ["s", "🤖 The bot is handling +961 3 456 789 again."],
        ] },
        { label: "Delivered", at: "18:50", messages: [
          ["S", "✅ Delivered"],
          ["s", "👍 Order #57 updated."],
          ["c", "✅ Your order #57 was delivered. Thank you for ordering from Pharmacie Al Shifa!"],
        ] },
      ],
    },
    {
      id: "salon", icon: "💇", label: "Salon", shop: "Beauty Corner", chats: ["c", "s"],
      shows: "booking a free time, the shop is notified, automatic reminder, Done / No-show, rating",
      steps: [
        { label: "Booking", at: "20:15", messages: [
          ["C", "Hi! Can I book a haircut and blow-dry tomorrow afternoon?"],
          ["c", "🗓 Free times for Haircut & blow-dry on Wed 15 Oct:\n14:00 · 15:30 · 16:30 · 17:30\nWhich time suits you?"],
          ["C", "16:30"],
          ["c", "✅ Booked: Haircut & blow-dry on Wed 15 Oct at 16:30 (25.00$) at Beauty Corner, Jounieh.\nWe'll send you a reminder. Reply \"cancel appointment\" if you can't make it."],
        ] },
        { label: "Shop notified", at: "20:16", messages: [
          ["s", "🗓 New booking, Beauty Corner\nHaircut & blow-dry\nWed 15 Oct at 16:30 · 25.00$\n📞 +961 76 555 010", { buttons: ["✅ Done", "🚫 No-show"] }],
        ] },
        { label: "Reminder", at: "14:30", messages: [
          ["c", "⏰ Reminder: your Haircut & blow-dry at Beauty Corner is on Wed 15 Oct at 16:30. See you soon!", { day: "Wed 15 Oct" }],
          ["C", "See you there 👍"],
        ] },
        { label: "Done", at: "17:20", messages: [
          ["S", "✅ Done", { day: "Wed 15 Oct" }],
          ["s", "👍 Haircut & blow-dry updated."],
        ] },
        { label: "Rating", at: "18:20", messages: [
          ["c", "⭐ How was it? Reply with a number from 1 (bad) to 5 (excellent)."],
          ["C", "5"],
          ["c", "Thank you for your rating! 🙏"],
        ] },
      ],
    },
    {
      id: "market", icon: "🛒", label: "Mini market", shop: "Mini Market Abou Elie", chats: ["c", "s"],
      shows: "opening and closing the shop from WhatsApp, typing in any mix of languages, pickup order, ready, collected",
      steps: [
        { label: "Shop opens", at: "07:58", messages: [
          ["S", "open"],
          ["s", "🟢 Mini Market Abou Elie is OPEN, the bot is taking orders."],
        ] },
        { label: "Order", at: "09:12", messages: [
          ["C", "bonjour, 3andak labne w 2 khebz?"],
          ["c", "Yes! 🙂\n1× Labneh 500g  3.50$\n2× Arabic bread  1.60$\nDelivery or pickup?"],
          ["C", "pickup, I'll pass in 15 min"],
          ["c", "Your order\n1× Labneh 500g  3.50$\n2× Arabic bread  1.60$\nTotal: 5.10$\nPickup at Zalka, main road · Cash at the shop\nReady in about 15 min", { buttons: ["✅ Confirm order", "✏️ Edit order", "❌ Cancel"] }],
          ["C", "✅ Confirm order"],
          ["c", "🧾 Order #312 sent to Mini Market Abou Elie.\nTotal: 5.10$\nYou will get a message as soon as the shop accepts it."],
        ] },
        { label: "Shop accepts", at: "09:13", messages: [
          ["s", "🔔 New PICKUP order #312\n1× Labneh 500g\n2× Arabic bread\nTotal: 5.10$ (cash)\n📞 +961 71 909 090", { buttons: ["✅ Accept · 15 min", "❌ Can't take it"] }],
          ["S", "✅ Accept · 15 min"],
          ["s", "👍 Order #312 updated.", { buttons: ["✅ Ready for pickup", "📦 Collected"] }],
          ["c", "✅ Mini Market Abou Elie accepted your order #312, ready in about 15 min."],
        ] },
        { label: "Pickup", at: "09:24", messages: [
          ["S", "✅ Ready for pickup"],
          ["c", "📦 Your order #312 is ready! You can pick it up now at Zalka, main road."],
          ["S", "📦 Collected"],
          ["c", "✅ Thank you for picking up order #312 from Mini Market Abou Elie!"],
        ] },
        { label: "Shop closes", at: "21:00", messages: [
          ["S", "close"],
          ["s", "🔴 Mini Market Abou Elie is CLOSED, the bot tells customers you are closed."],
          ["C", "3andak khebz?"],
          ["c", "Sorry, we're closed right now 🌙 We open again tomorrow at 8:00."],
        ] },
      ],
    },
  ],

  ar: [
    {
      id: "restaurant", icon: "🥙", label: "مطعم", shop: "فرن الحارة", chats: ["c", "s", "d"],
      shows: "القائمة داخل المحادثة، السلة، الدفع كاش بالدولار أو بالليرة، قبول المحل، دوام السائق، «أنا برا»، التقييم، التقرير اليومي",
      steps: [
        { label: "السائق يبدأ", at: "11:58", messages: [
          ["D", "start"],
          ["d", "✅ بدأ دوامك يا كريم. التوصيلات الجديدة رح توصلك هون.\nابعت \"stop\" لما تخلص."],
        ] },
        { label: "الطلب", at: "12:30", messages: [
          ["C", "مرحبا 👋"],
          ["c", "أهلا وسهلا بفرن الحارة! 😊 شو بتحب تطلب؟ اختار فئة 👇", { buttons: ["📋 عرض القائمة"] }],
          ["C", "🥙 مناقيش"],
          ["c", "اختار صنف 👇", { buttons: ["📋 شوف الأصناف"] }],
          ["C", "منقوشة زعتر · 1.00$"],
          ["c", "قديش منقوشة زعتر؟"],
          ["C", "2"],
          ["c", "✅ انضاف 2× منقوشة زعتر\n🛒 السلة: 2 أصناف · 2.00$", { buttons: ["➕ زيد", "🛒 إتمام الطلب"] }],
          ["C", "و زيد 1 جبنة"],
          ["c", "✅ انضاف 1× منقوشة جبنة\n🛒 السلة: 3 أصناف · 4.50$", { buttons: ["➕ زيد", "🛒 إتمام الطلب"] }],
          ["C", "🛒 إتمام الطلب"],
          ["c", "لأي منطقة منوصّل؟", { buttons: ["اختار"] }],
          ["C", "جبيل · التوصيل 1.00$"],
          ["c", "📍 اضغط الزر لتبعت موقعك، أو اكتب العنوان (الشارع، البناية، الطابق)."],
          ["C", "الشارع العام حد الكنيسة، بناية حداد، الطابق الثاني"],
          ["c", "كيف بتحب تدفع؟", { buttons: ["💵 كاش عند التسليم", "💳 Whish"] }],
          ["C", "💵 كاش عند التسليم"],
          ["c", "بتدفع كاش بالدولار أو بالليرة؟", { buttons: ["دولار", "ليرة"] }],
          ["C", "ليرة"],
          ["c", "طلبك\n2× منقوشة زعتر  2.00$\n1× منقوشة جبنة  2.50$\nالتوصيل (جبيل)  1.00$\nالمجموع: 5.50$ (492,250 ل.ل)\nكاش عند التسليم · جاهز بحوالي 25 دقيقة", { buttons: ["✅ تأكيد الطلب", "✏️ تعديل", "❌ إلغاء"] }],
          ["C", "✅ تأكيد الطلب"],
          ["c", "🧾 تم إرسال الطلب رقم 128 إلى فرن الحارة.\nالمجموع: 5.50$ (492,250 ل.ل)\nستصلك رسالة فور قبول المحل للطلب."],
        ] },
        { label: "المحل يقبل", at: "12:34", messages: [
          ["s", "🔔 طلب جديد رقم 128\n2× منقوشة زعتر\n1× منقوشة جبنة\nالمجموع: 492,250 ل.ل (كاش)\n📍 جبيل، الشارع العام حد الكنيسة\n📞 +961 71 234 567", { buttons: ["✅ قبول · 25 د", "❌ ما فينا"] }],
          ["S", "✅ قبول · 25 د"],
          ["s", "👍 تم تحديث الطلب رقم 128."],
          ["c", "✅ قبل فرن الحارة طلبك رقم 128، حوالي 25 دقيقة."],
        ] },
        { label: "بالطريق", at: "12:52", messages: [
          ["d", "🛵 توصيل جديد رقم 128\n2× زعتر، 1× جبنة\nالمبلغ: 492,250 ل.ل (كاش)\n📍 جبيل، الشارع العام حد الكنيسة\n📞 الزبون: +961 71 234 567", { buttons: ["🚀 استلمت"] }],
          ["D", "🚀 استلمت"],
          ["d", "🛵 التوصيل رقم 128، في الطريق\n💵 المبلغ: 492,250 ل.ل\nاضغط «أنا برا» لما توصل.", { buttons: ["📍 أنا برا", "✅ تم التوصيل"] }],
          ["c", "🛵 طلبك رقم 128 في الطريق مع كريم، حوالي 10 دقائق."],
        ] },
        { label: "عالباب", at: "13:03", messages: [
          ["D", "📍 أنا برا"],
          ["d", "👍 خبّرنا الزبون إنك برا."],
          ["c", "📍 كريم برا مع طلبك رقم 128! 📞 +961 70 111 222"],
          ["D", "✅ تم التوصيل"],
          ["d", "✅ تم التوصيل رقم 128. شكراً!"],
          ["c", "✅ تم توصيل طلبك رقم 128. شكراً لطلبك من فرن الحارة!"],
        ] },
        { label: "التقييم", at: "13:35", messages: [
          ["c", "⭐ كيف كان طلبك؟ أجب برقم من 1 (سيء) إلى 5 (ممتاز)."],
          ["C", "5"],
          ["c", "شكراً على تقييمك! 🙏"],
        ] },
        { label: "آخر النهار", at: "22:01", messages: [
          ["D", "stop"],
          ["d", "👋 انتهى دوامك يا كريم.\nالتوصيلات: 14\nالكاش يلي لازم تسلمه: 3,850,000 ل.ل"],
          ["s", "📊 فرن الحارة، تقرير اليوم\nالطلبات المسلّمة: 14\nالمبيعات: 81.00$\nمعدل التقييم: 4.8 ⭐\nطلبات متأخرة: 0\nالأكثر طلباً: منقوشة زعتر، منقوشة جبنة"],
        ] },
      ],
    },
    {
      id: "pharmacy", icon: "💊", label: "صيدلية", shop: "صيدلية الشفاء", chats: ["c", "s"],
      shows: "الطلب برسالة صوتية، رابط دفع Whish بعد قبول المحل، صورة بتنحوّل لشخص حقيقي، توصيل من المحل",
      steps: [
        { label: "طلب صوتي", at: "18:10", messages: [
          ["C", "", { voice: "0:07" }],
          ["c", "أكيد! 🙂\n1× Panadol Extra  4.00$\n1× Vitamin C 1000  6.00$\nتوصيل أو استلام؟"],
          ["C", "توصيل، الأشرفية، شارع سرسق، بناية 12، الطابق الثالث"],
          ["c", "كيف بتحب تدفع؟", { buttons: ["💵 كاش عند التسليم", "💳 Whish"] }],
          ["C", "💳 Whish"],
          ["c", "طلبك\n1× Panadol Extra  4.00$\n1× Vitamin C 1000  6.00$\nالتوصيل (الأشرفية)  1.00$\nالمجموع: 11.00$\nWhish · جاهز بحوالي 40 دقيقة", { buttons: ["✅ تأكيد الطلب", "✏️ تعديل", "❌ إلغاء"] }],
          ["C", "✅ تأكيد الطلب"],
          ["c", "🧾 تم إرسال الطلب رقم 57 إلى صيدلية الشفاء.\nالمجموع: 11.00$\n💳 رح يوصلك رابط الدفع بـ Whish هون أول ما المحل يقبل طلبك."],
        ] },
        { label: "المحل يقبل", at: "18:12", messages: [
          ["s", "🔔 طلب جديد رقم 57\n1× Panadol Extra\n1× Vitamin C 1000\nالمجموع: 11.00$ (Whish)\n📍 الأشرفية، شارع سرسق، بناية 12\n📞 +961 3 456 789", { buttons: ["✅ قبول · 40 د", "❌ ما فينا"] }],
          ["S", "✅ قبول · 40 د"],
          ["s", "👍 تم قبول الطلب رقم 57، والزبون وصله رابط Whish. رح يرجعلك الطلب هون أول ما ينْدفع. لا تحضّره قبل."],
        ] },
        { label: "دفع Whish", at: "18:13", messages: [
          ["c", "✅ صيدلية الشفاء قبلت طلبك رقم 57! ادفع 11.00$ بـ Whish من هون:\nhttps://whish.money/pay/57xk\nمنبلش نحضّره أول ما يتأكد الدفع (الرابط صالح 30 دقيقة)."],
          ["c", "✅ وصل الدفع! الطلب رقم 57 تأكد، حوالي 40 دقيقة."],
          ["s", "🔔 الطلب رقم 57 مدفوع ✅\n1× Panadol Extra\n1× Vitamin C 1000\nالمجموع: 11.00$ (Whish)\n📍 الأشرفية، شارع سرسق، بناية 12", { buttons: ["🛵 طلع للتوصيل", "✅ تم التوصيل"] }],
          ["S", "🛵 طلع للتوصيل"],
          ["c", "🛵 طلبك رقم 57 في الطريق!"],
        ] },
        { label: "حكي مع شخص", at: "18:31", messages: [
          ["C", "عندكن كمان هيدا؟", { photo: true }],
          ["c", "👋 حدا من فريق صيدلية الشفاء رح يرد عليك هون بعد شوي."],
          ["s", "📷 ‎+961 3 456 789 بعت صورة. البوت موقّف بهالمحادثة، شوفها وردّ من تطبيق واتساب للأعمال.\nاضغط الزر لما تخلص.", { buttons: ["▶️ رجّع للبوت"] }],
          ["c", "إيه موجود، 8.50$. السائق بيجيبلك ياه مع الطلب 🙂", { human: "ريتا، صيدلانية" }],
          ["C", "تمام، شكراً!"],
          ["S", "▶️ رجّع للبوت"],
          ["s", "🤖 البوت رجع يرد على ‎+961 3 456 789."],
        ] },
        { label: "التوصيل", at: "18:50", messages: [
          ["S", "✅ تم التوصيل"],
          ["s", "👍 تم تحديث الطلب رقم 57."],
          ["c", "✅ تم توصيل طلبك رقم 57. شكراً لطلبك من صيدلية الشفاء!"],
        ] },
      ],
    },
    {
      id: "salon", icon: "💇", label: "صالون", shop: "Beauty Corner", chats: ["c", "s"],
      shows: "حجز موعد فاضي، المحل بيوصله خبر، تذكير تلقائي، تم / ما إجا، التقييم",
      steps: [
        { label: "الحجز", at: "20:15", messages: [
          ["C", "مرحبا! فيي احجز قص وسشوار بكرا بعد الضهر؟"],
          ["c", "🗓 المواعيد الفاضية لـ قص وسشوار نهار الأربعاء 15 تشرين الأول:\n14:00 · 15:30 · 16:30 · 17:30\nأي وقت بيناسبك؟"],
          ["C", "16:30"],
          ["c", "✅ تم الحجز: قص وسشوار نهار الأربعاء 15 تشرين الأول الساعة 16:30 (25.00$) عند Beauty Corner، جونيه.\nرح نبعتلك تذكير. ابعت \"إلغاء الموعد\" إذا ما فيك تجي."],
        ] },
        { label: "خبر للمحل", at: "20:16", messages: [
          ["s", "🗓 حجز جديد، Beauty Corner\nقص وسشوار\nالأربعاء 15 تشرين الأول الساعة 16:30 · 25.00$\n📞 +961 76 555 010", { buttons: ["✅ تم", "🚫 ما إجا"] }],
        ] },
        { label: "التذكير", at: "14:30", messages: [
          ["c", "⏰ تذكير: موعدك لـ قص وسشوار عند Beauty Corner نهار الأربعاء 15 تشرين الأول الساعة 16:30. منشوفك قريباً!", { day: "الأربعاء 15 تشرين الأول" }],
          ["C", "منشوفكن 👍"],
        ] },
        { label: "تم", at: "17:20", messages: [
          ["S", "✅ تم", { day: "الأربعاء 15 تشرين الأول" }],
          ["s", "👍 تم تحديث قص وسشوار."],
        ] },
        { label: "التقييم", at: "18:20", messages: [
          ["c", "⭐ كيف كان طلبك؟ أجب برقم من 1 (سيء) إلى 5 (ممتاز)."],
          ["C", "5"],
          ["c", "شكراً على تقييمك! 🙏"],
        ] },
      ],
    },
    {
      id: "market", icon: "🛒", label: "ميني ماركت", shop: "ميني ماركت أبو إيلي", chats: ["c", "s"],
      shows: "فتح وسكّر المحل من واتساب، الكتابة بأي خليط لغات، طلب استلام، جاهز، تم الاستلام",
      steps: [
        { label: "فتح المحل", at: "07:58", messages: [
          ["S", "open"],
          ["s", "🟢 ميني ماركت أبو إيلي مفتوح، البوت عم ياخد طلبات."],
        ] },
        { label: "الطلب", at: "09:12", messages: [
          ["C", "bonjour, 3andak labne w 2 khebz?"],
          ["c", "أكيد! 🙂\n1× لبنة 500غ  3.50$\n2× خبز عربي  1.60$\nتوصيل أو استلام؟"],
          ["C", "استلام، بمرق بعد ربع ساعة"],
          ["c", "طلبك\n1× لبنة 500غ  3.50$\n2× خبز عربي  1.60$\nالمجموع: 5.10$\nاستلام من الزلقا، الطريق العام · كاش بالمحل\nجاهز بحوالي 15 دقيقة", { buttons: ["✅ تأكيد الطلب", "✏️ تعديل", "❌ إلغاء"] }],
          ["C", "✅ تأكيد الطلب"],
          ["c", "🧾 تم إرسال الطلب رقم 312 إلى ميني ماركت أبو إيلي.\nالمجموع: 5.10$\nستصلك رسالة فور قبول المحل للطلب."],
        ] },
        { label: "المحل يقبل", at: "09:13", messages: [
          ["s", "🔔 طلب استلام جديد رقم 312\n1× لبنة 500غ\n2× خبز عربي\nالمجموع: 5.10$ (كاش)\n📞 +961 71 909 090", { buttons: ["✅ قبول · 15 د", "❌ ما فينا"] }],
          ["S", "✅ قبول · 15 د"],
          ["s", "👍 تم تحديث الطلب رقم 312.", { buttons: ["✅ جاهز للاستلام", "📦 تم الاستلام"] }],
          ["c", "✅ قبل ميني ماركت أبو إيلي طلبك رقم 312، جاهز بحوالي 15 دقيقة."],
        ] },
        { label: "الاستلام", at: "09:24", messages: [
          ["S", "✅ جاهز للاستلام"],
          ["c", "📦 طلبك رقم 312 جاهز! فيك تستلمه هلق من الزلقا، الطريق العام."],
          ["S", "📦 تم الاستلام"],
          ["c", "✅ شكراً لاستلام الطلب رقم 312 من ميني ماركت أبو إيلي!"],
        ] },
        { label: "سكّر المحل", at: "21:00", messages: [
          ["S", "close"],
          ["s", "🔴 ميني ماركت أبو إيلي مسكّر، البوت بيخبّر الزباين إنكن مسكرين."],
          ["C", "3andak khebz?"],
          ["c", "عذراً، نحنا مسكرين هلق 🌙 منفتح بكرا الساعة 8:00."],
        ] },
      ],
    },
  ],

  fr: [
    {
      id: "restaurant", icon: "🥙", label: "Restaurant", shop: "Foren Al Hara", chats: ["c", "s", "d"],
      shows: "menu dans la discussion, panier, espèces en dollars ou en livres, acceptation par le commerce, service du livreur, « Je suis devant », note, rapport du soir",
      steps: [
        { label: "Livreur en service", at: "11:58", messages: [
          ["D", "start"],
          ["d", "✅ Service commencé, Karim. Les nouvelles livraisons arriveront ici.\nEnvoyez \"stop\" à la fin."],
        ] },
        { label: "Commande", at: "12:30", messages: [
          ["C", "Bonjour 👋"],
          ["c", "Bienvenue chez Foren Al Hara ! 😊 Que souhaitez-vous ? Choisissez une catégorie 👇", { buttons: ["📋 Voir le menu"] }],
          ["C", "🥙 Man'oushé"],
          ["c", "Choisissez un article 👇", { buttons: ["📋 Voir les articles"] }],
          ["C", "Man'oushé zaatar · 1,00 $"],
          ["c", "Combien de Man'oushé zaatar ?"],
          ["C", "2"],
          ["c", "✅ Ajouté 2× Man'oushé zaatar\n🛒 Panier : 2 articles · 2,00 $", { buttons: ["➕ Ajouter", "🛒 Commander"] }],
          ["C", "et 1 au fromage svp"],
          ["c", "✅ Ajouté 1× Man'oushé fromage\n🛒 Panier : 3 articles · 4,50 $", { buttons: ["➕ Ajouter", "🛒 Commander"] }],
          ["C", "🛒 Commander"],
          ["c", "Dans quelle zone livrer ?", { buttons: ["Choisir"] }],
          ["C", "Jbeil · Livraison 1,00 $"],
          ["c", "📍 Touchez le bouton pour envoyer votre position, ou écrivez l'adresse (rue, immeuble, étage)."],
          ["C", "Rue principale près de l'église, immeuble Haddad, 2e étage"],
          ["c", "Comment souhaitez-vous payer ?", { buttons: ["💵 Espèces à la livraison", "💳 Whish"] }],
          ["C", "💵 Espèces à la livraison"],
          ["c", "Payer en dollars ou en livres ?", { buttons: ["Dollars", "Livres"] }],
          ["C", "Livres"],
          ["c", "Votre commande\n2× Man'oushé zaatar  2,00 $\n1× Man'oushé fromage  2,50 $\nLivraison (Jbeil)  1,00 $\nTotal : 5,50 $ (492 250 LBP)\nEspèces à la livraison · Prête dans 25 min environ", { buttons: ["✅ Confirmer", "✏️ Modifier", "❌ Annuler"] }],
          ["C", "✅ Confirmer"],
          ["c", "🧾 Commande n° 128 envoyée à Foren Al Hara.\nTotal : 5,50 $ (492 250 LBP)\nVous recevrez un message dès que le commerce l'accepte."],
        ] },
        { label: "Acceptée", at: "12:34", messages: [
          ["s", "🔔 Nouvelle commande n° 128\n2× Man'oushé zaatar\n1× Man'oushé fromage\nTotal : 492 250 LBP (espèces)\n📍 Jbeil, rue principale près de l'église\n📞 +961 71 234 567", { buttons: ["✅ Accepter · 25 min", "❌ Impossible"] }],
          ["S", "✅ Accepter · 25 min"],
          ["s", "👍 Commande n° 128 mise à jour."],
          ["c", "✅ Foren Al Hara a accepté votre commande n° 128, environ 25 min."],
        ] },
        { label: "En route", at: "12:52", messages: [
          ["d", "🛵 Nouvelle livraison n° 128\n2× Zaatar, 1× Fromage\nÀ encaisser : 492 250 LBP (espèces)\n📍 Jbeil, rue principale près de l'église\n📞 Client : +961 71 234 567", { buttons: ["🚀 Récupérée"] }],
          ["D", "🚀 Récupérée"],
          ["d", "🛵 Livraison n° 128, en route\n💵 À encaisser : 492 250 LBP\nTouchez « Je suis devant » en arrivant.", { buttons: ["📍 Je suis devant", "✅ Livrée"] }],
          ["c", "🛵 Votre commande n° 128 est en route avec Karim, environ 10 min."],
        ] },
        { label: "À la porte", at: "13:03", messages: [
          ["D", "📍 Je suis devant"],
          ["d", "👍 Le client sait que vous êtes devant."],
          ["c", "📍 Karim est devant chez vous avec la commande n° 128 ! 📞 +961 70 111 222"],
          ["D", "✅ Livrée"],
          ["d", "✅ Livraison n° 128 terminée. Merci !"],
          ["c", "✅ Votre commande n° 128 a été livrée. Merci d'avoir commandé chez Foren Al Hara !"],
        ] },
        { label: "Note", at: "13:35", messages: [
          ["c", "⭐ Qu'en avez-vous pensé ? Répondez avec un chiffre de 1 (mauvais) à 5 (excellent)."],
          ["C", "5"],
          ["c", "Merci pour votre note ! 🙏"],
        ] },
        { label: "Fin de journée", at: "22:01", messages: [
          ["D", "stop"],
          ["d", "👋 Service terminé, Karim.\nLivraisons : 14\nEspèces à remettre : 3 850 000 LBP"],
          ["s", "📊 Foren Al Hara, rapport du jour\nCommandes livrées : 14\nVentes : 81,00 $\nNote moyenne : 4,8 ⭐\nCommandes en retard : 0\nLes plus demandés : Man'oushé zaatar, Man'oushé fromage"],
        ] },
      ],
    },
    {
      id: "pharmacy", icon: "💊", label: "Pharmacie", shop: "Pharmacie Al Shifa", chats: ["c", "s"],
      shows: "commande par message vocal, lien de paiement Whish après acceptation, photo transmise à une vraie personne, livraison par le commerce",
      steps: [
        { label: "Commande vocale", at: "18:10", messages: [
          ["C", "", { voice: "0:07" }],
          ["c", "Bien sûr ! 🙂\n1× Panadol Extra  4,00 $\n1× Vitamine C 1000  6,00 $\nLivraison ou retrait ?"],
          ["C", "Livraison, Achrafieh, rue Sursock, immeuble 12, 3e étage"],
          ["c", "Comment souhaitez-vous payer ?", { buttons: ["💵 Espèces à la livraison", "💳 Whish"] }],
          ["C", "💳 Whish"],
          ["c", "Votre commande\n1× Panadol Extra  4,00 $\n1× Vitamine C 1000  6,00 $\nLivraison (Achrafieh)  1,00 $\nTotal : 11,00 $\nWhish · Prête dans 40 min environ", { buttons: ["✅ Confirmer", "✏️ Modifier", "❌ Annuler"] }],
          ["C", "✅ Confirmer"],
          ["c", "🧾 Commande n° 57 envoyée à Pharmacie Al Shifa.\nTotal : 11,00 $\n💳 Vous recevrez ici le lien de paiement Whish dès que le commerce accepte votre commande."],
        ] },
        { label: "Acceptée", at: "18:12", messages: [
          ["s", "🔔 Nouvelle commande n° 57\n1× Panadol Extra\n1× Vitamine C 1000\nTotal : 11,00 $ (Whish)\n📍 Achrafieh, rue Sursock, immeuble 12\n📞 +961 3 456 789", { buttons: ["✅ Accepter · 40 min", "❌ Impossible"] }],
          ["S", "✅ Accepter · 40 min"],
          ["s", "👍 Commande n° 57 acceptée, le client a reçu le lien Whish. Vous recevrez la commande ici dès qu'elle est payée. Ne la préparez pas avant."],
        ] },
        { label: "Paiement Whish", at: "18:13", messages: [
          ["c", "✅ Pharmacie Al Shifa a accepté votre commande n° 57 ! Payez 11,00 $ avec Whish ici :\nhttps://whish.money/pay/57xk\nNous la préparons dès que le paiement est confirmé (lien valable 30 minutes)."],
          ["c", "✅ Paiement reçu ! La commande n° 57 est confirmée, environ 40 min."],
          ["s", "🔔 Commande n° 57 payée ✅\n1× Panadol Extra\n1× Vitamine C 1000\nTotal : 11,00 $ (Whish)\n📍 Achrafieh, rue Sursock, immeuble 12", { buttons: ["🛵 En livraison", "✅ Livrée"] }],
          ["S", "🛵 En livraison"],
          ["c", "🛵 Votre commande n° 57 est en route !"],
        ] },
        { label: "Parler à quelqu'un", at: "18:31", messages: [
          ["C", "Vous avez aussi celui-ci ?", { photo: true }],
          ["c", "👋 Un membre de l'équipe Pharmacie Al Shifa va vous répondre ici très vite."],
          ["s", "📷 +961 3 456 789 a envoyé une photo. Le bot est en pause dans cette discussion, voyez-la et répondez depuis WhatsApp Business.\nTouchez le bouton quand vous avez fini.", { buttons: ["▶️ Rendre au bot"] }],
          ["c", "Oui, nous l'avons, 8,50 $. Le livreur vous l'apporte avec la commande 🙂", { human: "Rita, pharmacienne" }],
          ["C", "Parfait, merci !"],
          ["S", "▶️ Rendre au bot"],
          ["s", "🤖 Le bot reprend la discussion avec +961 3 456 789."],
        ] },
        { label: "Livrée", at: "18:50", messages: [
          ["S", "✅ Livrée"],
          ["s", "👍 Commande n° 57 mise à jour."],
          ["c", "✅ Votre commande n° 57 a été livrée. Merci d'avoir commandé chez Pharmacie Al Shifa !"],
        ] },
      ],
    },
    {
      id: "salon", icon: "💇", label: "Salon", shop: "Beauty Corner", chats: ["c", "s"],
      shows: "réservation d'un créneau libre, commerce prévenu, rappel automatique, Terminé / Absent, note",
      steps: [
        { label: "Réservation", at: "20:15", messages: [
          ["C", "Bonjour ! Je peux réserver une coupe et brushing demain après-midi ?"],
          ["c", "🗓 Créneaux libres pour Coupe & brushing le mer. 15 oct. :\n14:00 · 15:30 · 16:30 · 17:30\nQuel horaire vous convient ?"],
          ["C", "16:30"],
          ["c", "✅ Réservé : Coupe & brushing le mer. 15 oct. à 16:30 (25,00 $) chez Beauty Corner, Jounieh.\nNous vous enverrons un rappel. Répondez \"annuler le rendez-vous\" si vous ne pouvez pas venir."],
        ] },
        { label: "Commerce prévenu", at: "20:16", messages: [
          ["s", "🗓 Nouvelle réservation, Beauty Corner\nCoupe & brushing\nmer. 15 oct. à 16:30 · 25,00 $\n📞 +961 76 555 010", { buttons: ["✅ Terminé", "🚫 Absent"] }],
        ] },
        { label: "Rappel", at: "14:30", messages: [
          ["c", "⏰ Rappel : votre Coupe & brushing chez Beauty Corner est le mer. 15 oct. à 16:30. À bientôt !", { day: "mer. 15 oct." }],
          ["C", "À tout à l'heure 👍"],
        ] },
        { label: "Terminé", at: "17:20", messages: [
          ["S", "✅ Terminé", { day: "mer. 15 oct." }],
          ["s", "👍 Coupe & brushing mis à jour."],
        ] },
        { label: "Note", at: "18:20", messages: [
          ["c", "⭐ Qu'en avez-vous pensé ? Répondez avec un chiffre de 1 (mauvais) à 5 (excellent)."],
          ["C", "5"],
          ["c", "Merci pour votre note ! 🙏"],
        ] },
      ],
    },
    {
      id: "market", icon: "🛒", label: "Supérette", shop: "Mini Market Abou Elie", chats: ["c", "s"],
      shows: "ouvrir et fermer le commerce depuis WhatsApp, écrire dans n'importe quel mélange de langues, retrait, prête, récupérée",
      steps: [
        { label: "Ouverture", at: "07:58", messages: [
          ["S", "open"],
          ["s", "🟢 Mini Market Abou Elie est OUVERT, le bot prend les commandes."],
        ] },
        { label: "Commande", at: "09:12", messages: [
          ["C", "bonjour, 3andak labne w 2 khebz?"],
          ["c", "Oui ! 🙂\n1× Labné 500 g  3,50 $\n2× Pain libanais  1,60 $\nLivraison ou retrait ?"],
          ["C", "retrait, je passe dans 15 min"],
          ["c", "Votre commande\n1× Labné 500 g  3,50 $\n2× Pain libanais  1,60 $\nTotal : 5,10 $\nRetrait à Zalka, route principale · Espèces au magasin\nPrête dans 15 min environ", { buttons: ["✅ Confirmer", "✏️ Modifier", "❌ Annuler"] }],
          ["C", "✅ Confirmer"],
          ["c", "🧾 Commande n° 312 envoyée à Mini Market Abou Elie.\nTotal : 5,10 $\nVous recevrez un message dès que le commerce l'accepte."],
        ] },
        { label: "Acceptée", at: "09:13", messages: [
          ["s", "🔔 Nouvelle commande À RETIRER n° 312\n1× Labné 500 g\n2× Pain libanais\nTotal : 5,10 $ (espèces)\n📞 +961 71 909 090", { buttons: ["✅ Accepter · 15 min", "❌ Impossible"] }],
          ["S", "✅ Accepter · 15 min"],
          ["s", "👍 Commande n° 312 mise à jour.", { buttons: ["✅ Prête à retirer", "📦 Récupérée"] }],
          ["c", "✅ Mini Market Abou Elie a accepté votre commande n° 312, prête dans 15 min environ."],
        ] },
        { label: "Retrait", at: "09:24", messages: [
          ["S", "✅ Prête à retirer"],
          ["c", "📦 Votre commande n° 312 est prête ! Vous pouvez la retirer à Zalka, route principale."],
          ["S", "📦 Récupérée"],
          ["c", "✅ Merci d'avoir retiré la commande n° 312 chez Mini Market Abou Elie !"],
        ] },
        { label: "Fermeture", at: "21:00", messages: [
          ["S", "close"],
          ["s", "🔴 Mini Market Abou Elie est FERMÉ, le bot prévient les clients."],
          ["C", "3andak khebz?"],
          ["c", "Désolé, nous sommes fermés 🌙 Nous rouvrons demain à 8:00."],
        ] },
      ],
    },
  ],
};

// Arabic text runs right to left: keep "5.50$" written that way instead of "$5.50".
function isolateAmounts(text) {
  return text.replace(/(\d[\d.,]*\$)/g, "⁦$1⁩");
}

function flatten(scenario, rtl) {
  const messages = [];
  scenario.steps.forEach((step, stepIndex) => {
    const [h, m] = step.at.split(":").map(Number);
    step.messages.forEach(([who, text, extra = {}], k) => {
      const minutes = h * 60 + m + Math.floor(k / 3);
      messages.push({
        chat: who.toLowerCase(),
        out: who !== who.toLowerCase(),
        step: stepIndex,
        time: `${Math.floor(minutes / 60) % 24}:${String(minutes % 60).padStart(2, "0")}`,
        text: rtl ? isolateAmounts(text) : text,
        ...extra,
        buttons: extra.buttons?.map((b) => (rtl ? isolateAmounts(b) : b)),
      });
    });
  });
  return { ...scenario, steps: scenario.steps.map((s) => s.label), messages };
}

export function orderDemo(lang) {
  const key = UI[lang] ? lang : "en";
  return { ...UI[key], scenarios: SCENARIOS[key].map((s) => flatten(s, key === "ar")) };
}
