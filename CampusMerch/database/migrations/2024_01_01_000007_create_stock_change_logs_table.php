<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 库存变动日志表
 * 记录商品库存（real_stock / reserved_stock）的每一次变化
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_change_logs', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->foreignId('product_id')->constrained('products')->comment('商品ID');
            $table->string('type', 50)->comment('变动类型：reserve-预订扣减/restore-释放/cancel-取消扣减/manual-手动调整/import-导入修正');
            $table->integer('change_qty')->comment('变动数量（正增负减）');
            $table->integer('stock_before')->comment('变动前实物库存');
            $table->integer('reserved_before')->default(0)->comment('变动前预扣库存');
            $table->integer('stock_after')->comment('变动后实物库存');
            $table->integer('reserved_after')->default(0)->comment('变动后预扣库存');

            // 多态关联（订单 / 导入批次 / 手动操作等）
            $table->string('related_type', 50)->nullable()->comment('关联类型：order-订单，import-导入批次');
            $table->unsignedBigInteger('related_id')->nullable()->comment('关联ID');

            $table->foreignId('operator_id')->nullable()->constrained('users')->comment('操作人ID');
            $table->string('operator_type', 20)->nullable()->comment('操作人类型：admin-管理员，system-系统自动');

            $table->string('remark', 500)->nullable()->comment('备注');
            $table->string('ip_address', 45)->nullable()->comment('操作IP');
            $table->timestamps();
        });

        // 为高频查询场景添加索引
        Schema::table('stock_change_logs', function (Blueprint $table) {
            $table->index(['product_id', 'created_at'], 'idx_stock_product_time');
            $table->index(['type', 'created_at'], 'idx_stock_type_time');
            $table->index(['related_type', 'related_id'], 'idx_stock_related');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_change_logs');
    }
};
