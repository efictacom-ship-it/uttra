<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Providers;

use App\Modules\EInvoicing\Contracts\EInvoicingProvider;
use App\Modules\EInvoicing\Contracts\EInvoiceSubmissionResult;
use App\Modules\EInvoicing\Models\EinvoiceDevice;
use App\Modules\EInvoicing\Models\EinvoiceDocument;
use Closure;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  محاكاة (MOCK) فقط — لا يوجد اتصال شبكي حقيقي بمنصة فاتورة (ZATCA) هنا.
 *  لا شهادات حقيقية، لا نقاط نهاية حقيقية، لا onboarding حقيقي مع الهيئة.
 *  الغرض: تجربة دورة حياة الفوترة الإلكترونية كاملة محليًا (توليد → إرسال →
 *  قبول/رفض/غير معروف → إعادة محاولة) قبل ربط مزود حقيقي actual لاحقًا خلف
 *  نفس EInvoicingProvider interface.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * السلوك الافتراضي: قبول كل محاولة فورًا (لتبسيط تطوير بقية النظام). يمكن حقن
 * سلوك مختلف عبر forceOutcome() في الاختبارات لمحاكاة الرفض أو الانقطاع أو
 * نتيجة غير معروفة، دون الحاجة لخادم وهمي حقيقي.
 */
final class MockEInvoicingProvider implements EInvoicingProvider
{
    /** @var Closure(EinvoiceDocument, EinvoiceDevice): EInvoiceSubmissionResult|null */
    private static ?Closure $forcedOutcome = null;

    public function submit(EinvoiceDocument $einvoiceDocument, EinvoiceDevice $device): EInvoiceSubmissionResult
    {
        if (self::$forcedOutcome !== null) {
            return (self::$forcedOutcome)($einvoiceDocument, $device);
        }

        return EInvoiceSubmissionResult::accepted(
            responsePayload: [
                'mock' => true,
                'clearanceStatus' => $einvoiceDocument->route === 'clearance' ? 'CLEARED' : 'REPORTED',
                'invoiceHash' => $einvoiceDocument->invoice_hash,
            ],
            clearedXmlStorageKey: $einvoiceDocument->route === 'clearance'
                ? $einvoiceDocument->xml_storage_key.'.cleared'
                : null,
            qrPayload: $einvoiceDocument->qr_payload,
        );
    }

    /** @param  Closure(EinvoiceDocument, EinvoiceDevice): EInvoiceSubmissionResult  $resolver */
    public static function forceOutcome(Closure $resolver): void
    {
        self::$forcedOutcome = $resolver;
    }

    public static function resetForcedOutcome(): void
    {
        self::$forcedOutcome = null;
    }
}
