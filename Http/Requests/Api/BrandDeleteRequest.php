<?php

namespace App\Http\Requests\Api;

class BrandDeleteRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'brands'      => 'required|array',
        ];
    }

    public function messages()
    {
        return [
            'brands.required' => trans('api_errors.need_brands'),
        ];
    }
}
