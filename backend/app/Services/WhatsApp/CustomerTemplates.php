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

    public const LANGUAGES = ['en', 'ar', 'fr'];

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
     * @return array<string, array{body: string, example: list<string>, language: string}>
     */
    public static function definitions(): array
    {
        $all = [];
        foreach (self::texts() as $event => $languages) {
            foreach ($languages as $language => [$body, $example]) {
                $all[self::name($event, $language)] = ['body' => $body, 'example' => $example, 'language' => $language];
            }
        }

        return $all;
    }

    /**
     * @return array<string, array<string, array{0: string, 1: list<string>}>>
     */
    protected static function texts(): array
    {
        return [
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
            self::BOOKING => [
                'en' => ['Reminder: your {{1}} at {{2}} is on {{3}} at {{4}}. If you need to change it, please write to us in this chat. See you soon!', ['Haircut', 'Glow Salon', 'Wednesday 14 October', '15:30']],
                'ar' => ['تذكير: موعد {{1}} في {{2}} يوم {{3}} الساعة {{4}}. إذا أردت تغييره، يرجى مراسلتنا في هذه المحادثة. نراك قريباً!', ['قص شعر', 'صالون جلو', 'الأربعاء 14 تشرين الأول', '15:30']],
                'fr' => ['Rappel : votre {{1}} chez {{2}} est le {{3}} à {{4}}. Pour le modifier, écrivez-nous dans cette conversation. À bientôt !', ['Coupe', 'Glow Salon', 'mercredi 14 octobre', '15h30']],
            ],
        ];
    }
}
