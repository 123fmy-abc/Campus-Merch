<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules()
    {
        return [
            'name'            => 'sometimes|string|max:255',
            'phone'           => ['sometimes', 'string', 'max:20', 'regex:/^1[3-9]\d{9}$/'],
            'department'      => 'sometimes|string|max:255',
            'default_address' => 'sometimes|string|max:500',
        ];
    }

    public function messages()
    {
        return [
            'phone.regex' => '手机号码格式不正确，请输入有效的中国大陆手机号',
        ];
    }
}
