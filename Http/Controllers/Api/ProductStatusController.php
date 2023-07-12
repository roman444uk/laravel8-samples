<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\BaseRequest;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductStatusController extends BaseApiController
{
    public function status(BaseRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $updatedCount   = 0;
            $additionalInfo = [];

            $products = $request->json('products');
            if ( ! $products) {
                throw new ApiException(trans('api_errors.need_products'));
            }

            if (count($products) > config('imports.max_products')) {
                throw new ApiException(trans('api_errors.to_many_products',
                    ['max_products' => config('imports.max_products')]));
            }

            foreach ($products as $key => $product) {
                $rules = [
                    'id'     => 'required|string',
                    'status' => ['required', Rule::in(['published', 'unpublished'])],
                ];


                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->user()->id, $product['id']);

                if ($productInDb) {
                    /** update status */
                    $updated = $productInDb->update(['status' => $product['status']]);

                    if ($updated) {
                        $updatedCount++;
                    }
                } else {
                    $additionalInfo[] = customAdditionalInfo(
                        sprintf('%s %s', trans('imports.ordinal_number'), $key),
                        [trans('exports.errors.product_not_found', ['product' => $product['id']])]
                    );
                }
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.updated_count',
                        [
                            'all'     => count($products),
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo,
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }
}
