<?php

namespace App\Exports;

use App\Models\Order;
use App\Services\OssService;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithStyles
{
    use Exportable;

    protected array $filters;
    protected array $columns;
    protected int $chunkSize = 200;
    protected ?OssService $ossService = null;

    // 可导出的列定义
    protected array $availableColumns = [
        'order_no'           => '订单编号',
        'status_text'        => '订单状态',
        'user_name'          => '预订用户',
        'user_phone'         => '用户电话',
        'product_name'       => '商品名称',
        'product_code'       => '商品编码',
        'quantity'           => '预订数量',
        'size'               => '尺寸',
        'color'              => '颜色',
        'unit_price'         => '单价',
        'total_amount'       => '订单金额',
        'recipient_name'     => '收货人姓名',
        'recipient_phone'    => '收货人电话',
        'recipient_address'  => '收货地址',
        'remark'             => '用户备注',
        'design_url'         => '定制稿链接',
        'payment_proof_url'  => '支付凭证链接',
        'submitted_at'       => '提交时间',
        'design_uploaded_at' => '设计稿上传时间',
        'reviewed_at'        => '审核时间',
        'reviewer_name'      => '审核人',
        'review_remark'      => '审核备注',
        'completed_at'       => '完成时间',
        'cancel_reason'      => '取消原因',
    ];

    // 状态映射
    protected array $statusMap = [
        'draft'          => '待提交',
        'booked'         => '已预订',
        'design_pending' => '定制待审',
        'ready'          => '待发货',
        'completed'      => '已完成',
        'rejected'       => '已驳回',
        'cancelled'      => '已取消',
    ];

    public function __construct(array $filters = [], array $columns = [])
    {
        $this->filters = $filters;
        $this->columns = $this->resolveColumns($columns);
        $this->ossService = app(OssService::class);
    }

    /**
     * 查询构造器
     */
    public function query()
    {
        $query = Order::query()
            ->with(['user', 'product', 'reviewer', 'attachments']);

        // 应用筛选条件
        $this->applyFilters($query);

        return $query->orderBy('id');
    }

    /**
     * 表头
     */
    public function headings(): array
    {
        $headings = [];
        foreach ($this->columns as $column) {
            $headings[] = $this->availableColumns[$column] ?? $column;
        }
        return $headings;
    }

    /**
     * 行数据映射
     */
    public function map($order): array
    {
        $row = [];

        foreach ($this->columns as $column) {
            $row[] = $this->getColumnValue($order, $column);
        }

        return $row;
    }

    /**
     * 分块读取，防止内存溢出
     */
    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * 设置分块大小
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }

    /**
     * Excel 样式
     */
    public function styles(Worksheet $sheet)
    {
        // 表头样式：加粗、背景色
        $sheet->getStyle('1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'E0E0E0',
                ],
            ],
        ]);

        // 冻结首行
        $sheet->freezePane('A2');

        return $sheet;
    }

    /**
     * 解析列配置
     */
    protected function resolveColumns(array $columns): array
    {
        // 如果未指定列，导出所有列
        if (empty($columns)) {
            return array_keys($this->availableColumns);
        }

        // 过滤无效的列
        return array_filter($columns, function ($column) {
            return isset($this->availableColumns[$column]);
        });
    }

    /**
     * 应用筛选条件
     */
    protected function applyFilters($query): void
    {
        // 状态筛选
        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        // 多状态筛选
        if (!empty($this->filters['status_in']) && is_array($this->filters['status_in'])) {
            $query->whereIn('status', $this->filters['status_in']);
        }

        // 用户ID筛选
        if (!empty($this->filters['user_id'])) {
            $query->where('user_id', $this->filters['user_id']);
        }

        // 商品ID筛选
        if (!empty($this->filters['product_id'])) {
            $query->where('product_id', $this->filters['product_id']);
        }

        // 订单编号模糊搜索
        if (!empty($this->filters['order_no'])) {
            $query->where('order_no', 'like', '%' . $this->filters['order_no'] . '%');
        }

        // 时间范围筛选 - 提交时间
        if (!empty($this->filters['submitted_from'])) {
            $query->where('submitted_at', '>=', $this->filters['submitted_from']);
        }
        if (!empty($this->filters['submitted_to'])) {
            $query->where('submitted_at', '<=', $this->filters['submitted_to']);
        }

        // 时间范围筛选 - 创建时间
        if (!empty($this->filters['created_from'])) {
            $query->where('created_at', '>=', $this->filters['created_from']);
        }
        if (!empty($this->filters['created_to'])) {
            $query->where('created_at', '<=', $this->filters['created_to']);
        }

        // 金额范围筛选
        if (!empty($this->filters['min_amount'])) {
            $query->where('total_amount', '>=', $this->filters['min_amount']);
        }
        if (!empty($this->filters['max_amount'])) {
            $query->where('total_amount', '<=', $this->filters['max_amount']);
        }
    }

    /**
     * 获取列值
     */
    protected function getColumnValue($order, string $column): string
    {
        switch ($column) {
            case 'order_no':
                return $order->order_no;

            case 'status_text':
                return $this->statusMap[$order->status] ?? $order->status;

            case 'user_name':
                return $order->user?->name ?? '';

            case 'user_phone':
                return $order->user?->phone ?? '';

            case 'product_name':
                return $order->product?->name ?? ($order->snapshot['name'] ?? '');

            case 'product_code':
                return $order->product?->code ?? ($order->snapshot['code'] ?? '');

            case 'quantity':
                return (string) $order->quantity;

            case 'size':
                return $order->size ?? '';

            case 'color':
                return $order->color ?? '';

            case 'unit_price':
                return number_format($order->unit_price, 2);

            case 'total_amount':
                return number_format($order->total_amount, 2);

            case 'recipient_name':
                return $order->recipient_name ?? '';

            case 'recipient_phone':
                return $order->recipient_phone ?? '';

            case 'recipient_address':
                return $order->recipient_address ?? '';

            case 'remark':
                return $order->remark ?? '';

            case 'design_url':
                return $this->getDesignUrl($order);

            case 'payment_proof_url':
                return $this->getPaymentProofUrl($order);

            case 'submitted_at':
                return $order->submitted_at?->format('Y-m-d H:i:s') ?? '';

            case 'design_uploaded_at':
                return $order->design_uploaded_at?->format('Y-m-d H:i:s') ?? '';

            case 'reviewed_at':
                return $order->reviewed_at?->format('Y-m-d H:i:s') ?? '';

            case 'reviewer_name':
                return $order->reviewer?->name ?? '';

            case 'review_remark':
                return $order->reject_reason ?? '';

            case 'completed_at':
                return $order->completed_at?->format('Y-m-d H:i:s') ?? '';

            case 'cancel_reason':
                return $order->cancel_reason ?? '';

            default:
                return '';
        }
    }

    /**
     * 获取定制稿链接（带OSS临时签名）
     */
    protected function getDesignUrl($order): string
    {
        try {
            // 从关联的attachments中获取设计稿
            $designAttachment = $order->attachments->firstWhere('type', 'design');
            
            if ($designAttachment && !empty($designAttachment->file_path)) {
                // 生成带签名的临时URL（2小时有效期）
                return $this->ossService->getSignedUrl($designAttachment->file_path, 7200);
            }

            // 兼容旧数据的payment_proof_url字段
            if (!empty($order->payment_proof_url)) {
                return $this->ossService->getSignedUrl($order->payment_proof_url, 7200);
            }

            return '';
        } catch (\Exception $e) {
            Log::warning('获取定制稿链接失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * 获取支付凭证链接（带OSS临时签名）
     */
    protected function getPaymentProofUrl($order): string
    {
        try {
            // 从关联的attachments中获取支付凭证
            $paymentAttachment = $order->attachments->firstWhere('type', 'payment_proof');
            
            if ($paymentAttachment && !empty($paymentAttachment->file_path)) {
                return $this->ossService->getSignedUrl($paymentAttachment->file_path, 7200);
            }

            return '';
        } catch (\Exception $e) {
            Log::warning('获取支付凭证链接失败', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * 获取可用列定义
     */
    public function getAvailableColumns(): array
    {
        return $this->availableColumns;
    }
}
