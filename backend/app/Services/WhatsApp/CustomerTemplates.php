<?php

namespace App\Services\WhatsApp;

/**
 * The approved wording a shop's number uses to reach a customer who has not written in the last
 * 24 hours (Meta drops plain text after that, without telling anyone). One template per event and
 * language, named neotalab_c_{event}_{language}; they are part of every shop's template set.
 * The text is factual only, so Meta files it under Utility; each body ends on fixed words, as
 * Meta refuses a variable at the start or the end.
 */
class CustomerTemplates
{
    public const ACCEPTED = 'order_accepted';

    public const READY = 'order_ready';

    public const ON_THE_WAY = 'order_on_the_way';

    public const DELAYED = 'order_delayed';

    public const CANCELLED = 'order_cancelled';

    public const BOOKING = 'booking_reminder';

    public const REFUND = 'order_refund';

    public const OUTSIDE = 'order_outside';

    public const DELIVERED = 'order_delivered';

    /** The thank-you that also asks how it was (the shop asks for a rating). */
    public const DELIVERED_RATE = 'order_delivered_rate';

    /** The shop's price for a request, with Accept / No thanks buttons. */
    public const QUOTE = 'quote_ready';

    public const SCHEDULED = 'order_scheduled';

    /** The shop accepted: how to pay by Whish, with an "I paid" button. */
    public const PAY_INSTRUCTIONS = 'pay_instructions';

    public const PAY_CONFIRMED = 'pay_confirmed';

    public const PAY_NOT_RECEIVED = 'pay_not_received';

    /** The payment window closed: the order is cancelled, with an "I paid" button for a late payer. */
    public const PAY_EXPIRED = 'pay_expired';

    public const LANGUAGES = ['en', 'ar', 'fr'];

    /** Whether failing to reach the customer is worth an owner alert (a late thank-you is not). */
    public static function critical(string $event): bool
    {
        return ! in_array($event, [self::DELIVERED, self::DELIVERED_RATE, self::SCHEDULED], true);
    }

    /** The template for this event in the customer's language (English when we have none for it). */
    public static function name(string $event, ?string $locale): string
    {
        $language = in_array($locale, self::LANGUAGES, true) ? $locale : 'en';

        return "neotalab_c_{$event}_{$language}";
    }

    /** @return list<string> names to try, the customer's language first, then English */
    public static function names(string $event, ?string $locale): array
    {
        return array_values(array_unique([self::name($event, $locale), self::name($event, 'en')]));
    }

    /**
     * @return array<string, array{body: string, example: list<string>, language: string, buttons?: list<string>}>
     */
    public static function definitions(): array
    {
        $all = [];
        foreach (self::texts() as $event => $languages) {
            foreach ($languages as $language => [$body, $example, $buttons]) {
                $all[self::name($event, $language)] = ['body' => $body, 'example' => $example, 'language' => $language]
                    + ($buttons ? ['buttons' => $buttons] : []);
            }
        }

        return $all;
    }

    /**
     * @return array<string, array<string, array{0: string, 1: list<string>, 2: list<string>}>>
     */
    protected static function texts(): array
    {
        return self::withButtons([
            self::ACCEPTED => [
                'en' => ['Good news! {{1}} accepted your order #{{2}}. Expected: {{3}}. We will keep you updated in this chat. Thank you for your order!', ['Beirut Bites', '128', 'around 11:25 AM']],
                'ar' => ['أخبار سارة! قَبِل {{1}} طلبك رقم {{2}}. الموعد المتوقع: {{3}}. سنُبقيك على اطلاع في هذه المحادثة. شكراً لطلبك!', ['بيروت بايتس', '128', 'حوالي الساعة 11:25 صباحاً']],
                'fr' => ['Bonne nouvelle ! {{1}} a accepté votre commande n° {{2}}. Délai prévu : {{3}}. Nous vous tiendrons informé dans cette conversation. Merci pour votre commande !', ['Beirut Bites', '128', 'vers 11h25']],
            ],
            self::READY => [
                'en' => ['Hello! Your order #{{1}} from {{2}} is ready for pickup at {{3}}. See you soon!', ['128', 'Beirut Bites', 'Hamra, Bliss Street']],
                'ar' => ['مرحباً! طلبك رقم {{1}} من {{2}} جاهز للاستلام من {{3}}. نراك قريباً!', ['128', 'بيروت بايتس', 'الحمرا، شارع بليس']],
                'fr' => ['Bonjour ! Votre commande n° {{1}} chez {{2}} est prête à être récupérée à {{3}}. À bientôt !', ['128', 'Beirut Bites', 'Hamra, rue Bliss']],
            ],
            self::ON_THE_WAY => [
                'en' => ['Good news! Your order #{{1}} from {{2}} is on its way to you. Thank you for your patience!', ['128', 'Beirut Bites']],
                'ar' => ['أخبار سارة! طلبك رقم {{1}} من {{2}} في الطريق إليك. شكراً لصبرك!', ['128', 'بيروت بايتس']],
                'fr' => ['Bonne nouvelle ! Votre commande n° {{1}} chez {{2}} est en route. Merci de votre patience !', ['128', 'Beirut Bites']],
            ],
            self::DELAYED => [
                'en' => ['Sorry, your order #{{1}} from {{2}} is taking longer than planned. The new expected time is {{3}}. Thank you for your patience.', ['128', 'Beirut Bites', 'Saturday 17 October']],
                'ar' => ['نعتذر، طلبك رقم {{1}} من {{2}} يتأخر عن الموعد المحدد. الموعد الجديد المتوقع: {{3}}. شكراً لصبرك.', ['128', 'بيروت بايتس', 'السبت 17 تشرين الأول']],
                'fr' => ['Désolé, votre commande n° {{1}} chez {{2}} prend plus de temps que prévu. La nouvelle heure prévue est {{3}}. Merci de votre patience.', ['128', 'Beirut Bites', 'samedi 17 octobre']],
            ],
            self::CANCELLED => [
                'en' => ['Sorry, your order #{{1}} from {{2}} could not be completed and was cancelled. If you have any question, please write to us in this chat.', ['128', 'Beirut Bites']],
                'ar' => ['نعتذر، تعذّر إتمام طلبك رقم {{1}} من {{2}} وتم إلغاؤه. إذا كان لديك أي سؤال، يرجى مراسلتنا في هذه المحادثة.', ['128', 'بيروت بايتس']],
                'fr' => ["Désolé, votre commande n° {{1}} chez {{2}} n'a pas pu être réalisée et a été annulée. Pour toute question, écrivez-nous dans cette conversation.", ['128', 'Beirut Bites']],
            ],
            self::REFUND => [
                'en' => ['Your payment for order #{{1}} from {{2}} will be refunded by the shop. If you have any question, please write to us in this chat.', ['128', 'Beirut Bites']],
                'ar' => ['سيتم ردّ المبلغ الذي دفعته للطلب رقم {{1}} من {{2}} من قِبل المتجر. إذا كان لديك أي سؤال، يرجى مراسلتنا في هذه المحادثة.', ['128', 'بيروت بايتس']],
                'fr' => ['Votre paiement pour la commande n° {{1}} chez {{2}} sera remboursé par la boutique. Pour toute question, écrivez-nous dans cette conversation.', ['128', 'Beirut Bites']],
            ],
            self::OUTSIDE => [
                'en' => ['Your driver {{1}} is outside with your order #{{2}}. You can reach the driver on {{3}} if needed.', ['Rami', '128', '+96170123456']],
                'ar' => ['السائق {{1}} أمام الباب مع طلبك رقم {{2}}. يمكنك الاتصال بالسائق على {{3}} عند الحاجة.', ['رامي', '128', '+96170123456']],
                'fr' => ['Votre livreur {{1}} est devant la porte avec votre commande n° {{2}}. Vous pouvez le joindre au {{3}} si besoin.', ['Rami', '128', '+96170123456']],
            ],
            self::DELIVERED => [
                'en' => ['Your order #{{1}} from {{2}} was delivered. Thank you for ordering with us!', ['128', 'Beirut Bites']],
                'ar' => ['تم تسليم طلبك رقم {{1}} من {{2}}. شكراً لطلبك منا!', ['128', 'بيروت بايتس']],
                'fr' => ['Votre commande n° {{1}} chez {{2}} a été livrée. Merci de votre commande !', ['128', 'Beirut Bites']],
            ],
            self::DELIVERED_RATE => [
                'en' => ['Your order #{{1}} from {{2}} was delivered. Thank you! How was it? Reply with a number from 1 (bad) to 5 (excellent).', ['128', 'Beirut Bites']],
                'ar' => ['تم تسليم طلبك رقم {{1}} من {{2}}. شكراً لك! كيف كانت التجربة؟ ردّ برقم من 1 (سيئ) إلى 5 (ممتاز).', ['128', 'بيروت بايتس']],
                'fr' => ['Votre commande n° {{1}} chez {{2}} a été livrée. Merci ! Comment était-elle ? Répondez par un chiffre de 1 (mauvais) à 5 (excellent).', ['128', 'Beirut Bites']],
            ],
            self::QUOTE => [
                'en' => ['Good news! {{1}} sent you a price for your request #{{2}}: {{3}} for {{4}}. The offer is valid for {{5}} days. Please choose below.', ['Sofa Repair', '128', 'Sofa rebuild', '150.00$', '3'], ['Accept', 'No thanks']],
                'ar' => ['أخبار سارة! أرسل {{1}} سعراً لطلبك رقم {{2}}: {{3}} بسعر {{4}}. العرض صالح لمدة {{5}} أيام. يرجى الاختيار أدناه.', ['ورشة الأريكة', '128', 'تجديد الأريكة', '150.00$', '3'], ['قبول', 'لا، شكراً']],
                'fr' => ['Bonne nouvelle ! {{1}} vous a envoyé un prix pour votre demande n° {{2}} : {{3}} pour {{4}}. L\'offre est valable {{5}} jours. Veuillez choisir ci-dessous.', ['Atelier Sofa', '128', 'Réfection du canapé', '150.00$', '3'], ['Accepter', 'Non merci']],
            ],
            self::SCHEDULED => [
                'en' => ['Reminder: your order #{{1}} from {{2}} is scheduled for {{3}}. We are getting it ready now.', ['128', 'Flower Shop', 'Friday 10:00–12:00']],
                'ar' => ['تذكير: طلبك رقم {{1}} من {{2}} مجدول {{3}}. نقوم بتجهيزه الآن.', ['128', 'محل الزهور', 'الجمعة 10:00–12:00']],
                'fr' => ['Rappel : votre commande n° {{1}} chez {{2}} est prévue {{3}}. Nous la préparons maintenant.', ['128', 'Fleuriste', 'vendredi 10h00–12h00']],
            ],
            self::PAY_INSTRUCTIONS => [
                'en' => ['Good news! {{1}} accepted your order #{{2}}. Please pay {{3}} to the Whish number {{4}} and write {{5}} in the Whish note. When you have paid, tap the button below. You have {{6}} minutes.', ['Beirut Bites', '128', '12.00$', '70123456', '128', '15'], ['I paid']],
                'ar' => ['أخبار سارة! قَبِل {{1}} طلبك رقم {{2}}. يرجى دفع {{3}} إلى رقم Whish {{4}} وكتابة {{5}} في ملاحظة Whish. بعد الدفع، اضغط الزر أدناه. لديك {{6}} دقيقة.', ['بيروت بايتس', '128', '12.00$', '70123456', '128', '15'], ['دفعت']],
                'fr' => ['Bonne nouvelle ! {{1}} a accepté votre commande n° {{2}}. Merci de payer {{3}} au numéro Whish {{4}} en écrivant {{5}} dans la note Whish. Une fois le paiement fait, appuyez sur le bouton ci-dessous. Vous avez {{6}} minutes.', ['Beirut Bites', '128', '12.00$', '70123456', '128', '15'], ["J'ai payé"]],
            ],
            self::PAY_CONFIRMED => [
                'en' => ['Payment received! Your order #{{1}} from {{2}} is confirmed: {{3}}. Thank you!', ['128', 'Beirut Bites', 'arriving around 11:25 AM']],
                'ar' => ['تم استلام الدفعة! تم تأكيد طلبك رقم {{1}} من {{2}}: {{3}}. شكراً لك!', ['128', 'بيروت بايتس', 'حوالي الساعة 11:25 صباحاً']],
                'fr' => ['Paiement reçu ! Votre commande n° {{1}} chez {{2}} est confirmée : {{3}}. Merci !', ['128', 'Beirut Bites', 'vers 11h25']],
            ],
            self::PAY_NOT_RECEIVED => [
                'en' => ['The shop could not find your Whish payment for order #{{1}} from {{2}}, so the order was cancelled. You can order again anytime.', ['128', 'Beirut Bites']],
                'ar' => ['لم يجد المتجر دفعتك عبر Whish للطلب رقم {{1}} من {{2}}، لذلك تم إلغاء الطلب. يمكنك الطلب من جديد في أي وقت.', ['128', 'بيروت بايتس']],
                'fr' => ["La boutique n'a pas trouvé votre paiement Whish pour la commande n° {{1}} chez {{2}}, la commande a donc été annulée. Vous pouvez commander à nouveau à tout moment.", ['128', 'Beirut Bites']],
            ],
            self::PAY_EXPIRED => [
                'en' => ['We did not receive the Whish payment for order #{{1}} from {{2}} in time, so the order was cancelled. If you already paid, tap the button below and the shop will check.', ['128', 'Beirut Bites'], ['I paid']],
                'ar' => ['لم نستلم دفعة Whish للطلب رقم {{1}} من {{2}} في الوقت المحدد، لذلك تم إلغاء الطلب. إذا كنت قد دفعت بالفعل، اضغط الزر أدناه وسيتحقق المتجر.', ['128', 'بيروت بايتس'], ['دفعت']],
                'fr' => ["Nous n'avons pas reçu le paiement Whish pour la commande n° {{1}} chez {{2}} à temps, la commande a donc été annulée. Si vous avez déjà payé, appuyez sur le bouton ci-dessous et la boutique vérifiera.", ['128', 'Beirut Bites'], ["J'ai payé"]],
            ],
            self::BOOKING => [
                'en' => ['Reminder: your {{1}} at {{2}} is on {{3}} at {{4}}. If you need to change it, please write to us in this chat. See you soon!', ['Haircut', 'Glow Salon', 'Wednesday 14 October', '15:30']],
                'ar' => ['تذكير: موعد {{1}} في {{2}} يوم {{3}} الساعة {{4}}. إذا أردت تغييره، يرجى مراسلتنا في هذه المحادثة. نراك قريباً!', ['قص شعر', 'صالون جلو', 'الأربعاء 14 تشرين الأول', '15:30']],
                'fr' => ['Rappel : votre {{1}} chez {{2}} est le {{3}} à {{4}}. Pour le modifier, écrivez-nous dans cette conversation. À bientôt !', ['Coupe', 'Glow Salon', 'mercredi 14 octobre', '15h30']],
            ],
        ]);
    }

    /**
     * Gives every text a button list (empty for most), so definitions() reads them the same way.
     *
     * @param  array<string, array<string, array<int, mixed>>>  $texts
     * @return array<string, array<string, array{0: string, 1: list<string>, 2: list<string>}>>
     */
    protected static function withButtons(array $texts): array
    {
        foreach ($texts as $event => $languages) {
            foreach ($languages as $language => $text) {
                $texts[$event][$language] = [$text[0], $text[1], $text[2] ?? []];
            }
        }

        return $texts;
    }
}
