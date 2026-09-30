<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Services;

use App\Modules\EInvoicing\Models\EinvoiceDevice;
use App\Modules\EInvoicing\Models\EinvoiceDocument;
use App\Shared\Audit\AuditService;
use App\Shared\Documents\Models\Document;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * توليد سجل فوترة إلكترونية لمستند مرحّل بالفعل (بيع أو مرتجع بيع) يحمل einvoice_type
 * غير "none". لا يُولَّد سجل لمستند غير مرحّل، ولا لمستند einvoice_type = none.
 * التسلسل والتجزئة (previous_invoice_hash → invoice_hash) على مستوى الوحدة (device)
 * الواحدة، بقفل صف الوحدة أثناء الحجز حتى لا يتكرر رقم العداد بين طلبين متزامنين.
 *
 * ملاحظة: xml_storage_key و invoice_hash هنا تمثيل محلي بسيط (mock) وليس XML/تجزئة
 * ZATCA الحقيقية — التوقيع الرقمي والشهادات الحقيقية خارج نطاق هذه النسخة.
 */
final class EInvoiceGenerationService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function generate(int $documentId, int $deviceId): EinvoiceDocument
    {
        return DB::transaction(function () use ($documentId, $deviceId): EinvoiceDocument {
            $document = Document::query()->findOrFail($documentId);

            if (! $document->isPosted()) {
                throw new DomainException('لا يمكن توليد فاتورة إلكترونية لمستند غير مرحّل.');
            }

            if (! in_array($document->kind, [Document::KIND_SALE, Document::KIND_SALE_RETURN], true)) {
                throw new DomainException('الفوترة الإلكترونية تُولَّد لفواتير البيع ومرتجعاتها فقط.');
            }

            if ($document->einvoice_type === 'none') {
                throw new DomainException('هذا المستند غير مصنَّف للفوترة الإلكترونية (einvoice_type = none).');
            }

            $existing = EinvoiceDocument::query()->where('document_id', $document->id)->first();

            if ($existing) {
                return $existing;
            }

            $device = EinvoiceDevice::query()->lockForUpdate()->findOrFail($deviceId);

            if (! $device->is_active) {
                throw new DomainException('وحدة الإصدار غير نشطة.');
            }

            $counter = (int) $device->next_invoice_counter;
            $previousHash = (string) ($device->previous_invoice_hash ?? str_repeat('0', 44));
            $uuid = (string) Str::uuid();

            // تمثيل محلي بسيط للتجزئة المتسلسلة؛ ليس خوارزمية ZATCA الحقيقية.
            $invoiceHash = hash('sha256', $previousHash.'|'.$document->id.'|'.$document->grand_total.'|'.$counter);
            $xmlStorageKey = "einvoice-mock/{$device->id}/{$uuid}.xml";
            $seller = $document->seller_snapshot ?? [];

            $qrPayload = base64_encode(json_encode([
                'seller' => $seller['legal_name'] ?? null,
                'vat_number' => $seller['vat_number'] ?? null,
                'timestamp' => $document->issued_at?->toIso8601String(),
                'total' => $document->grand_total,
                'tax' => $document->tax_total,
                'hash' => $invoiceHash,
            ], JSON_UNESCAPED_UNICODE));

            $route = $document->einvoice_type === 'standard' ? 'clearance' : 'reporting';

            $einvoiceDocument = EinvoiceDocument::query()->create([
                'document_id' => $document->id,
                'device_id' => $device->id,
                'uuid' => $uuid,
                'invoice_counter' => $counter,
                'previous_invoice_hash' => $previousHash,
                'invoice_hash' => $invoiceHash,
                'route' => $route,
                'xml_storage_key' => $xmlStorageKey,
                'xml_checksum' => hash('sha256', $xmlStorageKey),
                'qr_payload' => $qrPayload,
                'status' => 'ready',
                'schema_version' => 'mock-1.0',
                'generated_at' => now(),
                // التقرير المبسّط: خلال 24 ساعة من الإصدار حسب متطلبات المرحلة الثانية.
                'reporting_due_at' => $route === 'reporting' ? now()->addHours(24) : null,
            ]);

            $device->update([
                'next_invoice_counter' => $counter + 1,
                'previous_invoice_hash' => $invoiceHash,
            ]);

            DB::table('outbox_jobs')->insert([
                'job_type' => 'einvoice_submit',
                'dedupe_key' => 'einvoice_submit:'.$einvoiceDocument->id,
                'document_id' => $document->id,
                'einvoice_document_id' => $einvoiceDocument->id,
                'payload_json' => json_encode(['einvoice_document_id' => $einvoiceDocument->id]),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
            ]);

            $this->audit->log('einvoice_document', $einvoiceDocument->id, 'generated', null, [
                'document_id' => $document->id,
                'route' => $route,
            ]);

            return $einvoiceDocument;
        });
    }
}
