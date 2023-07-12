<?php

namespace App\Http\Requests\Api;

class CategoryDeleteRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'categories'      => 'required|array',
        ];
    }

    public function messages()
    {
        return [
            'categories.required' => trans('api_errors.need_categories'),
        ];
    }
}
