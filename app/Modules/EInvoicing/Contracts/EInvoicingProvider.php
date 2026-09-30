<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Contracts;

use App\Modules\EInvoicing\Models\EinvoiceDevice;
use App\Modules\EInvoicing\Models\EinvoiceDocument;

/**
 * العقد الذي يُبنى عليه أي مزود فوترة إلكترونية فعلي مستقبلًا (ZATCA أو غيرها).
 * النسخة الوحيدة المطبَّقة اليوم في هذا المشروع هي MockEInvoicingProvider — لا يوجد
 * اتصال شبكي حقيقي ولا شهادات حقيقية. استبدال هذا التطبيق بمزود حقيقي يجب أن
 * يحترم نفس التوقيع: نتيجة "غير معروفة" (timeout/connection_error) مختلفة تمامًا
 * عن "مرفوضة"، ولا يجوز لأي مستدعٍ أن يعامل الاثنتين بنفس الطريقة.
 */
interface EInvoicingProvider
{
    /**
     * يرسل مستند فوترة واحدًا (تقرير أو تخليص) ويعيد نتيجة محاولة الإرسال.
     * لا يعدّل حالة $einvoiceDocument بنفسه؛ العنصر المستدعي (EInvoiceSubmissionService)
     * هو من يفسّر EInvoiceSubmissionResult ويحدّث الحالة.
     */
    public function submit(EinvoiceDocument $einvoiceDocument, EinvoiceDevice $device): EInvoiceSubmissionResult;
}
