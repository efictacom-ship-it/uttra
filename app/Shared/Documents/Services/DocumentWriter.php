<?php

declare(strict_types=1);

namespace App\Shared\Documents\Services;

use App\Shared\Documents\DTOs\CalculatedDocument;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Models\DocumentLine;
use App\Shared\Documents\Models\DocumentTaxTotal;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** كتابة رأس المستند وسطوره وتجميع ضرائبه. لا يُعدَّل مستند مرحّل أبدًا. */
final class DocumentWriter
{
    public function createDraft(string $kind, array $attributes, ?CalculatedDocument $calculated = null): Document
    {
        $attributes['idempotency_key'] = $attributes['idempotency_key'] ?? (string) Str::uuid();

        $document = Document::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'kind' => $kind,
            'status' => 'draft',
            'currency' => 'SAR',
        ], $attributes));

        if ($calculated) {
            $this->replaceLines($document, $calculated);
        }

        return $document->refresh();
    }

    public function replaceLines(Document $document, CalculatedDocument $calculated): Document
    {
        $this->assertMutable($document);

        DocumentTaxTotal::query()->where('document_id', $document->id)->delete();
        DocumentLine::query()->where('document_id', $document->id)->delete();

        $rows = [];
        foreach ($calculated->lines as $line) {
            $rows[] = $line->toDatabase($document->id);
        }

        DB::table('document_lines')->insert($rows);

        foreach ($calculated->taxTotals as $total) {
            DocumentTaxTotal::query()->create($total + ['document_id' => $document->id]);
        }

        $document->update($calculated->headerToDatabase());

        return $document->refresh();
    }

    public function assertMutable(Document $document): void
    {
        if ($document->status === 'posted') {
            throw new DomainException('المستند مرحّل؛ لا يُعدَّل ولا يُحذف. استخدم مستند عكس أو إشعارًا مرتبطًا.');
        }

        if ($document->status === 'cancelled') {
            throw new DomainException('المستند ملغي.');
        }
    }

    public function markPosted(Document $document, string $documentNumber, int $periodId): Document
    {
        $document->update([
            'document_number' => $documentNumber,
            'status' => 'posted',
            'accounting_period_id' => $periodId,
            'issued_at' => $document->issued_at ?? now(),
            'posted_at' => now(),
        ]);

        return $document->refresh();
    }

    /** إلغاء مسودة فقط؛ الفاتورة الصادرة لا تُلغى بهذا الطريق. */
    public function cancelDraft(Document $document, string $reason): Document
    {
        if (! $document->isDraft()) {
            throw new DomainException('الإلغاء متاح للمسودات فقط.');
        }

        $document->update(['status' => 'cancelled', 'reason' => $reason]);

        return $document->refresh();
    }

    /** يمنع تكرار الترحيل بسبب ضغط مزدوج أو إعادة إرسال الطلب. */
    public function findByIdempotencyKey(string $key): ?Document
    {
        return Document::query()->where('idempotency_key', $key)->first();
    }

    public function lockForPosting(int $documentId): Document
    {
        $document = Document::query()->lockForUpdate()->find($documentId);

        if (! $document) {
            throw new DomainException('المستند غير موجود.');
        }

        if ($document->isPosted()) {
            throw new DomainException('المستند مرحّل بالفعل.');
        }

        if ($document->status === 'cancelled') {
            throw new DomainException('لا يمكن ترحيل مستند ملغي.');
        }

        return $document;
    }
}
