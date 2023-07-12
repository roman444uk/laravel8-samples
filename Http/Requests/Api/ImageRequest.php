<?php

namespace App\Http\Requests\Api;

use App\Rules\UserUuidNotExist;

class ImageRequest extends BaseRequest
{

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'uuid'  => ['required', 'uuid', 'bail', new UserUuidNotExist],
            'image' => sprintf('bail|required|image|mimes:jpg,png,jpeg,gif|max:%s|dimensions:min_width=%s,min_height=%s,max_width=%s,max_height=%s',
                config('images.max_file_size'), config('images.min_width'), config('images.min_height'),
                config('images.max_width'), config('images.max_height'))
        ];
    }

    protected function prepareForValidation()
    {
        $uuid = $this->header('X-File-Id');
        $this->mergeIfMissing(['uuid' => $uuid]);
    }
}
