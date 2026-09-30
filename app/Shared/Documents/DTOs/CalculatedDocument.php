<?php

declare(strict_types=1);

namespace App\Shared\Documents\DTOs;

use App\Shared\Money\Money;

final class CalculatedDocument
{
    /**
     * @param  list<CalculatedLine>  $lines
     * @param  list<array{category:string,rate:string,taxable_amount:string,tax_amount:string,reason_code:?string}>  $taxTotals
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $taxTotals,
        public readonly Money $subtotal,
        public readonly Money $discountTotal,
        public readonly Money $netTotal,
        public readonly Money $taxTotal,
        public readonly Money $grandTotal,
    ) {}

    public function headerToDatabase(): array
    {
        return [
            'subtotal' => $this->subtotal->toDb(),
            'discount_total' => $this->discountTotal->toDb(),
            'net_total' => $this->netTotal->toDb(),
            'tax_total' => $this->taxTotal->toDb(),
            'grand_total' => $this->grandTotal->toDb(),
        ];
    }
}
