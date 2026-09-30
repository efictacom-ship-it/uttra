<?php

declare(strict_types=1);

namespace App\Shared\Documents\Services;

use App\Modules\Catalog\Models\ItemUnit;
use App\Modules\Settings\Models\BusinessSetting;
use App\Modules\Settings\Models\TaxCode;
use App\Shared\Documents\DTOs\CalculatedDocument;
use App\Shared\Documents\DTOs\CalculatedLine;
use App\Shared\Documents\DTOs\LineInput;
use App\Shared\Money\Money;
use App\Shared\Money\RoundingPolicy;
use DomainException;

/**
 * مصدر الحقيقة الوحيد للحساب المالي والضريبي. الواجهة تعرض تقديرًا فقط.
 *
 * سياسة التقريب الموحدة:
 * 1) السعر المُدخل شاملًا للضريبة يُعامل الإجمالي فيه كمرجع: gross = السعر × الكمية،
 *    ثم net = تقريب(gross ÷ (1+النسبة))، والضريبة = gross − net. بهذا يطابق المطبوع
 *    ما يدفعه العميل بالضبط ولا يظهر فرق هللة في الإيصال.
 * 2) السعر غير الشامل: net = تقريب(السعر × الكمية) − الخصومات، والضريبة = تقريب(net × النسبة).
 * 3) خصم الفاتورة يُوزَّع على السطور بنسبة إجماليها شاملًا الضريبة، وفرق التوزيع
 *    يُسنَد لأكبر سطر حتى يساوي المجموع الخصم المطلوب تمامًا.
 * 4) إجماليات الرأس = مجموع السطور المقرّبة، وتجميع الضريبة = مجموع ضرائب السطور.
 */
final class CalculationService
{
    /**
     * @param  list<LineInput>  $lines
     */
    public function calculate(
        array $lines,
        Money $documentDiscount,
        string $businessDate,
        bool $taxable = true,
        string $stockDirection = 'none',
    ): CalculatedDocument {
        if ($lines === []) {
            throw new DomainException('لا يمكن حساب مستند بلا سطور.');
        }

        $settings = BusinessSetting::current();
        $policy = $settings->roundingPolicy();
        $vatRegistered = (bool) $settings->vat_registered && $taxable;

        $prepared = [];
        $allocationWeights = [];

        foreach ($lines as $index => $input) {
            $row = $this->prepareLine($input, $businessDate, $vatRegistered, $policy, $stockDirection);
            $prepared[$index] = $row;
            $allocationWeights[$index] = $row['gross_after_line_discount'];
        }

        $documentDiscount = $policy->round($documentDiscount);
        $shares = $policy->allocate($documentDiscount, $allocationWeights);

        $calculated = [];
        $subtotal = Money::zero();
        $discountTotal = Money::zero();
        $netTotal = Money::zero();
        $taxTotal = Money::zero();
        $taxBuckets = [];

        foreach ($prepared as $index => $row) {
            $share = $shares[$index] ?? Money::zero();

            if ($share->isGreaterThan($row['gross_after_line_discount'])) {
                throw new DomainException("خصم الفاتورة أكبر من قيمة السطر «{$row['description']}».");
            }

            $rate = $row['tax_rate'];
            $onePlusRate = Money::of('1')->plus($rate->dividedBy('100'));

            if ($row['price_includes_tax'] && $rate->isPositive()) {
                $gross = $row['gross_after_line_discount']->minus($share);
                $net = $policy->round($gross->dividedBy($onePlusRate));
                $tax = $gross->minus($net);
            } else {
                $netShare = $rate->isPositive() ? $share->dividedBy($onePlusRate) : $share;
                $net = $policy->round($row['net_after_line_discount']->minus($netShare));
                $tax = $policy->round($net->multipliedBy($rate)->dividedBy('100'));
                $gross = $net->plus($tax);
            }

            if ($net->isNegative()) {
                throw new DomainException("الخصم على السطر «{$row['description']}» أكبر من قيمته.");
            }

            $lineDiscountInNet = $row['net_before_discount']->minus($net);

            $subtotal = $subtotal->plus($row['net_before_discount']);
            $discountTotal = $discountTotal->plus($lineDiscountInNet);
            $netTotal = $netTotal->plus($net);
            $taxTotal = $taxTotal->plus($tax);

            $bucketKey = $row['tax_category'].'|'.$rate->toDecimalString(4);
            $taxBuckets[$bucketKey] ??= [
                'category' => $row['tax_category'],
                'rate' => $rate,
                'taxable_amount' => Money::zero(),
                'tax_amount' => Money::zero(),
                'reason_code' => $row['tax_reason_code'],
            ];
            $taxBuckets[$bucketKey]['taxable_amount'] = $taxBuckets[$bucketKey]['taxable_amount']->plus($net);
            $taxBuckets[$bucketKey]['tax_amount'] = $taxBuckets[$bucketKey]['tax_amount']->plus($tax);

            $calculated[] = new CalculatedLine(
                lineNumber: $index + 1,
                itemId: $row['item_id'],
                itemUnitId: $row['item_unit_id'],
                accountId: $row['account_id'],
                taxCodeId: $row['tax_code_id'],
                originalLineId: $row['original_line_id'],
                description: $row['description'],
                skuSnapshot: $row['sku'],
                itemTypeSnapshot: $row['item_type'],
                unitNameSnapshot: $row['unit_name'],
                unitCodeSnapshot: $row['unit_code'],
                quantity: $row['quantity'],
                toBaseFactor: $row['to_base_factor'],
                baseQuantity: $row['base_quantity'],
                stockDirection: $row['stock_direction'],
                unitPrice: $row['unit_price_net'],
                enteredUnitPrice: $row['entered_unit_price'],
                priceIncludesTax: $row['price_includes_tax'],
                discountAmount: $lineDiscountInNet,
                taxCategory: $row['tax_category'],
                taxRate: $rate,
                taxReasonCode: $row['tax_reason_code'],
                taxReason: $row['tax_reason'],
                netAmount: $net,
                taxAmount: $tax,
                grossAmount: $gross,
                taxRecoverableAmount: Money::zero(),
            );
        }

        $taxTotals = array_values(array_map(fn (array $bucket) => [
            'category' => $bucket['category'],
            'rate' => $bucket['rate']->toDb(),
            'taxable_amount' => $bucket['taxable_amount']->toDb(),
            'tax_amount' => $bucket['tax_amount']->toDb(),
            'reason_code' => $bucket['reason_code'],
        ], $taxBuckets));

        return new CalculatedDocument(
            lines: $calculated,
            taxTotals: $taxTotals,
            subtotal: $subtotal,
            discountTotal: $discountTotal,
            netTotal: $netTotal,
            taxTotal: $taxTotal,
            grandTotal: $netTotal->plus($taxTotal),
        );
    }

    /** @return array<string, mixed> */
    private function prepareLine(LineInput $input, string $businessDate, bool $vatRegistered, RoundingPolicy $policy, string $stockDirection): array
    {
        if (! $input->quantity->isPositive()) {
            throw new DomainException('كمية السطر يجب أن تكون أكبر من صفر.');
        }

        $itemUnit = null;
        $item = null;

        if ($input->itemUnitId !== null) {
            $itemUnit = ItemUnit::query()->with(['item.taxCode', 'unit'])->find($input->itemUnitId);

            if (! $itemUnit) {
                throw new DomainException('وحدة الصنف غير موجودة.');
            }

            if ($input->itemId !== null && (int) $itemUnit->item_id !== $input->itemId) {
                throw new DomainException('وحدة القياس لا تخص الصنف المحدد.');
            }

            $item = $itemUnit->item;
        }

        if ($item === null && $input->accountId === null) {
            throw new DomainException('السطر يحتاج صنفًا أو حساب مصروف.');
        }

        $factor = $itemUnit ? Money::of($itemUnit->to_base_factor) : Money::of('1');
        $baseQuantity = $input->quantity->multipliedBy($factor);

        $taxCode = $this->resolveTaxCode($item?->taxCode, $businessDate, $vatRegistered);
        $rate = $vatRegistered && $taxCode ? Money::of($taxCode->rate) : Money::zero();
        $onePlusRate = Money::of('1')->plus($rate->dividedBy('100'));

        $priceIncludesTax = ($input->priceIncludesTaxOverride
            ?? ($itemUnit ? (bool) $itemUnit->price_includes_tax : false)) && $rate->isPositive();

        $entered = $input->enteredUnitPrice;
        $lineAmount = $policy->round($entered->multipliedBy($input->quantity));
        $lineDiscount = $policy->round($input->lineDiscount);

        if ($lineDiscount->isGreaterThan($lineAmount)) {
            throw new DomainException('خصم السطر أكبر من قيمته.');
        }

        if ($priceIncludesTax) {
            $unitNet = $entered->dividedBy($onePlusRate);
            $netBeforeDiscount = $policy->round($lineAmount->dividedBy($onePlusRate));
            $grossAfterLineDiscount = $lineAmount->minus($lineDiscount);
            $netAfterLineDiscount = $policy->round($grossAfterLineDiscount->dividedBy($onePlusRate));
        } else {
            $unitNet = $entered;
            $netBeforeDiscount = $lineAmount;
            $netAfterLineDiscount = $lineAmount->minus($lineDiscount);
            $grossAfterLineDiscount = $netAfterLineDiscount->multipliedBy($onePlusRate);
        }

        $itemType = $item ? $item->item_type : 'expense';
        $direction = 'none';

        if ($item && $item->isStock() && $stockDirection !== 'none') {
            $direction = $stockDirection;
        }

        return [
            'item_id' => $item?->id,
            'item_unit_id' => $itemUnit?->id,
            'account_id' => $input->accountId,
            'tax_code_id' => $taxCode?->id,
            'original_line_id' => $input->originalLineId,
            'description' => $input->description !== '' ? $input->description : (string) ($item?->name_ar ?? 'سطر'),
            'sku' => $item?->sku,
            'item_type' => $itemType,
            'unit_name' => $itemUnit?->unit?->name ?? 'خدمة/مصروف',
            'unit_code' => $itemUnit?->unit?->code,
            'quantity' => $input->quantity,
            'to_base_factor' => $factor,
            'base_quantity' => $baseQuantity,
            'stock_direction' => $direction,
            'unit_price_net' => $unitNet,
            'entered_unit_price' => $entered,
            'price_includes_tax' => $priceIncludesTax,
            'net_before_discount' => $netBeforeDiscount,
            'net_after_line_discount' => $netAfterLineDiscount,
            'gross_after_line_discount' => $grossAfterLineDiscount,
            'tax_category' => $taxCode?->category ?? 'O',
            'tax_rate' => $rate,
            'tax_reason_code' => $taxCode?->exemption_reason_code,
            'tax_reason' => $taxCode?->exemption_reason,
        ];
    }

    private function resolveTaxCode(?TaxCode $taxCode, string $businessDate, bool $vatRegistered): ?TaxCode
    {
        if (! $vatRegistered || ! $taxCode) {
            return $taxCode;
        }

        if (! $taxCode->isValidOn($businessDate) || ! $taxCode->is_active) {
            throw new DomainException("المعالجة الضريبية «{$taxCode->name}» غير سارية بتاريخ {$businessDate}.");
        }

        return $taxCode;
    }
}
