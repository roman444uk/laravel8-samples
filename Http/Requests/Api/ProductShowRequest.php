<?php

namespace App\Http\Requests\Api;

class ProductShowRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'product_id'  => 'int|required_without_all:external_id,barcode',
            'external_id' => 'string|required_without_all:product_id,barcode',
            'barcode'     => 'string|required_without_all:product_id,external_id',
        ];
    }
}
