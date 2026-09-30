<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AccountingPeriod;
use DomainException;
use Illuminate\Support\Carbon;

final class PeriodService
{
    /** الفترة المفتوحة التي تغطي التاريخ، أو خطأ واضح بالعربية. */
    public function openPeriodFor(string $businessDate): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->where('starts_on', '<=', $businessDate)
            ->where('ends_on', '>=', $businessDate)
            ->first();

        if (! $period) {
            throw new DomainException("لا توجد فترة محاسبية تغطي التاريخ {$businessDate}. أنشئ الفترة أولًا من الإعدادات.");
        }

        if (! $period->isOpen()) {
            throw new DomainException("الفترة «{$period->name}» مغلقة، فلا يمكن الترحيل في التاريخ {$businessDate}.");
        }

        return $period;
    }

    /** يمنع تداخل الفترات: قاعدة لا يعبّر عنها MySQL إعلاميًا. */
    public function assertNoOverlap(string $startsOn, string $endsOn, ?int $ignoreId = null): void
    {
        if ($endsOn < $startsOn) {
            throw new DomainException('تاريخ نهاية الفترة يجب أن يكون بعد تاريخ بدايتها.');
        }

        $overlap = AccountingPeriod::query()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $startsOn)
            ->first();

        if ($overlap) {
            throw new DomainException("الفترة تتداخل مع الفترة القائمة «{$overlap->name}».");
        }
    }

    public function createYear(int $year, int $startMonth = 1): AccountingPeriod
    {
        $start = Carbon::create($year, $startMonth, 1)->startOfMonth();
        $end = $start->copy()->addYear()->subDay();

        $this->assertNoOverlap($start->toDateString(), $end->toDateString());

        return AccountingPeriod::query()->create([
            'name' => 'السنة المالية '.$year,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'status' => 'open',
        ]);
    }

    public function close(AccountingPeriod $period, int $userId): void
    {
        if (! $period->isOpen()) {
            throw new DomainException('الفترة مغلقة بالفعل.');
        }

        $draftCount = \App\Shared\Documents\Models\Document::query()
            ->whereIn('status', ['draft', 'prepared'])
            ->whereBetween('business_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])
            ->count();

        if ($draftCount > 0) {
            throw new DomainException(
                "لا يمكن إقفال الفترة «{$period->name}»: يوجد {$draftCount} مستند(مستندات) بحالة مسودة بتاريخ ضمن هذه الفترة. رحّلها أو ألغِها أولًا."
            );
        }

        $period->update([
            'status' => 'closed',
            'closed_by' => $userId,
            'closed_at' => now(),
        ]);
    }

    public function reopen(AccountingPeriod $period): void
    {
        $period->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
    }
}
