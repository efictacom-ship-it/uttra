<?php

declare(strict_types=1);

namespace App\Shared\Documents\Services;

use App\Shared\Documents\DTOs\LineInput;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Models\DocumentLine;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء وتعديل المسودات لكل أنواع المستندات، بحساب كامل على السيرفر.
 * السلة المعلقة في الكاشير هي ببساطة مستند بيع بحالة draft.
 */
final class DocumentDraftService
{
    private const STOCK_DIRECTION = [
        Document::KIND_SALE => 'out',
        Document::KIND_SALE_RETURN => 'in',
        Document::KIND_PURCHASE => 'in',
        Document::KIND_PURCHASE_RETURN => 'out',
        Document::KIND_EXPENSE => 'none',
        Document::KIND_STOCK_OPENING => 'in',
        Document::KIND_STOCK_ADJUSTMENT => 'none',
        Document::KIND_MATERIAL_USAGE => 'out',
    ];

    public function __construct(
        private readonly CalculationService $calculator,
        private readonly DocumentWriter $writer,
    ) {}

    public function create(string $kind, array $payload, int $userId): Document
    {
        return DB::transaction(function () use ($kind, $payload, $userId): Document {
            if (! empty($payload['idempotency_key'])) {
                $existing = $this->writer->findByIdempotencyKey($payload['idempotency_key']);

                if ($existing) {
                    return $existing;
                }
            }

            $document = $this->writer->createDraft($kind, [
                'party_id' => $payload['party_id'] ?? null,
                'warehouse_id' => $payload['warehouse_id'] ?? null,
                'original_document_id' => $payload['original_document_id'] ?? null,
                'business_date' => $payload['business_date'],
                'due_date' => $payload['due_date'] ?? null,
                'supplier_reference' => $payload['supplier_reference'] ?? null,
                'einvoice_type' => $payload['einvoice_type'] ?? 'none',
                'reason' => $payload['reason'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'created_by' => $userId,
            ]);

            return $this->applyLines($document, $payload);
        });
    }

    public function update(Document $document, array $payload): Document
    {
        return DB::transaction(function () use ($document, $payload): Document {
            $this->writer->assertMutable($document);

            $document->update(array_filter([
                'party_id' => $payload['party_id'] ?? null,
                'warehouse_id' => $payload['warehouse_id'] ?? null,
                'business_date' => $payload['business_date'] ?? null,
                'due_date' => $payload['due_date'] ?? null,
                'supplier_reference' => $payload['supplier_reference'] ?? null,
                'einvoice_type' => $payload['einvoice_type'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ], fn ($value) => $value !== null));

            return $this->applyLines($document->refresh(), $payload);
        });
    }

    private function applyLines(Document $document, array $payload): Document
    {
        $rows = $payload['lines'] ?? [];

        if ($rows === []) {
            throw new DomainException('المستند يحتاج سطرًا واحدًا على الأقل.');
        }

        $inputs = array_map(fn (array $row) => LineInput::fromArray($row), $rows);

        $calculated = $this->calculator->calculate(
            lines: $inputs,
            documentDiscount: Money::of($payload['document_discount'] ?? '0'),
            businessDate: (string) ($payload['business_date'] ?? $document->business_date->toDateString()),
            taxable: $document->isCommercial(),
            stockDirection: self::STOCK_DIRECTION[$document->kind] ?? 'none',
        );

        $document = $this->writer->replaceLines($document, $calculated);

        // مستند التسوية يحدد اتجاه كل سطر بنفسه (تالف/نقص مقابل زيادة)
        if ($document->kind === Document::KIND_STOCK_ADJUSTMENT || $document->kind === Document::KIND_STOCK_OPENING) {
            $lines = $document->lines()->get();

            foreach ($lines as $index => $line) {
                $row = $rows[$index] ?? [];

                $line->update([
                    'stock_direction' => $row['stock_direction'] ?? ($document->kind === Document::KIND_STOCK_OPENING ? 'in' : 'out'),
                    'unit_cost_snapshot' => isset($row['unit_cost']) ? Money::of($row['unit_cost'])->toDb() : $line->unit_cost_snapshot,
                ]);
            }
        }

        return $document->refresh();
    }

    /** نسخة الطباعة/العرض لا تُعاد حسابها من بطاقة الصنف أبدًا. */
    public function lineSnapshots(Document $document): array
    {
        return DocumentLine::query()->where('document_id', $document->id)->orderBy('line_number')->get()->toArray();
    }
}
