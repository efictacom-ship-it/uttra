<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Services;

use App\Modules\EInvoicing\Contracts\EInvoicingProvider;
use App\Modules\EInvoicing\Models\EinvoiceDevice;
use App\Modules\EInvoicing\Models\EinvoiceDocument;
use App\Modules\EInvoicing\Models\EinvoiceSubmission;
use App\Shared\Audit\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * محاولة إرسال واحدة لمستند فوترة إلكترونية، عبر EInvoicingProvider (اليوم: محاكاة
 * فقط). القاعدة الأهم هنا: نتيجة "غير معروفة" (unknown/connection_error) لا تُعامَل
 * أبدًا كرفض — تبقى الحالة كما هي (pending/connection_error) لإعادة المحاولة لاحقًا،
 * ولا يُبنى عليها أي قرار تلقائي (لا إبطال مستند، لا تحرير حجز مخزون). الرفض الصريح
 * فقط هو ما يُسجَّل status = rejected، والمعالجة منه تكون يدوية (retry يعيد المحاولة
 * على نفس سجل einvoice_document وnفس document_id — لا يُنشئ مستندًا جديدًا).
 */
final class EInvoiceSubmissionService
{
    public function __construct(
        private readonly EInvoicingProvider $provider,
        private readonly AuditService $audit,
    ) {}

    public function submit(int $einvoiceDocumentId): EinvoiceDocument
    {
        return DB::transaction(function () use ($einvoiceDocumentId): EinvoiceDocument {
            $einvoiceDocument = EinvoiceDocument::query()->lockForUpdate()->findOrFail($einvoiceDocumentId);

            if (in_array($einvoiceDocument->status, ['reported', 'cleared'], true)) {
                return $einvoiceDocument;
            }

            $device = EinvoiceDevice::query()->findOrFail($einvoiceDocument->device_id);

            $attemptNumber = (int) EinvoiceSubmission::query()
                ->where('einvoice_document_id', $einvoiceDocument->id)
                ->max('attempt_number') + 1;

            $startedAt = now();

            $submission = EinvoiceSubmission::query()->create([
                'einvoice_document_id' => $einvoiceDocument->id,
                'attempt_number' => $attemptNumber,
                'request_correlation_id' => (string) Str::uuid(),
                'started_at' => $startedAt,
                'result' => 'in_progress',
            ]);

            $einvoiceDocument->update(['status' => 'pending', 'last_submitted_at' => $startedAt]);

            $result = $this->provider->submit($einvoiceDocument, $device);

            $submission->update([
                'finished_at' => now(),
                'http_status' => $result->httpStatus,
                'result' => $result->result,
                'response_json' => $result->responsePayload,
                'error_message' => $result->errorMessage,
            ]);

            $newStatus = match ($result->result) {
                'accepted' => $einvoiceDocument->route === 'clearance' ? 'cleared' : 'reported',
                'rejected' => 'rejected',
                // غير معروف/انقطاع: لا نستقر على حالة نهائية، فقط نعلّم الانقطاع للمتابعة
                'connection_error' => 'connection_error',
                default => $einvoiceDocument->status === 'ready' ? 'pending' : $einvoiceDocument->status,
            };

            $updates = ['status' => $newStatus];

            if ($result->clearedXmlStorageKey !== null) {
                $updates['cleared_xml_storage_key'] = $result->clearedXmlStorageKey;
            }

            if ($result->qrPayload !== null) {
                $updates['qr_payload'] = $result->qrPayload;
            }

            $einvoiceDocument->update($updates);

            $this->audit->log('einvoice_document', $einvoiceDocument->id, 'submission_'.$result->result, null, [
                'attempt' => $attemptNumber,
                'status' => $newStatus,
            ]);

            return $einvoiceDocument->refresh();
        });
    }

    /** إعادة محاولة صريحة على نفس السجل بعد رفض أو انقطاع — لا يُنشئ مستندًا جديدًا. */
    public function retry(int $einvoiceDocumentId): EinvoiceDocument
    {
        $einvoiceDocument = EinvoiceDocument::query()->findOrFail($einvoiceDocumentId);

        if (in_array($einvoiceDocument->status, ['reported', 'cleared'], true)) {
            throw new DomainException('المستند مقبول بالفعل؛ لا داعي لإعادة الإرسال.');
        }

        return $this->submit($einvoiceDocumentId);
    }
}
