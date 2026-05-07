<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'password' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => '用户名不能为空',
            'name.max'          => '用户名长度不能超过255个字符',
            'password.required' => '密码不能为空',
        ];
    }
}