<?php

declare(strict_types=1);

namespace App\Shared\Documents\DTOs;

use App\Shared\Money\Money;

/** سطر كما يصل من الطلب، قبل أي حساب على السيرفر. */
final class LineInput
{
    public function __construct(
        public readonly ?int $itemId,
        public readonly ?int $itemUnitId,
        public readonly ?int $accountId,
        public readonly string $description,
        public readonly Money $quantity,
        public readonly Money $enteredUnitPrice,
        public readonly Money $lineDiscount,
        public readonly ?bool $priceIncludesTaxOverride = null,
        public readonly ?int $originalLineId = null,
        public readonly ?string $taxCodeIdOverride = null,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            itemId: isset($row['item_id']) ? (int) $row['item_id'] : null,
            itemUnitId: isset($row['item_unit_id']) ? (int) $row['item_unit_id'] : null,
            accountId: isset($row['account_id']) ? (int) $row['account_id'] : null,
            description: (string) ($row['description'] ?? ''),
            quantity: Money::of($row['quantity'] ?? '0'),
            enteredUnitPrice: Money::of($row['unit_price'] ?? '0'),
            lineDiscount: Money::of($row['discount_amount'] ?? '0'),
            priceIncludesTaxOverride: array_key_exists('price_includes_tax', $row) ? (bool) $row['price_includes_tax'] : null,
            originalLineId: isset($row['original_line_id']) ? (int) $row['original_line_id'] : null,
            taxCodeIdOverride: isset($row['tax_code_id']) ? (string) $row['tax_code_id'] : null,
        );
    }
}
