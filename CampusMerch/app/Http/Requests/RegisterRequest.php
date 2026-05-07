<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:users,email',
            'password'    => 'required|string|min:6|max:20',
            'verify_code' => 'required|string|size:6',
            'phone'       => 'nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => '姓名不能为空',
            'name.max'             => '姓名长度不能超过255个字符',
            'email.required'       => '邮箱不能为空',
            'email.email'          => '邮箱格式不正确',
            'email.unique'         => '该邮箱已被注册',
            'password.required'    => '密码不能为空',
            'password.min'         => '密码长度不能少于6位',
            'password.max'         => '密码长度不能超过20位',
            'verify_code.required' => '验证码不能为空',
            'verify_code.size'     => '验证码必须是6位数字',
            'phone.max'            => '手机号格式不正确',
        ];
    }
}
