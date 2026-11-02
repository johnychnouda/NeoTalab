<?php

namespace App\Services\WhatsApp;

/**
 * The wording of the platform number's templates in Arabic and French (English lives in
 * PlatformTemplateService for the ones that existed first, and here for the newer ones). Each body
 * ends on fixed words and has enough text around its variables, as Meta requires. A template in
 * another language is a template of its own: neotalab_paid_ar, neotalab_paid_fr…
 */
class PlatformTemplateTexts
{
    /**
     * @return array<string, array<string, array{text: string, examples: list<string>, button_text?: string}>>
     */
    public static function all(): array
    {
        return [
            // Alerts to the NeoTalab owner (the wrapper only: the alert text itself is generated in English).
            config('alerts.whatsapp_template') => [
                'ar' => [
                    'text' => 'إشعار حول منصة NeoTalab الخاصة بك: {{1}}. يرجى مراجعته في لوحة المالك ضمن البريد الوارد ← النظام.',
                    'examples' => ['فشل النسخ الاحتياطي الليلي لقاعدة البيانات'],
                ],
                'fr' => [
                    'text' => 'Avis concernant votre plateforme NeoTalab : {{1}}. Merci de le consulter dans votre portail propriétaire, sous Boîte de réception → Système.',
                    'examples' => ['la sauvegarde nocturne de la base de données a échoué'],
                ],
            ],
            config('alerts.notice_template') => [
                'ar' => [
                    'text' => '🎉 أخبار سارة من NeoTalab: {{1}}. اضغط على الرابط لفتحها في لوحة المالك.',
                    'examples' => ['طلب متجر جديد: سناك أبو علي (مطعم، بيروت). التواصل: علي +96170000000. للمراجعة: https://neotalab.com/owner?req=1'],
                ],
                'fr' => [
                    'text' => "🎉 Bonne nouvelle de NeoTalab : {{1}}. Appuyez sur le lien pour l'ouvrir dans votre portail propriétaire.",
                    'examples' => ['Nouvelle demande de boutique : Abou Ali Snack (Restaurant, Beyrouth). Contact : Ali +96170000000. À examiner : https://neotalab.com/owner?req=1'],
                ],
            ],
            PlatformTemplateService::WELCOME => [
                'ar' => [
                    'text' => "أهلاً بك في NeoTalab! 🎉 حساب {{1}} جاهز.\n\nعنوان بريدك الإلكتروني: {{2}}\n\nاضغط الزر أدناه لفتح لوحة التحكم. وستصلك تفاصيل الوصول في الرسالة التالية.",
                    'examples' => ['بيروت بايتس', 'abouali@merchant.neotalab'],
                    'button_text' => 'افتح لوحة التحكم',
                ],
                'fr' => [
                    'text' => "Bienvenue sur NeoTalab ! 🎉 Le compte de {{1}} est prêt.\n\nVotre adresse e-mail : {{2}}\n\nAppuyez sur le bouton ci-dessous pour ouvrir votre tableau de bord. Vos informations d'accès suivent dans le message suivant.",
                    'examples' => ['Beirut Bites', 'abouali@merchant.neotalab'],
                    'button_text' => 'Ouvrir mon tableau de bord',
                ],
            ],
            PlatformTemplateService::PAID => [
                'ar' => [
                    'text' => 'شكراً لك! اشتراكك في NeoTalab لمتجر {{1}} مدفوع حتى {{2}}. شكراً لثقتك بـ NeoTalab.',
                    'examples' => ['بيروت بايتس', '21 تشرين الثاني 2026'],
                ],
                'fr' => [
                    'text' => "Merci ! Votre abonnement NeoTalab pour {{1}} est payé jusqu'au {{2}}. Merci de votre confiance envers NeoTalab.",
                    'examples' => ['Beirut Bites', '21 novembre 2026'],
                ],
            ],
            PlatformTemplateService::DAILY_REPORT => [
                'ar' => [
                    'text' => "📊 هذا هو التقرير اليومي لمتجر {{1}} بتاريخ {{2}}، أرسلته NeoTalab.\n\nالطلبات المسلّمة اليوم: {{3}}\nإجمالي المبيعات اليوم: {{4}}\nمتوسط تقييم الزبائن: {{5}}\nالطلبات المتأخرة: {{6}}\nالأكثر مبيعاً اليوم: {{7}}\n\nافتح لوحة التحكم في أي وقت لرؤية المزيد من التفاصيل عن متجرك وزبائنك.",
                    'examples' => ['بيروت بايتس', '2026-10-06', '42', '512.00 USD', '4.6 / 5', '2', 'شاورما ×30، بطاطا ×18'],
                ],
                'fr' => [
                    'text' => "📊 Voici le rapport quotidien de {{1}} pour le {{2}}, envoyé par NeoTalab.\n\nCommandes livrées aujourd'hui : {{3}}\nTotal des ventes aujourd'hui : {{4}}\nNote moyenne des clients : {{5}}\nCommandes en retard : {{6}}\nMeilleures ventes du jour : {{7}}\n\nOuvrez votre tableau de bord à tout moment pour voir plus de détails sur votre boutique et vos clients.",
                    'examples' => ['Beirut Bites', '2026-10-06', '42', '512.00 USD', '4.6 / 5', '2', 'Shawarma ×30, Frites ×18'],
                ],
            ],
            // The shop stopped (trial over, or payment overdue). {{2}} is the reason, in the shop's language.
            PlatformTemplateService::SHOP_PAUSED => [
                'en' => [
                    'text' => 'Hello! Your NeoTalab shop {{1}} is paused because {{2}}. Your customers cannot order until it is renewed. Please send your payment by Whish and reply to this chat with the transfer reference. Your shop restarts as soon as the payment is recorded.',
                    'examples' => ['Beirut Bites', 'your free trial has ended'],
                ],
                'ar' => [
                    'text' => 'مرحباً! تم إيقاف متجرك {{1}} على NeoTalab مؤقتاً لأن {{2}}. لا يستطيع زبائنك الطلب حتى يتم التجديد. يرجى إرسال الدفعة عبر Whish والرد على هذه المحادثة برقم التحويل. يعود متجرك للعمل فور تسجيل الدفعة.',
                    'examples' => ['بيروت بايتس', 'انتهت تجربتك المجانية'],
                ],
                'fr' => [
                    'text' => "Bonjour ! Votre boutique NeoTalab {{1}} est suspendue car {{2}}. Vos clients ne peuvent plus commander tant qu'elle n'est pas renouvelée. Merci d'envoyer votre paiement par Whish et de répondre à cette conversation avec la référence du transfert. Votre boutique redémarre dès que le paiement est enregistré.",
                    'examples' => ['Beirut Bites', 'votre essai gratuit est terminé'],
                ],
            ],
            PlatformTemplateService::SHOP_ACTIVE => [
                'en' => [
                    'text' => 'Good news! Your NeoTalab shop {{1}} is active again. Your customers can order on WhatsApp now.',
                    'examples' => ['Beirut Bites'],
                ],
                'ar' => [
                    'text' => 'أخبار سارة! متجرك {{1}} على NeoTalab يعمل من جديد. يستطيع زبائنك الطلب عبر واتساب الآن.',
                    'examples' => ['بيروت بايتس'],
                ],
                'fr' => [
                    'text' => 'Bonne nouvelle ! Votre boutique NeoTalab {{1}} est de nouveau active. Vos clients peuvent commander sur WhatsApp dès maintenant.',
                    'examples' => ['Beirut Bites'],
                ],
            ],
            // The shop's own WhatsApp connection stopped working or is about to expire. {{2}} says which.
            PlatformTemplateService::WHATSAPP_DOWN => [
                'en' => [
                    'text' => 'Hello! The WhatsApp connection for your NeoTalab shop {{1}} needs your attention: {{2}}. Please open your dashboard and reconnect WhatsApp so your customers keep getting answers. If you need help, reply to this chat.',
                    'examples' => ['Beirut Bites', 'it has stopped working'],
                ],
                'ar' => [
                    'text' => 'مرحباً! اتصال واتساب لمتجرك {{1}} على NeoTalab يحتاج إلى اهتمامك: {{2}}. يرجى فتح لوحة التحكم وإعادة ربط واتساب لكي يستمر زبائنك في تلقي الردود. إذا احتجت إلى مساعدة، ردّ على هذه المحادثة.',
                    'examples' => ['بيروت بايتس', 'توقف عن العمل'],
                ],
                'fr' => [
                    'text' => "Bonjour ! La connexion WhatsApp de votre boutique NeoTalab {{1}} demande votre attention : {{2}}. Ouvrez votre tableau de bord et reconnectez WhatsApp pour que vos clients continuent d'être servis. Si vous avez besoin d'aide, répondez à cette conversation.",
                    'examples' => ['Beirut Bites', 'elle a cessé de fonctionner'],
                ],
            ],
            // Someone applied to join NeoTalab; the join page sends the language it was read in.
            PlatformTemplateService::REQUEST_RECEIVED => [
                'en' => [
                    'text' => 'Hello {{1}}! We received your request to join NeoTalab with {{2}}. We will review it and reply in this chat. Thank you for your interest!',
                    'examples' => ['Ali', 'Abou Ali Snack'],
                ],
                'ar' => [
                    'text' => 'مرحباً {{1}}! استلمنا طلبك للانضمام إلى NeoTalab بمتجر {{2}}. سنراجعه ونرد عليك في هذه المحادثة. شكراً لاهتمامك!',
                    'examples' => ['علي', 'سناك أبو علي'],
                ],
                'fr' => [
                    'text' => 'Bonjour {{1}} ! Nous avons bien reçu votre demande pour rejoindre NeoTalab avec {{2}}. Nous allons l\'examiner et vous répondre dans cette conversation. Merci de votre intérêt !',
                    'examples' => ['Ali', 'Abou Ali Snack'],
                ],
            ],
            PlatformTemplateService::REQUEST_DECLINED => [
                'en' => [
                    'text' => 'Hello {{1}}, thank you for your interest in NeoTalab. We could not approve your request for {{2}} at this time. You are welcome to apply again later, or reply to this chat if you have any question.',
                    'examples' => ['Ali', 'Abou Ali Snack'],
                ],
                'ar' => [
                    'text' => 'مرحباً {{1}}، شكراً لاهتمامك بـ NeoTalab. لم نتمكن من الموافقة على طلبك لمتجر {{2}} في الوقت الحالي. يمكنك التقديم مجدداً لاحقاً، أو الرد على هذه المحادثة إذا كان لديك أي سؤال.',
                    'examples' => ['علي', 'سناك أبو علي'],
                ],
                'fr' => [
                    'text' => 'Bonjour {{1}}, merci de votre intérêt pour NeoTalab. Nous n\'avons pas pu approuver votre demande pour {{2}} pour le moment. Vous pouvez soumettre une nouvelle demande plus tard, ou répondre à cette conversation pour toute question.',
                    'examples' => ['Ali', 'Abou Ali Snack'],
                ],
            ],
        ];
    }

    /** The reason a shop was paused, as it reads inside the shop_paused message. */
    public static function pausedReason(string $why, string $locale): string
    {
        return [
            'trial' => ['en' => 'your free trial has ended', 'ar' => 'انتهت تجربتك المجانية', 'fr' => 'votre essai gratuit est terminé'],
            'overdue' => ['en' => 'your payment is overdue', 'ar' => 'تأخر دفع اشتراكك', 'fr' => 'votre paiement est en retard'],
            'manual' => ['en' => 'your account was paused by NeoTalab', 'ar' => 'تم إيقاف حسابك من قبل NeoTalab', 'fr' => 'votre compte a été suspendu par NeoTalab'],
        ][$why][$locale] ?? 'your payment is due';
    }

    /** What is wrong with a shop's WhatsApp connection, as it reads inside the whatsapp_down message. */
    public static function downReason(string $why, string $locale, string $date = ''): string
    {
        return match ($why) {
            'expiring' => ['en' => "it expires on {$date}", 'ar' => "تنتهي صلاحيته في {$date}", 'fr' => "elle expire le {$date}"][$locale] ?? "it expires on {$date}",
            default => ['en' => 'it has stopped working', 'ar' => 'توقف عن العمل', 'fr' => 'elle a cessé de fonctionner'][$locale] ?? 'it has stopped working',
        };
    }
}
