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
            'account' => 'required|string|size:11|regex:/^\d{11}$/|unique:users',
            'name' => 'required|string|max:50',
            'email' => 'required|email|unique:users',
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^[a-zA-Z][a-zA-Z0-9]*$/'],
            'code' => 'required|string|size:6',
            'phone' => 'nullable|string|regex:/^1[3-9]\d{9}$/',
        ];
    }

    public function messages(): array
    {
        return [
            'account.required'     => '学号/工号不能为空',
            'account.size'         => '学号/工号必须是11位',
            'account.regex'        => '学号/工号必须是11位数字',
            'account.unique'       => '该学号/工号已被注册',
            'name.required'        => '姓名不能为空',
            'name.max'             => '姓名长度不能超过50个字符',
            'email.required'       => '邮箱不能为空',
            'email.email'          => '邮箱格式不正确',
            'email.unique'         => '该邮箱已被注册',
            'password.required'    => '密码不能为空',
            'password.min'         => '密码长度不能少于8位',
            'password.regex'       => '密码必须以字母开头，只能包含字母和数字',
            'password.confirmed'   => '两次密码输入不一致',
            'code.required'        => '验证码不能为空',
            'code.size'            => '验证码必须是6位数字',
            'phone.regex'          => '手机号格式不正确，请输入11位中国大陆手机号',
        ];
    }
}
