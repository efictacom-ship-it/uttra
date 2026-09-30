<?php

declare(strict_types=1);

namespace App\Shared\Documents\DTOs;

use App\Shared\Money\Money;

/** سطر بعد الحساب على السيرفر: هذه هي القيم التي تُكتب في document_lines. */
final class CalculatedLine
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly ?int $itemId,
        public readonly ?int $itemUnitId,
        public readonly ?int $accountId,
        public readonly ?int $taxCodeId,
        public readonly ?int $originalLineId,
        public readonly string $description,
        public readonly ?string $skuSnapshot,
        public readonly string $itemTypeSnapshot,
        public readonly string $unitNameSnapshot,
        public readonly ?string $unitCodeSnapshot,
        public readonly Money $quantity,
        public readonly Money $toBaseFactor,
        public readonly Money $baseQuantity,
        public readonly string $stockDirection,
        public readonly Money $unitPrice,
        public readonly Money $enteredUnitPrice,
        public readonly bool $priceIncludesTax,
        public readonly Money $discountAmount,
        public readonly ?string $taxCategory,
        public readonly Money $taxRate,
        public readonly ?string $taxReasonCode,
        public readonly ?string $taxReason,
        public readonly Money $netAmount,
        public readonly Money $taxAmount,
        public readonly Money $grossAmount,
        public readonly Money $taxRecoverableAmount,
    ) {}

    public function toDatabase(int $documentId): array
    {
        return [
            'document_id' => $documentId,
            'line_number' => $this->lineNumber,
            'item_id' => $this->itemId,
            'item_unit_id' => $this->itemUnitId,
            'account_id' => $this->accountId,
            'tax_code_id' => $this->taxCodeId,
            'original_line_id' => $this->originalLineId,
            'description' => $this->description,
            'sku_snapshot' => $this->skuSnapshot,
            'item_type_snapshot' => $this->itemTypeSnapshot,
            'unit_name_snapshot' => $this->unitNameSnapshot,
            'unit_code_snapshot' => $this->unitCodeSnapshot,
            'quantity' => $this->quantity->toDb(),
            'to_base_factor' => $this->toBaseFactor->toDb(),
            'base_quantity' => $this->baseQuantity->toDb(),
            'stock_direction' => $this->stockDirection,
            'unit_price' => $this->unitPrice->toDb(),
            'entered_unit_price' => $this->enteredUnitPrice->toDb(),
            'price_includes_tax_snapshot' => $this->priceIncludesTax,
            'discount_amount' => $this->discountAmount->toDb(),
            'tax_category_snapshot' => $this->taxCategory,
            'tax_rate_snapshot' => $this->taxRate->toDb(),
            'tax_reason_code_snapshot' => $this->taxReasonCode,
            'tax_reason_snapshot' => $this->taxReason,
            'net_amount' => $this->netAmount->toDb(),
            'tax_amount' => $this->taxAmount->toDb(),
            'gross_amount' => $this->grossAmount->toDb(),
            'tax_recoverable_amount' => $this->taxRecoverableAmount->toDb(),
        ];
    }
}
