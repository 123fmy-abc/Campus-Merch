<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单附件表
 * 存储用户上传的设计稿/支付凭证，关联订单
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_attachments', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->foreignId('order_id')->constrained()->onDelete('cascade')->comment('订单ID，关联orders表');
            $table->enum('type', ['design', 'payment_proof'])->default('design')->comment('附件类型：design-设计稿，payment_proof-支付凭证');
            $table->string('file_name')->comment('原始文件名');
            $table->string('file_path')->comment('OSS存储路径');
            $table->string('file_url')->comment('访问URL（带签名）');
            $table->string('mime_type')->comment('文件MIME类型');
            $table->unsignedInteger('file_size')->comment('文件大小（字节）');
            $table->unsignedInteger('width')->nullable()->comment('图片宽度');
            $table->unsignedInteger('height')->nullable()->comment('图片高度');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_attachments');
    }
};
