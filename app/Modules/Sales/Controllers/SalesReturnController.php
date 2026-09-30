<?php

declare(strict_types=1);

namespace App\Modules\Sales\Controllers;

use App\Modules\Finance\Services\PaymentPostingService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Shared\Documents\Models\Document;
use App\Shared\Documents\Models\DocumentLine;
use App\Shared\Documents\Services\DocumentDraftService;
use App\Shared\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesReturnController
{
    public function __construct(
        private readonly DocumentDraftService $drafts,
        private readonly SalesReturnPostingService $posting,
        private readonly PaymentPostingService $payments,
    ) {}

    public function store(Request $request, Document $sale): RedirectResponse
    {
        if ($sale->kind !== Document::KIND_SALE) {
            throw ValidationException::withMessages(['sale' => ['المرتجع يُنشأ من فاتورة بيع فقط.']]);
        }

        if (! $sale->isPosted()) {
            throw ValidationException::withMessages(['sale' => ['لا يمكن إنشاء مرتجع من فاتورة بيع غير مرحّلة.']]);
        }

        $data = $request->validate([
            'business_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.original_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'refund' => ['nullable', 'array'],
            'refund.amount' => ['nullable', 'numeric', 'gt:0'],
            'refund.method' => ['nullable', 'in:cash,card,bank_transfer,other'],
            'refund.financial_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
        ]);

        $lines = $this->buildAndValidateLines($sale, $data['lines']);

        $document = $this->drafts->create(Document::KIND_SALE_RETURN, [
            'party_id' => $sale->party_id,
            'warehouse_id' => $sale->warehouse_id,
            'original_document_id' => $sale->id,
            'business_date' => $data['business_date'],
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'lines' => $lines,
        ], (int) $request->user()->id);

        $posted = $this->posting->post($document->id, (int) $request->user()->id);

        if (! empty($data['refund']['amount'])) {
            $this->payments->postRefund(
                $posted,
                $data['refund']['method'] ?? 'cash',
                (int) $data['refund']['financial_account_id'],
                Money::of((string) $data['refund']['amount']),
                (int) $request->user()->id,
                $posted->idempotency_key.'-customer-refund',
            );
        }

        return back()->with('success', 'تم ترحيل مرتجع البيع رقم '.$posted->document_number.'.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildAndValidateLines(Document $sale, array $rows): array
    {
        $originalLines = DocumentLine::query()->where('document_id', $sale->id)->get()->keyBy('id');

        $lines = [];

        foreach ($rows as $row) {
            $originalLineId = (int) $row['original_line_id'];
            $originalLine = $originalLines->get($originalLineId);

            if (! $originalLine) {
                throw ValidationException::withMessages([
                    'lines' => ['السطر المحدد لا ينتمي لفاتورة البيع رقم '.($sale->document_number ?? $sale->id).'.'],
                ]);
            }

            if ($originalLine->item_type_snapshot !== 'stock') {
                throw ValidationException::withMessages([
                    'lines' => ['لا يمكن إرجاع سطر خدمة؛ مرتجع البيع يقتصر على الأصناف المخزنية.'],
                ]);
            }

            $alreadyReturned = Money::of(DocumentLine::query()
                ->join('documents', 'documents.id', '=', 'document_lines.document_id')
                ->where('documents.kind', Document::KIND_SALE_RETURN)
                ->where('documents.status', 'posted')
                ->where('document_lines.original_line_id', $originalLineId)
                ->sum('document_lines.quantity') ?? '0');

            $requestedQuantity = Money::of((string) $row['quantity']);
            $remaining = Money::of($originalLine->quantity)->minus($alreadyReturned);

            if ($requestedQuantity->isGreaterThan($remaining)) {
                throw ValidationException::withMessages([
                    'lines' => [
                        'الكمية المرتجعة للسطر «'.$originalLine->description.'» أكبر من الكمية المتبقية القابلة للإرجاع ('.$remaining->toDecimalString(3).').',
                    ],
                ]);
            }

            $lines[] = [
                'item_id' => $originalLine->item_id,
                'item_unit_id' => $originalLine->item_unit_id,
                'original_line_id' => $originalLine->id,
                'description' => $row['description'] ?? $originalLine->description,
                'quantity' => (string) $row['quantity'],
                'unit_price' => (string) $row['unit_price'],
                'discount_amount' => (string) ($row['discount_amount'] ?? '0'),
                'price_includes_tax' => (bool) $originalLine->price_includes_tax_snapshot,
            ];
        }

        return $lines;
    }
}
