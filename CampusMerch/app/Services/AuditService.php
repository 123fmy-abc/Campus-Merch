<?php
namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public static function log($userId, $operatorType, $targetType, $targetId, $action, $before = null, $after = null, $success = true, $errorMsg = null)
    {
        AuditLog::create([
            'operator_id' => $userId,
            'operator_type' => $operatorType,
            'entity_type' => $targetType,
            'entity_id' => $targetId,
            'action' => $action,
            'old_values' => $before ? json_encode($before) : null,
            'new_values' => $after ? json_encode($after) : null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'is_success' => $success,
            'error_message' => $errorMsg,
            'created_at' => now(),
        ]);
    }
}
