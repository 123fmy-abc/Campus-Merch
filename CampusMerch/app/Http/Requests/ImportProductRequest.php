<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // 最大10MB
        ];
    }

    public function messages()
    {
        return [
            'file.required' => '请上传Excel文件',
            'file.file' => '上传文件格式不正确',
            'file.mimes' => '仅支持 .xlsx 或 .xls 格式',
            'file.max' => '文件大小不能超过10MB',
        ];
    }
}
