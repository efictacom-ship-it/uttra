<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Modules\Operations\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

final class AuditService
{
    /** حقول لا تُسجَّل أبدًا في سجل التدقيق. */
    private const REDACTED = ['password', 'password_hash', 'password_confirmation', 'token', 'otp', 'secret', 'credentials_secret_ref'];

    public function log(string $entityType, ?int $entityId, string $action, ?array $before = null, ?array $after = null): void
    {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $before ? $this->redact($before) : null,
            'after_json' => $after ? $this->redact($after) : null,
            'request_id' => Request::header('X-Request-Id'),
            'ip_address' => Request::ip(),
        ]);
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                $data[$key] = '[محجوب]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
