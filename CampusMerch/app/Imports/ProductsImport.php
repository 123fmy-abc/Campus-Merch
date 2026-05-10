<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\DB;

class ProductsImport implements ToModel, WithHeadingRow, WithValidation, WithBatchInserts, WithChunkReading
{
    protected $successCount = 0;
    protected $failCount = 0;
    protected $errors = [];
    private $currentRow = 0;

    /**
     * 中文表头 -> 英文字段 映射
     *
     * Excel 表头（按顺序）:
     *   商品名称 | 分类 | 商品编码 | 商品描述 | 规格类型 | 规格详情 | 价格 | 库存数量 |
     *   限购数量 | 封面图片 | 定制规则 | 需要设计稿 | 上架状态
     */
    private array $columnMapping = [
        '商品名称' => 'name',
        '分类' => 'category',
        '商品编码' => 'code',
        '商品描述' => 'description',
        '规格类型' => 'type',
        '规格详情' => 'spec',
        '价格' => 'price',
        '库存数量' => 'stock',
        '限购数量' => 'max_buy_limit',
        '封面图片' => 'cover_url',
        '定制规则' => 'custom_rule',
        '需要设计稿' => 'need_design',
        '上架状态' => 'status',
    ];

    /**
     * 将中文表头转换为英文字段名
     */
    private function mapRow(array $row): array
    {
        $mapped = [];
        foreach ($row as $key => $value) {
            // 如果是中文表头，转换为英文；否则保持原样（兼容英文表头）
            $englishKey = $this->columnMapping[trim($key)] ?? $key;
            $mapped[$englishKey] = $value;
        }
        return $mapped;
    }

    public function model(array $row)
    {
        $this->currentRow++;
        
        // 映射中文表头为英文字段
        $row = $this->mapRow($row);
        
        try {
            // 1. 查找分类ID
            $category = Category::where('name', $row['category'] ?? null)->first();
            if (!$category) {
                throw new \Exception("分类 '{$row['category']}' 不存在");
            }

            // 2. 检查商品是否存在（根据名称判断是创建还是更新）
            $existingProduct = Product::where('name', $row['name'])->first();

            if ($existingProduct) {
                // 更新现有商品
                $updateData = [
                    'code'           => $row['code'] ?? $existingProduct->code,
                    'description'    => $row['description'] ?? $existingProduct->description,
                    'specifications' => isset($row['spec']) ? json_decode($row['spec'], true) : $existingProduct->specifications,
                    'price'          => floatval($row['price']),
                    'real_stock'     => intval($row['stock']),
                    'max_buy_limit'  => isset($row['max_buy_limit']) ? intval($row['max_buy_limit']) : $existingProduct->max_buy_limit,
                    'cover_url'      => $row['cover_url'] ?? $existingProduct->cover_url,
                    'custom_rule'    => isset($row['custom_rule']) ? json_decode($row['custom_rule'], true) : $existingProduct->custom_rule,
                    'need_design'    => isset($row['need_design']) ? $this->parseBool($row['need_design']) : $existingProduct->need_design,
                    'status'         => $row['status'] ?? $existingProduct->status,
                    'category_id'    => $category->id,
                    'updated_at'     => now(),
                ];
                // status 字段校验：只允许合法值
                if (isset($row['status']) && !in_array($row['status'], ['draft', 'published', 'archived'])) {
                    throw new \Exception("上架状态 '{$row['status']}' 不合法，仅支持: draft(草稿)/published(上架)/archived(下架)");
                }
                $existingProduct->update($updateData);

                // 同步封面图到 product_images 表
                $this->syncCoverImage($existingProduct, $updateData['cover_url'] ?? null);
            } else {
                // 创建新商品
                $importStatus = $row['status'] ?? 'published';
                if (!in_array($importStatus, ['draft', 'published', 'archived'])) {
                    throw new \Exception("上架状态 '{$importStatus}' 不合法，仅支持: draft(草稿)/published(上架)/archived(下架)");
                }

                $newProduct = Product::create([
                    'name'           => $row['name'],
                    'code'           => $row['code'] ?? '',
                    'description'    => $row['description'] ?? '',
                    'specifications' => isset($row['spec']) ? json_decode($row['spec'], true) : [],
                    'price'          => floatval($row['price']),
                    'real_stock'     => intval($row['stock']),
                    'max_buy_limit'  => isset($row['max_buy_limit']) ? intval($row['max_buy_limit']) : 0,
                    'cover_url'      => $row['cover_url'] ?? null,
                    'custom_rule'    => isset($row['custom_rule']) ? json_decode($row['custom_rule'], true) : [],
                    'need_design'    => isset($row['need_design']) ? $this->parseBool($row['need_design']) : 0,
                    'status'         => $importStatus,
                    'category_id'    => $category->id,
                    'reserved_stock' => 0,
                    'sold_count'     => 0,
                    'version'        => 0,
                ]);

                // 同步封面图到 product_images 表
                $this->syncCoverImage($newProduct, $row['cover_url'] ?? null);
            }

            $this->successCount++;

        } catch (\Exception $e) {
            $this->failCount++;
            $this->errors[] = [
                'row' => $this->currentRow + 1, // +1 因为有标题行
                'field' => 'general',
                'reason' => $e->getMessage(),
            ];
        }

        return null; // 不返回模型，我们手动处理
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',          // 商品名称必填
            'price' => 'required|numeric|min:0.01',        // 价格必须大于0
            'stock' => 'required|integer|min:0',           // 库存不能小于0
            'category' => 'required|string',               // 分类必填
            '*.0' => function ($attribute, $value, $onFail) {
                // 自定义行级校验逻辑
            },
        ];
    }

    public function batchSize(): int
    {
        return 100; // 每100行一批次插入
    }

    public function chunkSize(): int
    {
        return 200; // 分块读取，每块200行
    }

    // 辅助方法：获取结果
    public function getSuccessCount() { return $this->successCount; }
    public function getFailCount() { return $this->failCount; }
    public function getErrors() { return $this->errors; }

    /**
     * 将各种格式的值转为 0/1 整数
     * 支持: 是/否, true/false, 1/0, Y/N
     */
    private function parseBool($value): int
    {
        if (is_numeric($value)) {
            return intval($value) > 0 ? 1 : 0;
        }
        return in_array(strtolower(trim($value)), ['是', 'yes', 'y', 'true', '1']) ? 1 : 0;
    }

    /**
     * 同步封面图到 product_images 表
     * 规则：有 cover_url 则插入/更新 is_main=1 的记录；为空则删除主图记录
     */
    private function syncCoverImage(Product $product, $coverUrl): void
    {
        if (empty($coverUrl)) {
            // cover_url 为空，清除该商品的主图标记
            $product->images()->where('is_main', true)->update(['is_main' => false]);
            return;
        }

        // 查找是否已有主图
        $existingMain = $product->images()->where('is_main', true)->first();
        if ($existingMain) {
            // 更新已有主图的 URL（Excel 可能换了封面）
            $existingMain->update([
                'file_path' => $coverUrl,
                'file_url'  => $coverUrl,
            ]);
        } else {
            // 新建主图记录
            $product->images()->create([
                'file_path'  => $coverUrl,
                'file_url'   => $coverUrl,
                'is_main'    => true,
                'sort_order' => 0,
            ]);
        }
    }
}
