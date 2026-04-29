<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品表
 * 存储文创/物料商品信息，支持库存预扣和乐观锁
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id()->comment('主键ID');
            $table->string('name')->comment('商品名称');
            $table->foreignId('category_id')->constrained()->comment('分类ID，关联categories表');
            $table->string('code')->unique()->comment('商品编码，唯一标识');
            $table->text('description')->nullable()->comment('商品描述');
            $table->json('specifications')->nullable()->comment('规格参数JSON，如：尺寸、颜色选项');
            $table->decimal('price', 10, 2)->comment('单价');
            $table->unsignedInteger('real_stock')->default(0)->comment('实际库存');
            $table->unsignedInteger('reserved_stock')->default(0)->comment('已预订未发货数量（预扣库存）');
            $table->unsignedInteger('sold_count')->default(0)->comment('已售出数量');
            $table->unsignedInteger('max_buy_limit')->comment('每人限购数量');
            $table->string('cover_url')->nullable()->comment('封面图OSS路径');
            // 定制规则JSON：{"sizes": ["M", "L"], "colors": ["红", "蓝"], "max_file_size": 15}
            $table->json('custom_rule')->nullable()->comment('定制规则JSON');
            $table->boolean('need_design')->default(false)->comment('是否需要定制设计稿');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->comment('状态：draft-草稿，published-上架，archived-下架');
            $table->unsignedInteger('version')->default(0)->comment('乐观锁版本号，防并发覆盖');
            $table->timestamps();
            $table->softDeletes();

            // 索引优化
            $table->index(['status', 'category_id']);
            $table->index('allow_custom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
