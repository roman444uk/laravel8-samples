<?php

namespace App\Http\Requests\Api;

class PriceStoreRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'products' => 'required_without_all:variations,variation_items|array',
        ];
    }

    public function messages()
    {
        return [
            'products.required_without_all' => trans('api_errors.need_products'),
        ];
    }
}
