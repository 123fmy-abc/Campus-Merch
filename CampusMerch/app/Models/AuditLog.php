<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    // 定义动作常量（便于代码中引用，避免硬编码字符串）
    public const ACTION_CREATE = 'create';          // 创建操作
    public const ACTION_UPDATE = 'update';          // 更新操作
    public const ACTION_DELETE = 'delete';          // 删除操作（软删除或硬删除）
    public const ACTION_REVIEW = 'review';          // 审核操作（订单/附件审核）
    public const ACTION_COMPLETE = 'complete';      // 完成操作（订单核销）
    public const ACTION_UPLOAD = 'upload';          // 上传操作（文件/图片）
    public const ACTION_IMPORT = 'import';          // 导入操作（Excel批量导入）
    public const ACTION_EXPORT = 'export';          // 导出操作（Excel报表导出）
    public const ACTION_LOGIN = 'login';            // 登录操作
    public const ACTION_LOGOUT = 'logout';          // 登出操作
    public const ACTION_CANCEL = 'cancel';          // 取消操作（订单取消）
    public const ACTION_SET_MAIN = 'set_main';      // 设置主图操作
    public const ACTION_RELEASE_STOCK = 'release_stock'; // 释放库存操作

    // 操作人类型常量
    public const OPERATOR_USER = 'User';            // 操作人为普通用户
    public const OPERATOR_ADMIN = 'Admin';          // 操作人为管理员
    public const OPERATOR_SYSTEM = 'System';        // 操作为系统自动触发

    /**
     * 关联的数据表名
     *
     * @var string
     */
    protected $table = 'audit_logs';

    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'entity_type', 'entity_id', 'action', 'operator_id', 'operator_name',
        'old_values', 'new_values', 'remark', 'created_at',
        'user_id',            // 操作人ID（关联users表，可为空，比如系统操作）
        'operator_type',      // 操作人类型：User/Admin/System
        'target_type',        // 操作目标类型（例如 'Order', 'Product', 'User'，用于多态关联）
        'target_id',          // 操作目标ID（与target_type组成多态关联）
        'action',             // 操作动作（对应上面的常量，如 'create', 'update'）
        'summary',            // 操作摘要（简短描述，便于快速查看）
        'before_data',        // 变更前的数据（JSON格式）
        'after_data',         // 变更后的数据（JSON格式）
        'request_id',         // 请求唯一标识（可用于追踪一次请求关联的多条日志）
        'request_method',     // HTTP请求方法（GET, POST, PUT, DELETE等）
        'request_url',        // 完整的请求URL
        'response_code',      // HTTP响应状态码
        'execution_time',     // 请求执行耗时（毫秒）
        'is_success',         // 操作是否成功（布尔值）
        'error_message',      // 错误信息（当is_success=false时记录）
        'batch_id',           // 批次ID（用于批量操作，将多条日志关联到同一批次）
        'ip_address',         // 操作者的IP地址
        'user_agent',         // 操作者的浏览器User-Agent字符串
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'before_data'   => 'array',     // 自动将JSON字段转换为PHP数组
        'after_data'    => 'array',     // 自动将JSON字段转换为PHP数组
        'response_code' => 'integer',   // 转为整型
        'execution_time' => 'integer',  // 转为整型
        'is_success'    => 'boolean',   // 转为布尔值
        'updated_at'    => 'datetime',
    ];

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
    /**
     * 关联操作人（多态关联中的User模型）
     * 注意：由于操作人可能是User或Admin，但两者都使用users表，
     * 因此直接关联User模型即可。
     *
     */
    public function user()
    {
        // 外键 user_id 参照 users.id，允许为空
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 定义多态关联的目标对象（被操作的对象）
     * 通过 target_type 和 target_id 动态关联到对应的模型（如 Order, Product, User等）
     * 使用此方法可以方便地获取被操作的原对象。
     */
    public function target()
    {
        // Laravel 会根据 target_type 和 target_id 自动解析对应的模型类
        return $this->morphTo();
    }

    /**
     * 获取操作人的类型文本描述（用于展示）
     *
     */
    public function getOperatorTypeTextAttribute()
    {
        $map = [
            self::OPERATOR_USER   => '普通用户',
            self::OPERATOR_ADMIN  => '管理员',
            self::OPERATOR_SYSTEM => '系统',
        ];
        return $map[$this->operator_type] ?? '未知';
    }

    /**
     * 获取操作动作的文本描述（用于前端展示）
     *
     * @return string
     */
    public function getActionTextAttribute()
    {
        $map = [
            self::ACTION_CREATE   => '创建',
            self::ACTION_UPDATE   => '更新',
            self::ACTION_DELETE   => '删除',
            self::ACTION_REVIEW   => '审核',
            self::ACTION_COMPLETE => '完成',
            self::ACTION_UPLOAD   => '上传',
            self::ACTION_IMPORT   => '导入',
            self::ACTION_EXPORT   => '导出',
            self::ACTION_LOGIN    => '登录',
            self::ACTION_LOGOUT   => '登出',
            self::ACTION_CANCEL   => '取消',
        ];
        return $map[$this->action] ?? $this->action;
    }

    /**
     * 限定查询：按目标类型和ID过滤
     *
     */
    public function scopeForTarget($query, $targetType, $targetId)
    {
        return $query->where('target_type', $targetType)
            ->where('target_id', $targetId);
    }

    /**
     * 限定查询：按操作动作过滤
     *
     */
    public function scopeOfAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * 限定查询：只查询成功操作
     *
     */
    public function scopeSuccessful($query)
    {
        return $query->where('is_success', true);
    }

    /**
     * 限定查询：只查询失败操作
     *
     */
    public function scopeFailed($query)
    {
        return $query->where('is_success', false);
    }
}
