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

    public function model(array $row)
    {
        $this->currentRow++;
        
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
                $existingProduct->update([
                    'code' => $row['type'] ?? $existingProduct->code,
                    'specifications' => isset($row['spec']) ? json_decode($row['spec'], true) : $existingProduct->specifications,
                    'price' => floatval($row['price']),
                    'real_stock' => intval($row['stock']),
                    'custom_rule' => isset($row['custom_rule']) ? json_decode($row['custom_rule'], true) : $existingProduct->custom_rule,
                    'category_id' => $category->id,
                    'updated_at' => now(),
                ]);
            } else {
                // 创建新商品
                Product::create([
                    'name' => $row['name'],
                    'code' => $row['type'] ?? '',
                    'specifications' => isset($row['spec']) ? json_decode($row['spec'], true) : [],
                    'price' => floatval($row['price']),
                    'real_stock' => intval($row['stock']),
                    'cover_url' => $row['cover_url'] ?? null,
                    'custom_rule' => isset($row['custom_rule']) ? json_decode($row['custom_rule'], true) : [],
                    'category_id' => $category->id,
                    'status' => 'published',
                    'reserved_stock' => 0,
                    'sold_count' => 0,
                    'version' => 0,
                ]);
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
}
