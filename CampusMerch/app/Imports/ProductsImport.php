<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Validators\Failure;

class ProductsImport implements ToModel, WithHeadingRow, WithValidation, WithChunkReading, SkipsOnFailure
{
    use SkipsFailures;

    protected bool $updateExisting;
    protected array $categoryMap = [];
    protected array $existingProducts = [];
    protected array $result = [
        'total_rows'    => 0,
        'success_count' => 0,
        'create_count'  => 0,
        'update_count'  => 0,
        'fail_count'    => 0,
        'errors'        => [],
    ];
    protected array $processedCodes = [];
    protected int $currentRow = 0;

    public function __construct(bool $updateExisting = true)
    {
        $this->updateExisting = $updateExisting;
        
        $categories = Category::where('status', 1)->get(['id', 'name']);
        foreach ($categories as $category) {
            $this->categoryMap[strtolower(trim($category->name))] = $category->id;
        }

        $this->existingProducts = Product::pluck('id', 'code')->toArray();
    }

    public function model(array $row)
    {
        $this->currentRow++;
        $this->result['total_rows']++;

        $categoryName = strtolower(trim($row['category_name'] ?? ''));
        $categoryId = $this->categoryMap[$categoryName] ?? null;

        if (!$categoryId) {
            $this->result['fail_count']++;
            $this->result['errors'][] = [
                'row'    => $this->currentRow + 1,
                'field'  => 'category_name',
                'reason' => "分类 '{$row['category_name']}' 不存在或已禁用",
            ];
            return null;
        }

        $code = trim($row['code'] ?? '');
        if (isset($this->processedCodes[$code])) {
            $this->result['fail_count']++;
            $this->result['errors'][] = [
                'row'    => $this->currentRow + 1,
                'field'  => 'code',
                'reason' => "商品编码 '{$code}' 在Excel中重复",
            ];
            return null;
        }

        $this->processedCodes[$code] = true;

        $specifications = $this->parseSpecifications($row['specifications'] ?? null);
        $customRule = $this->parseCustomRule($row['custom_rule'] ?? null);
        $needDesign = $this->parseBoolean($row['need_design'] ?? false);

        $status = strtolower(trim($row['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'published', 'archived'])) {
            $status = 'draft';
        }

        $productData = [
            'name'           => trim($row['name'] ?? ''),
            'category_id'    => $categoryId,
            'description'    => !empty($row['description']) ? trim($row['description']) : null,
            'specifications' => $specifications,
            'price'          => (float) ($row['price'] ?? 0),
            'real_stock'     => (int) ($row['stock'] ?? 0),
            'cover_url'      => !empty($row['cover_url']) ? trim($row['cover_url']) : null,
            'custom_rule'    => $customRule,
            'need_design'    => $needDesign,
            'status'         => $status,
            'max_buy_limit'  => (int) ($row['max_buy_limit'] ?? 99),
        ];

        if (isset($this->existingProducts[$code]) && $this->updateExisting) {
            $productId = $this->existingProducts[$code];
            $product = Product::find($productId);
            
            if ($product) {
                $productData['version'] = $product->version + 1;
                $product->update($productData);
                $this->result['update_count']++;
                $this->result['success_count']++;
            }
            
            return null;
        }

        $productData['code'] = $code;
        $productData['version'] = 0;
        
        $this->result['create_count']++;
        $this->result['success_count']++;

        return new Product($productData);
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'category_name' => 'required|string|max:255',
            'code'          => 'required|string|max:255',
            'price'         => 'required|numeric|min:0.01|max:99999999.99',
            'stock'         => 'required|integer|min:0|max:999999999',
            'description'   => 'nullable|string',
            'specifications'=> 'nullable',
            'cover_url'     => 'nullable|string|max:500',
            'custom_rule'   => 'nullable',
            'need_design'   => 'nullable',
            'status'        => 'nullable|in:draft,published,archived',
            'max_buy_limit' => 'nullable|integer|min:1',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'name.required'          => '商品名称不能为空',
            'name.max'               => '商品名称不能超过255字符',
            'category_name.required' => '分类名称不能为空',
            'code.required'          => '商品编码不能为空',
            'code.max'               => '商品编码不能超过255字符',
            'price.required'         => '价格不能为空',
            'price.numeric'          => '价格必须是数字',
            'price.min'              => '价格必须为正数',
            'stock.required'         => '库存不能为空',
            'stock.integer'          => '库存必须是整数',
            'stock.min'              => '库存不能为负数',
            'max_buy_limit.min'      => '限购数量必须为正整数',
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $this->result['fail_count']++;
            $this->result['errors'][] = [
                'row'    => $failure->row(),
                'field'  => $failure->attribute(),
                'reason' => implode(', ', $failure->errors()),
            ];
        }
    }

    public function getResult(): array
    {
        return $this->result;
    }

    private function parseSpecifications($value): ?array
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);
        
        $json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        if (strpos($value, ':') !== false || strpos($value, '：') !== false) {
            $result = [];
            $pairs = preg_split('/[;；]/', $value);
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                if (empty($pair)) continue;
                
                $parts = preg_split('/[:：]/', $pair, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $values = array_map('trim', preg_split('/[,，]/', $parts[1]));
                    $result[$key] = array_values(array_filter($values));
                }
            }
            return !empty($result) ? $result : null;
        }

        return null;
    }

    private function parseCustomRule($value): ?array
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);
        $json = json_decode($value, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        return null;
    }

    private function parseBoolean($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['true', 'yes', 'y', '是', '1', '需要', 'yes', '要']);
    }
}
