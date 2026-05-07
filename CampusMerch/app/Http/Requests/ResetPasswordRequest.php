<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'       => 'required|email|exists:users,email',
            'verify_code' => 'required|string|size:6',
            'password'    => 'required|string|min:6|max:20|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'       => '邮箱不能为空',
            'email.email'          => '邮箱格式不正确',
            'email.exists'         => '该邮箱未注册',
            'verify_code.required' => '验证码不能为空',
            'verify_code.size'     => '验证码必须是6位数字',
            'password.required'    => '密码不能为空',
            'password.min'         => '密码长度不能少于6位',
            'password.max'         => '密码长度不能超过20位',
            'password.confirmed'   => '两次密码输入不一致',
        ];
    }
}
