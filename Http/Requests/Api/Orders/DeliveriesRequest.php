<?php

namespace App\Http\Requests\Api\Orders;

use App\Http\Requests\Api\BaseRequest;
use Arr;
use Illuminate\Validation\Rules\In;

class DeliveriesRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $marketPlaces = Arr::pluck(getActiveMarketPlaces(), 'name');

        return [
            'marketplace'    => ['required', 'string', new In($marketPlaces)],
        ];
    }
}
