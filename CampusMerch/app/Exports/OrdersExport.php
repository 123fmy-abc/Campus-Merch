<?php

namespace App\Exports;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrdersExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting, ShouldQueue
{
    protected $query;

    public function __construct($query)
    {
        $this->query = $query;
    }

    public function query(): Builder
    {
        return $this->query; // 直接传入查询构建器，流式处理
    }

    public function headings(): array
    {
        return [
            '订单号',
            '商品名称',
            '购买用户',
            '数量',
            '单价',
            '总价',
            '收货人',
            '联系电话',
            '收货地址',
            '订单状态',
            '下单时间',
            '定制稿链接',
            '支付凭证链接',
            '审核备注',
        ];
    }

    public function map($order): array
    {
        // 状态文本映射
        $statusMap = [
            'booked' => '已预订',
            'design_pending' => '待上传设计稿',
            'design_reviewing' => '审核中',
            'ready' => '待发货',
            'shipped' => '已发货',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'rejected' => '已驳回',
        ];

        // 获取定制稿链接（如果有）
        $designUrl = $order->attachments()
            ->where('type', 'design')
            ->first()?->file_url ?? '';

        // 获取支付凭证链接
        $paymentUrl = $order->payment_proof_url ?? '';

        return [
            $order->order_no,
            $order->product?->name ?? '-',
            $order->user?->name ?? '-',
            $order->quantity,
            number_format($order->unit_price, 2),
            number_format($order->total_amount, 2),
            $order->recipient_name ?? $order->receiver_name ?? '-',
            $order->recipient_phone ?? $order->receiver_phone ?? '-',
            $order->recipient_address ?? $order->delivery_address ?? '-',
            $statusMap[$order->status] ?? $order->status,
            $order->created_at->format('Y-m-d H:i:s'),
            $designUrl,
            $paymentUrl,
            $order->review_remark ?? '',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_NUMBER,      // 数量
            'E' => NumberFormat::FORMAT_NUMBER_00,   // 单价
            'F' => NumberFormat::FORMAT_NUMBER_00,   // 总价
        ];
    }

    public function startCell(): string
    {
        return 'A1';
    }
}
