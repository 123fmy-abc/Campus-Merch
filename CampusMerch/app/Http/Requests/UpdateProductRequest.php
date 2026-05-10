<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'           => 'nullable|string|max:255',
            'category_id'    => 'nullable|exists:categories,id',
            'price'          => 'nullable|numeric|min:0.01',
            'real_stock'     => 'nullable|integer|min:0',
            'description'    => 'nullable|string|max:2000',
            'specifications' => 'nullable|array',
            'cover_url'      => 'nullable|url|max:500',
            'custom_rule'    => 'nullable|array',
            'need_design'    => 'nullable|boolean',
            'status'         => 'nullable|in:draft,published,archived',
            'max_buy_limit'  => 'nullable|integer|min:1|max:999',
            'sort_order'     => 'nullable|integer|min:0',
        ];
    }
}
