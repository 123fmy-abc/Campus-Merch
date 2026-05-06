<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'department' => 'sometimes|string|max:255',
            'default_address' => 'sometimes|string|max:500',
        ];
    }
}
