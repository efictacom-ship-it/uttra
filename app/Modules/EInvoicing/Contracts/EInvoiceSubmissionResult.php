<?php

declare(strict_types=1);

namespace App\Modules\EInvoicing\Contracts;

/**
 * نتيجة محاولة إرسال واحدة. "unknown" و"connection_error" تعنيان أن مصير الفاتورة
 * لدى الجهة الخارجية غير معروف بعد — ليس مرفوضًا وليس مقبولًا — فلا يجوز اعتبارها
 * رفضًا ولا اعتماد الفاتورة استنادًا إليها، ولا إبطال المستند أو تحرير حجوزاته تلقائيًا.
 */
final class EInvoiceSubmissionResult
{
    private function __construct(
        public readonly string $result,
        public readonly ?int $httpStatus,
        public readonly array $responsePayload,
        public readonly ?string $errorMessage,
        public readonly ?string $clearedXmlStorageKey,
        public readonly ?string $qrPayload,
    ) {}

    public static function accepted(array $responsePayload, ?string $clearedXmlStorageKey = null, ?string $qrPayload = null): self
    {
        return new self('accepted', 200, $responsePayload, null, $clearedXmlStorageKey, $qrPayload);
    }

    public static function rejected(array $responsePayload, ?string $errorMessage = null): self
    {
        return new self('rejected', 400, $responsePayload, $errorMessage, null, null);
    }

    public static function unknown(?string $errorMessage = null): self
    {
        return new self('unknown', null, [], $errorMessage, null, null);
    }

    public static function connectionError(?string $errorMessage = null): self
    {
        return new self('connection_error', null, [], $errorMessage, null, null);
    }

    public function isSettled(): bool
    {
        return in_array($this->result, ['accepted', 'rejected'], true);
    }
}
