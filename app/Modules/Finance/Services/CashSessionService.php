<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CashSession;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Models\Payment;
use App\Shared\Audit\AuditService;
use App\Shared\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * صندوق الكاشير: جلسة مفتوحة واحدة لكل حساب مالي (مضمونة بقيد فريد في القاعدة).
 * النقدي المتوقع عند الإغلاق = الافتتاحي + التحصيل النقدي − الصرف النقدي خلال الجلسة،
 * والفرق عن المعدود يُوثَّق بسبب لكنه لا يُنشئ قيدًا تلقائيًا (فروق الصندوق تُعالَج يدويًا).
 */
final class CashSessionService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function open(int $financialAccountId, string $openingCash, int $userId): CashSession
    {
        return DB::transaction(function () use ($financialAccountId, $openingCash, $userId): CashSession {
            $account = FinancialAccount::query()->findOrFail($financialAccountId);

            if (! $account->is_active) {
                throw new DomainException('الحساب المالي غير نشط.');
            }

            $existing = CashSession::query()->where('financial_account_id', $financialAccountId)->whereNull('closed_at')->first();

            if ($existing) {
                throw new DomainException('يوجد صندوق مفتوح بالفعل على هذا الحساب.');
            }

            $opening = Money::of($openingCash);

            if ($opening->isNegative()) {
                throw new DomainException('الرصيد الافتتاحي لا يمكن أن يكون سالبًا.');
            }

            $session = CashSession::query()->create([
                'financial_account_id' => $financialAccountId,
                'opened_by' => $userId,
                'opened_at' => now(),
                'opening_cash' => $opening->toDb(),
            ]);

            $this->audit->log('cash_session', $session->id, 'opened', null, ['financial_account_id' => $financialAccountId, 'opening_cash' => $opening->toDb()]);

            return $session;
        });
    }

    public function close(int $cashSessionId, string $countedCash, ?string $reason, int $userId): CashSession
    {
        return DB::transaction(function () use ($cashSessionId, $countedCash, $reason, $userId): CashSession {
            $session = CashSession::query()->lockForUpdate()->findOrFail($cashSessionId);

            if (! $session->isOpen()) {
                throw new DomainException('الصندوق مغلق بالفعل.');
            }

            $counted = Money::of($countedCash);

            if ($counted->isNegative()) {
                throw new DomainException('النقدية المعدودة لا يمكن أن تكون سالبة.');
            }

            $expected = $this->expectedCash($session);
            $difference = $counted->minus($expected);

            if (! $difference->isZero() && ! $reason) {
                throw new DomainException('يوجد فرق بين النقدية المعدودة والمتوقعة؛ يجب توثيق السبب.');
            }

            $session->update([
                'closed_at' => now(),
                'expected_cash' => $expected->toDb(),
                'counted_cash' => $counted->toDb(),
                'difference_reason' => $reason,
            ]);

            $this->audit->log('cash_session', $session->id, 'closed', null, [
                'expected_cash' => $expected->toDb(),
                'counted_cash' => $counted->toDb(),
                'difference' => $difference->toDb(),
            ]);

            return $session->refresh();
        });
    }

    public function expectedCash(CashSession $session): Money
    {
        $receipts = Money::of(Payment::query()
            ->where('cash_session_id', $session->id)
            ->where('direction', 'receipt')
            ->where('method', 'cash')
            ->sum('amount') ?? '0');

        $disbursements = Money::of(Payment::query()
            ->where('cash_session_id', $session->id)
            ->where('direction', 'disbursement')
            ->where('method', 'cash')
            ->sum('amount') ?? '0');

        return Money::of($session->opening_cash)->plus($receipts)->minus($disbursements);
    }
}
