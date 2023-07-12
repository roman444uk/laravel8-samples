<?php

namespace App\Http\Controllers\Api;

use App\DTO\BrandDTO;
use App\Exceptions\ApiException;
use App\Http\Requests\Api\BaseRequest;
use App\Http\Requests\Api\BrandDeleteRequest;
use App\Http\Requests\Api\BrandStoreRequest;
use App\Models\Brand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

class BrandController extends BaseApiController
{

    public function store(BrandStoreRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $createdCount = 0;

            $brands = $request->json('brands');
            if (!$brands) {
                throw new ApiException(trans('api_errors.need_brands'));
            }

            $additionalInfo = [];

            foreach ($brands as $key => $brand) {
                $validator = Validator::make($brand, ['title' => 'required|string']);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $brand);
                    continue;
                }

                if (Brand::where(['user_id' => auth()->user()->id, 'title' => $brand['title']])->count() === 0) {
                    $newBrand = Brand::create(
                        [
                            'title'   => $brand['title'],
                            'user_id' => auth()->user()->id,
                        ]
                    );

                    if ($newBrand) {
                        $createdCount++;
                    }
                }
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.created_updated_count',
                        [
                            'all'     => count($brands),
                            'created' => $createdCount,
                            'updated' => 0,
                        ]),
                    'additionalInfo' => $additionalInfo
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    public function destroy(BrandDeleteRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $brands = $request->json('brands');
            if (!$brands) {
                throw new ApiException(trans('api_errors.need_brands'));
            }

            $additionalInfo = [];

            $titles = collect($brands)->map(function ($item, $key) use (&$additionalInfo) {
                $validator = Validator::make($item, ['title' => 'required|string',]);

                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $item);

                    return false;
                }

                return $item['title'];
            })->reject(function ($value) {
                return $value === false;
            })->all();

            $deletedCount = $titles ?
                Brand::where('user_id', auth()->user()->id)->whereIn('title', $titles)->delete()
                : 0;

            return $this->successResponse([
                [
                    'msg'            => trans('imports.deleted_count',
                        [
                            'all'     => count($brands),
                            'deleted' => $deletedCount,
                        ]),
                    'additionalInfo' => $additionalInfo
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    public function all(BaseRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $result = [];

            $brands = Brand::where('user_id', auth()->user()->id)->get();

            foreach ($brands as $brand) {
                $result[] = new BrandDTO(['title' => $brand->title]);
            }

            return $this->successResponse($result);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }
}
