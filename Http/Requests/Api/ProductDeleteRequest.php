<?php

namespace App\Http\Requests\Api;

class ProductDeleteRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'products'      => 'required|array',
        ];
    }

    public function messages()
    {
        return [
            'products.required' => trans('api_errors.need_products'),
        ];
    }
}
