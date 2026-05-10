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
        'entity_type', 'entity_id', 'action', 'operator_id',
        'old_values', 'new_values', 'ip_address','user_agent','remark', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
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


}
