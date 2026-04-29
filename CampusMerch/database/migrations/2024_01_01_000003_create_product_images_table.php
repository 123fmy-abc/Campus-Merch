<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品图片表
 * 存储商品的多张展示图，支持排序和封面标记
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->foreignId('product_id')->constrained()->onDelete('cascade')->comment('商品ID，关联products表');
            $table->string('file_path')->comment('OSS存储路径');
            $table->string('file_url')->comment('访问URL（带签名）');
            $table->unsignedTinyInteger('sort_order')->default(0)->comment('排序顺序，数字越小越靠前');
            $table->boolean('is_main')->default(false)->comment('是否为封面图：0-否，1-是');
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
