<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单表
 * 存储预订订单信息，支持6种状态流转和库存预扣
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->string('order_no')->unique()->comment('订单编号，唯一标识');
            $table->foreignId('user_id')->constrained()->comment('用户ID，关联users表');
            $table->foreignId('product_id')->constrained()->comment('商品ID，关联products表');
            $table->unsignedInteger('quantity')->comment('预订数量');
            $table->decimal('unit_price', 10, 2)->comment('下单时单价（快照）');
            $table->decimal('total_amount', 10, 2)->comment('订单总金额');
            $table->json('snapshot')->nullable()->comment('商品快照：下单时商品名称、价格、规格等JSON数据');
            $table->string('size')->nullable()->comment('尺寸规格');
            $table->string('color')->nullable()->comment('颜色偏好');
            $table->text('remark')->nullable()->comment('用户备注');
            $table->string('recipient_name')->nullable()->comment('收货人姓名');
            $table->string('recipient_phone')->nullable()->comment('收货人电话');
            $table->text('recipient_address')->nullable()->comment('收货地址');
            $table->enum('status', ['draft', 'booked', 'design_pending', 'design_reviewing', 'ready', 'shipped', 'completed', 'rejected', 'cancelled'])->default('draft')->comment('订单状态：draft-待提交，booked-已预订，design_pending-定制待审，design_reviewing-审核中，ready-待发货，shipped-已发货，completed-已完成，rejected-已驳回，cancelled-已取消');
            $table->timestamp('submitted_at')->nullable()->comment('订单提交时间');
            $table->timestamp('design_uploaded_at')->nullable()->comment('设计稿上传时间');
            $table->string('payment_proof_url')->nullable()->comment('支付凭证OSS路径（线下转账截图）');
            $table->timestamp('paid_at')->nullable()->comment('支付时间（线下支付凭证上传时间）');
            $table->unsignedBigInteger('reviewed_by')->nullable()->comment('审核人ID');
            $table->timestamp('reviewed_at')->nullable()->comment('管理员审核时间');
            $table->string('reject_reason')->nullable()->comment('审核备注/驳回原因');
            $table->timestamp('completed_at')->nullable()->comment('订单完成时间');
            
            // 取消相关字段
            $table->string('cancel_reason')->nullable()->comment('取消原因');
            $table->timestamp('cancelled_at')->nullable()->comment('取消时间');
            $table->unsignedBigInteger('cancelled_by')->nullable()->comment('取消人ID，关联users表');
            
            $table->timestamps();

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
            
            $table->foreign('cancelled_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
