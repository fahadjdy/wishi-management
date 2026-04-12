<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wishi;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public function log(?Wishi $wishi, ?User $user, string $action, ?string $description = null, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'wishi_id' => $wishi?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
        ]);
    }
}
