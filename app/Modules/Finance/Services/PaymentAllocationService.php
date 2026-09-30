<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Shared\Documents\Models\Document;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * تخصيص الدفعات على المستندات:
 * - التخصيص لا يتجاوز المتبقي على المستند.
 * - اتجاه الدفعة يجب أن يوافق نوع المستند (قبض لفاتورة بيع، صرف لفاتورة شراء…).
 * - الطرف في الدفعة يطابق طرف المستند.
 * - ما لا يُخصَّص يبقى دفعة مقدمة، ولا يُحسب إقفالًا لفاتورة.
 */
final class PaymentAllocationService
{
    private const RECEIPT_KINDS = [Document::KIND_SALE, Document::KIND_PURCHASE_RETURN];

    private const DISBURSEMENT_KINDS = [Document::KIND_PURCHASE, Document::KIND_EXPENSE, Document::KIND_SALE_RETURN];

    /**
     * @param  list<array{document_id:int, amount:string|Money}>  $allocations
     */
    public function allocate(Payment $payment, array $allocations): void
    {
        $assigned = Money::zero();

        foreach ($allocations as $row) {
            $amount = $row['amount'] instanceof Money ? $row['amount'] : Money::of($row['amount']);

            if (! $amount->isPositive()) {
                throw new DomainException('مبلغ التخصيص يجب أن يكون أكبر من صفر.');
            }

            $document = Document::query()->lockForUpdate()->find($row['document_id']);

            if (! $document || ! $document->isPosted()) {
                throw new DomainException('لا يمكن تخصيص دفعة على مستند غير مرحّل.');
            }

            $this->assertDirectionMatches($payment, $document);

            if ($document->party_id && $payment->party_id && (int) $document->party_id !== (int) $payment->party_id) {
                throw new DomainException('الطرف في الدفعة لا يطابق طرف المستند.');
            }

            $outstanding = $this->outstanding($document);

            if ($amount->isGreaterThan($outstanding)) {
                throw new DomainException(
                    'التخصيص ('.$amount->toDecimalString(2).') أكبر من المتبقي على المستند ('.$outstanding->toDecimalString(2).').'
                );
            }

            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'document_id' => $document->id,
                'amount' => $amount->toDb(),
            ]);

            $assigned = $assigned->plus($amount);
        }

        if ($assigned->isGreaterThan(Money::of($payment->amount))) {
            throw new DomainException('مجموع التخصيصات أكبر من قيمة الدفعة.');
        }
    }

    public function outstanding(Document $document): Money
    {
        $paid = Money::of(DB::table('payment_allocations')->where('document_id', $document->id)->sum('amount') ?? '0');

        return Money::of($document->grand_total)->minus($paid);
    }

    /** المبلغ غير المخصص من الدفعة (دفعة مقدمة). */
    public function unallocated(Payment $payment): Money
    {
        $allocated = Money::of(DB::table('payment_allocations')->where('payment_id', $payment->id)->sum('amount') ?? '0');

        return Money::of($payment->amount)->minus($allocated);
    }

    private function assertDirectionMatches(Payment $payment, Document $document): void
    {
        $valid = match ($payment->direction) {
            'receipt' => in_array($document->kind, self::RECEIPT_KINDS, true),
            'disbursement' => in_array($document->kind, self::DISBURSEMENT_KINDS, true),
            default => false,
        };

        if (! $valid) {
            throw new DomainException('اتجاه الدفعة لا يناسب نوع المستند المخصَّص عليه.');
        }
    }
}
