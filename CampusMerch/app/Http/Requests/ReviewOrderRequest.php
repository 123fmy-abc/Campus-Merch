<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewOrderRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'action'        => 'required|in:approve,reject',
            'remark'        => 'nullable|string|max:500',
            'reject_reason' => 'required_if:action,reject|string|max:500',
        ];
    }

    public function messages()
    {
        return [
            'action.required'        => '请指定审核动作(approve/reject)',
            'action.in'              => '审核动作只能是 approve 或 reject',
            'remark.max'             => '备注不能超过500字',
            'reject_reason.required_if' => '驳回时必须填写驳回原因',
            'reject_reason.max'      => '驳回原因不能超过500字',
        ];
    }
}
