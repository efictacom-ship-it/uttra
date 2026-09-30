<?php

declare(strict_types=1);

namespace App\Shared\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * مبلغ عشري دقيق. لا يُستخدم float في أي حساب مالي أو ضريبي.
 * التخزين الداخلي BigDecimal، والقاعدة DECIMAL(19,6).
 */
final class Money implements \JsonSerializable, \Stringable
{
    public const SCALE = 6;

    private function __construct(private readonly BigDecimal $value) {}

    public static function of(string|int|float|BigDecimal|self|null $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        if ($amount === null) {
            return new self(BigDecimal::zero()->toScale(self::SCALE));
        }

        // الأرقام العشرية القادمة من JSON تُحوَّل نصًا قبل الحساب، فلا يدخل float في العمليات
        $raw = is_float($amount) ? rtrim(rtrim(number_format($amount, self::SCALE, '.', ''), '0'), '.') : (string) $amount;

        return new self(BigDecimal::of($raw === '' ? '0' : $raw)->toScale(self::SCALE, RoundingMode::HALF_UP));
    }

    public static function zero(): self
    {
        return self::of('0');
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value)->toScale(self::SCALE, RoundingMode::HALF_UP));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value)->toScale(self::SCALE, RoundingMode::HALF_UP));
    }

    public function multipliedBy(self|string|int $factor): self
    {
        $f = $factor instanceof self ? $factor->value : BigDecimal::of((string) $factor);

        return new self($this->value->multipliedBy($f)->toScale(self::SCALE, RoundingMode::HALF_UP));
    }

    public function dividedBy(self|string|int $divisor): self
    {
        $d = $divisor instanceof self ? $divisor->value : BigDecimal::of((string) $divisor);

        if ($d->isZero()) {
            throw new \DomainException('لا يمكن القسمة على صفر في حساب مالي.');
        }

        return new self($this->value->dividedBy($d, self::SCALE, RoundingMode::HALF_UP));
    }

    /** تقريب للعرض والترحيل حسب سياسة التقريب الموحدة (منزلتان افتراضيًا). */
    public function roundTo(int $decimals, string $mode = 'half_up'): self
    {
        $rounding = $mode === 'half_even' ? RoundingMode::HALF_EVEN : RoundingMode::HALF_UP;

        return new self($this->value->toScale($decimals, $rounding)->toScale(self::SCALE));
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    public function abs(): self
    {
        return new self($this->value->abs());
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->value->isGreaterThan($other->value);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->value->isGreaterThanOrEqualTo($other->value);
    }

    public function isLessThan(self $other): bool
    {
        return $this->value->isLessThan($other->value);
    }

    public function equals(self $other): bool
    {
        return $this->value->compareTo($other->value) === 0;
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    /** القيمة كما تُخزَّن في DECIMAL(19,6). */
    public function toDb(): string
    {
        return $this->value->toScale(self::SCALE, RoundingMode::HALF_UP)->__toString();
    }

    public function toDecimalString(int $decimals = 2): string
    {
        return $this->value->toScale($decimals, RoundingMode::HALF_UP)->__toString();
    }

    public function toFloatForDisplay(): float
    {
        return (float) $this->toDecimalString(2);
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString(2);
    }

    public function __toString(): string
    {
        return $this->toDb();
    }
}
