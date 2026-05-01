<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    public function authorize() { return true; }
    public function rules() {
        return [
            'cancel_reason' => 'nullable|string|max:255',
        ];
    }
}
