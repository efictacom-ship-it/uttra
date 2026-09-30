<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * معالج صندوق الصادر (outbox pattern): الوظيفة كُتبت داخل معاملة التوليد نفسها،
 * وتُنفَّذ هنا خارجها — بلا طابور حقيقي (Redis/SQS) في هذه النسخة، استدعاء يدوي
 * أو مجدول (Uttra Scheduler) عبر artisan command بسيط. فشل مهمة لا يُسقطها فورًا:
 * تُعاد جدولتها بتأخير متصاعد حتى حد محاولات، وبعده تُترك "failed" دون أي أثر
 * تلقائي على المستند التجاري نفسه.
 */
final class EInvoiceOutboxProcessor
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EInvoiceSubmissionService $submissions,
    ) {}

    /** @return array{processed:int, failed:int} */
    public function processPending(int $limit = 20): array
    {
        $processed = 0;
        $failed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $job = DB::transaction(function () {
                $job = DB::table('outbox_jobs')
                    ->where('status', 'pending')
                    ->where('available_at', '<=', now())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($job) {
                    DB::table('outbox_jobs')->where('id', $job->id)->update([
                        'status' => 'processing',
                        'locked_at' => now(),
                    ]);
                }

                return $job;
            });

            if (! $job) {
                break;
            }

            try {
                $payload = json_decode((string) $job->payload_json, true) ?: [];
                $this->submissions->submit((int) ($payload['einvoice_document_id'] ?? $job->einvoice_document_id));

                DB::table('outbox_jobs')->where('id', $job->id)->update(['status' => 'done']);
                $processed++;
            } catch (Throwable $e) {
                $attempts = (int) $job->attempts + 1;
                $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';

                DB::table('outbox_jobs')->where('id', $job->id)->update([
                    'status' => $status,
                    'attempts' => $attempts,
                    // تأخير متصاعد بسيط: دقيقتان × عدد المحاولات
                    'available_at' => now()->addMinutes(2 * $attempts),
                    'last_error' => substr($e->getMessage(), 0, 1000),
                ]);

                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
