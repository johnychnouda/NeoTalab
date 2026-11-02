<?php

namespace App\Services\WhatsApp;

/**
 * The staff and shop-owner templates in Arabic and French. Each one has the same variables, in the
 * same order, as its English original in ShopTemplateService (which also gives the examples), and the
 * quick-reply buttons mean the same: their payloads come from the code, only the titles are translated
 * (25 letters at most). A template in another language is a template of its own: neotalab_new_order_ar…
 */
class StaffTemplateTexts
{
    /**
     * @return array<string, array<string, array{body: string, buttons?: list<string>}>>
     */
    public static function all(): array
    {
        return [
            ShopTemplateService::BILLING_REMINDER => [
                'ar' => ['body' => 'مرحباً {{1}}، ينتهي اشتراكك في NeoTalab بتاريخ {{2}}. يرجى التجديد ليبقى بوت الطلبات على واتساب يعمل. ردّ هنا إذا احتجت إلى مساعدة.'],
                'fr' => ['body' => "Bonjour {{1}}, votre abonnement NeoTalab se termine le {{2}}. Merci de le renouveler pour que votre bot de commandes WhatsApp continue de fonctionner. Répondez ici si vous avez besoin d'aide."],
            ],
            ShopTemplateService::NEW_ORDER => [
                'ar' => ['body' => 'طلب جديد رقم {{1}} لمتجر {{2}}: {{3}}. المدة المعتادة: {{4}}. اضغط قبول لتأكيده للزبون، أو لا أستطيع لرفضه.', 'buttons' => ['قبول', 'لا أستطيع']],
                'fr' => ['body' => 'Nouvelle commande n° {{1}} pour {{2}} : {{3}}. Délai habituel : {{4}}. Appuyez sur Accepter pour la confirmer au client, ou sur Impossible pour la refuser.', 'buttons' => ['Accepter', 'Impossible']],
            ],
            ShopTemplateService::STAFF_ALERT => [
                'ar' => ['body' => 'زبون يحتاج إلى شخص في {{1}}: {{2}}. تم إيقاف المساعد في هذه المحادثة، لذا يرجى الرد عليه من تطبيق واتساب للأعمال. اضغط العودة إلى البوت عند الانتهاء.', 'buttons' => ['العودة إلى البوت']],
                'fr' => ['body' => "Un client a besoin d'une personne chez {{1}} : {{2}}. L'assistant est en pause dans cette conversation, merci de lui répondre depuis l'application WhatsApp Business. Appuyez sur Retour au bot quand vous avez terminé.", 'buttons' => ['Retour au bot']],
            ],
            ShopTemplateService::NEW_BOOKING => [
                'ar' => ['body' => 'حجز جديد في {{1}}: {{2}} يوم {{3}} الساعة {{4}}، السعر {{5}}. الزبون: {{6}}. بعد الزيارة، اضغط تم أو لم يحضر. لإلغاء الحجز أو نقله، اضغط تعديل.', 'buttons' => ['تم', 'لم يحضر', 'تعديل']],
                'fr' => ['body' => 'Nouvelle réservation chez {{1}} : {{2}} le {{3}} à {{4}}, prix {{5}}. Client : {{6}}. Après la visite, appuyez sur Terminé ou Absent. Pour annuler ou déplacer la réservation, appuyez sur Modifier.', 'buttons' => ['Terminé', 'Absent', 'Modifier']],
            ],
            ShopTemplateService::ORDER_DUE => [
                'ar' => ['body' => 'الطلب رقم {{1}} في {{2}} موعده اليوم: {{3}}. اضغط جاهز / في الطريق عند خروجه، أو تأجيل يوم لنقله إلى يوم العمل التالي.', 'buttons' => ['جاهز / في الطريق', 'تأجيل يوم']],
                'fr' => ['body' => "La commande n° {{1}} chez {{2}} est prévue aujourd'hui : {{3}}. Appuyez sur Prête / en route quand elle part, ou sur Reporter d'1 jour pour la décaler au prochain jour ouvré.", 'buttons' => ['Prête / en route', "Reporter d'1 jour"]],
            ],
            ShopTemplateService::PAYMENT_CHECK => [
                'ar' => ['body' => 'دفعة للتحقق منها للطلب رقم {{1}}: {{2}} من {{3}}. ابحث عن الملاحظة {{4}} في حساب Whish الخاص بك. هل وصل المبلغ؟ اضغط وصل أو لم يصل.', 'buttons' => ['وصل', 'لم يصل']],
                'fr' => ['body' => "Paiement à vérifier pour la commande n° {{1}} : {{2}} de {{3}}. Cherchez la note {{4}} dans votre compte Whish. L'argent est-il arrivé ? Appuyez sur Reçu ou Non reçu.", 'buttons' => ['Reçu', 'Non reçu']],
            ],
            ShopTemplateService::REFUND_NEEDED => [
                'ar' => ['body' => 'مطلوب استرداد في {{1}}: الطلب رقم {{2}} دُفع عبر Whish ({{3}}) لكنه أُلغي. الزبون: {{4}}. يرجى إعادة المبلغ من حساب Whish الخاص بك.'],
                'fr' => ['body' => 'Remboursement nécessaire chez {{1}} : la commande n° {{2}} a été payée par Whish ({{3}}) mais elle a été annulée. Client : {{4}}. Merci de rembourser depuis votre compte Whish.'],
            ],
            ShopTemplateService::CUSTOMER_CANCELLED => [
                'ar' => ['body' => 'ألغى الزبون الطلب رقم {{1}} في {{2}} ({{3}}). يرجى عدم تحضيره.'],
                'fr' => ['body' => 'La commande n° {{1}} chez {{2}} a été annulée par le client ({{3}}). Merci de ne pas la préparer.'],
            ],
            ShopTemplateService::SCHEDULED_DUE => [
                'ar' => ['body' => 'الطلب رقم {{1}} في {{2}} موعده {{3}}. يرجى البدء بتحضيره الآن.'],
                'fr' => ['body' => 'La commande n° {{1}} chez {{2}} est prévue {{3}}. Merci de commencer à la préparer maintenant.'],
            ],
            ShopTemplateService::BOOKING_CANCELLED => [
                'ar' => ['body' => 'ألغى الزبون حجزاً في {{1}}: {{2}} يوم {{3}} الساعة {{4}}. الزبون: {{5}}. أصبح هذا الوقت متاحاً من جديد.'],
                'fr' => ['body' => 'Le client a annulé une réservation chez {{1}} : {{2}} le {{3}} à {{4}}. Client : {{5}}. Ce créneau est de nouveau libre.'],
            ],
            ShopTemplateService::NOT_DELIVERED => [
                'ar' => ['body' => 'تعذّر تسليم الطلب رقم {{1}} في {{2}}. السبب: {{3}}. تم إبلاغ الزبون.'],
                'fr' => ['body' => "La commande n° {{1}} chez {{2}} n'a pas pu être livrée. Motif : {{3}}. Le client a été prévenu."],
            ],
            ShopTemplateService::WHISH_UNPAID => [
                'ar' => ['body' => 'أُلغي الطلب رقم {{1}} في {{2}} لأن الزبون لم يدفع عبر Whish. يرجى عدم تحضيره.'],
                'fr' => ['body' => "La commande n° {{1}} chez {{2}} a été annulée car le client n'a pas payé par Whish. Merci de ne pas la préparer."],
            ],
            ShopTemplateService::QUOTE_REQUEST => [
                'ar' => ['body' => 'طلب سعر جديد رقم {{1}} في {{2}} من {{3}}: {{4}}. للرد، أرسل: quote {{5}} ثم السعر ووصف العمل.'],
                'fr' => ['body' => 'Nouvelle demande de prix n° {{1}} chez {{2}} de la part de {{3}} : {{4}}. Pour répondre, envoyez : quote {{5}}, puis le prix et la description du travail.'],
            ],
            ShopTemplateService::QUOTE_DECLINED => [
                'ar' => ['body' => 'رفض الزبون عرض السعر رقم {{1}} في {{2}} ({{3}}). لا يلزم أي إجراء.'],
                'fr' => ['body' => "Le client a refusé le devis n° {{1}} chez {{2}} ({{3}}). Aucune action n'est nécessaire."],
            ],
            ShopTemplateService::LOW_RATING => [
                'ar' => ['body' => 'حصل الطلب رقم {{1}} في {{2}} على تقييم {{3}} من 5 من {{4}}. يرجى الاتصال بالزبون لمعرفة ما حدث.'],
                'fr' => ['body' => "La commande n° {{1}} chez {{2}} a reçu la note de {{3}} sur 5 de la part de {{4}}. Merci d'appeler le client pour comprendre ce qui s'est passé."],
            ],
        ];
    }
}
