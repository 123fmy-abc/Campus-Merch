<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadProductImageRequest extends FormRequest
{
    public function authorize() {
        // 仅管理员可操作，具体在控制器中再校验
        return true;
    }
    public function rules() {
        return [
            'image' => 'required|file|mimes:jpg,jpeg,png,gif|max:15360',
            'is_main' => 'sometimes|boolean',
        ];
    }
}
