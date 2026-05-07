<?php
namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public static function log($userId, $operatorType, $targetType, $targetId, $action, $before = null, $after = null, $success = true, $errorMsg = null)
    {
        AuditLog::create([
            'user_id' => $userId,
            'operator_type' => $operatorType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'before_data' => $before ? json_encode($before) : null,
            'after_data' => $after ? json_encode($after) : null,
            'request_method' => Request::method(),
            'request_url' => Request::fullUrl(),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'is_success' => $success,
            'error_message' => $errorMsg,
        ]);
    }
}
