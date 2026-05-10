<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 审计日志表
 * 记录关键操作（订单审核、商品修改等）用于操作留痕
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->string('entity_type')->comment('实体类型：order-订单，product-商品');
            $table->unsignedBigInteger('entity_id')->comment('实体ID');
            $table->string('action')->comment('操作类型：create-创建，update-更新，review-审核等');
            $table->foreignId('operator_id')->constrained('users')->comment('操作人ID');
            $table->json('old_values')->nullable()->comment('变更前的数据');
            $table->json('new_values')->nullable()->comment('变更后的数据');
            $table->string('ip_address', 45)->nullable()->comment('操作IP');
            $table->string('user_agent', 255)->nullable()->comment('浏览器信息');
            $table->string('operator_type')->nullable()->comment('操作人类型：User-普通用户，Admin-管理员，System-系统');
            $table->boolean('is_success')->default(true)->comment('操作是否成功');
            $table->text('error_message')->nullable()->comment('错误信息（失败时记录）');
            $table->text('remark')->nullable()->comment('操作备注');
            $table->timestamp('created_at')->comment('操作时间');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
