<?php

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * سياسة تقريب واحدة موثقة لكل النظام:
 * 1) تُحسب قيم السطر (صافي/ضريبة/إجمالي) وتُقرَّب إلى منازل العرض المعتمدة على مستوى السطر.
 * 2) تجميع الضريبة يتم بجمع ضرائب السطور المقرّبة، لا بإعادة حسابها من إجمالي الرأس.
 * 3) إجماليات الرأس = مجموع السطور المقرّبة، فلا يظهر فرق بين الرأس والسطور.
 * 4) فرق التقريب النهائي (إن وُجد) يُرحّل لحساب فرق التقريب المعرّف في ربط الحسابات.
 */
final class RoundingPolicy
{
    public function __construct(
        private readonly int $decimals = 2,
        private readonly string $mode = 'half_up',
    ) {}

    public static function default(): self
    {
        return new self(2, 'half_up');
    }

    public function decimals(): int
    {
        return $this->decimals;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function round(Money $amount): Money
    {
        return $amount->roundTo($this->decimals, $this->mode);
    }

    /**
     * توزيع مبلغ (خصم فاتورة مثلًا) على أوزان، مع إعطاء فرق التقريب لأكبر وزن
     * حتى يساوي مجموع الأنصبة المبلغ الأصلي تمامًا.
     *
     * @param  array<int|string, Money>  $weights
     * @return array<int|string, Money>
     */
    public function allocate(Money $amount, array $weights): array
    {
        $total = Money::zero();
        foreach ($weights as $w) {
            $total = $total->plus($w);
        }

        $result = [];
        if ($total->isZero() || $amount->isZero()) {
            foreach ($weights as $k => $_) {
                $result[$k] = Money::zero();
            }

            return $result;
        }

        $assigned = Money::zero();
        $largestKey = null;
        $largestWeight = Money::zero();

        foreach ($weights as $key => $weight) {
            $share = $this->round($amount->multipliedBy($weight)->dividedBy($total));
            $result[$key] = $share;
            $assigned = $assigned->plus($share);

            if ($weight->isGreaterThan($largestWeight)) {
                $largestWeight = $weight;
                $largestKey = $key;
            }
        }

        $diff = $amount->minus($assigned);
        if (! $diff->isZero() && $largestKey !== null) {
            $result[$largestKey] = $result[$largestKey]->plus($diff);
        }

        return $result;
    }
}
