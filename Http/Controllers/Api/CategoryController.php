<?php

namespace App\Http\Controllers\Api;

use App\DTO\CategoryDTO;
use App\Exceptions\ApiException;
use App\Facades\SyncHelper;
use App\Http\Requests\Api\BaseRequest;
use App\Http\Requests\Api\CategoryDeleteRequest;
use App\Http\Requests\Api\CategoryStoreRequest;
use App\Models\Category;
use App\Rules\UserUuid;
use App\Traits\ImageHelper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends BaseApiController
{
    use ImageHelper;

    public function store(CategoryStoreRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $createdCount = 0;
            $updatedCount = 0;

            $categories = $request->json('categories');
            if ( ! $categories) {
                throw new ApiException(trans('api_errors.need_categories'));
            }

            $additionalInfo = [];
            $imagesToMove   = [];

            foreach ($categories as $key => $category) {
                $category['image'] = $category['image'] ?? '';
                $imageIsUuid       = Str::isUuid($category['image']);

                $rules = [
                    'title'     => 'required|string',
                    'id'        => 'required|string',
                    'parent_id' => 'nullable|string',
                    'image'     => $imageIsUuid ? ['nullable', 'uuid', new UserUuid] : 'nullable|active_url',
                    'status'    => ['required', Rule::in(['published', 'unpublished'])],
                ];

                $validator = Validator::make($category, $rules);

                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $category);
                    continue;
                }

                /** если фото было загружено по api - переносим его с временной папки в основную и удаляем запись */
                if ($imageIsUuid) {
                    $uuid              = $category['image'];
                    $category['image'] = $this->getImageNewPath($uuid, 'categories');

                    if ( ! empty($category['image'])) {
                        $imagesToMove[] = $uuid;
                    }
                }

                $categoryInDb = Category::getUserCategoryByExternalId(auth()->user()->id, $category['id']);

                /** Если существует связка с переданным id у нас в базе */
                if ($categoryInDb) {
                    $parent_id = $categoryInDb->parent_id;
                    if (isset($category['parent_id'])) {
                        $parent = Category::getUserCategoryByExternalId(auth()->user()->id, $category['parent_id']);
                        if ($parent) {
                            $parent_id = $parent->id;
                        }
                    }

                    /** удаляем старую фотографию из хранилища, если она там есть */
                    if ($categoryInDb->image) {
                        Storage::delete($categoryInDb->image);
                    }

                    /** Обновляем категорию */
                    $updated = $categoryInDb->update([
                        'title'     => $category['title'],
                        'image'     => $category['image'] ?? '',
                        'status'    => $category['status'],
                        'parent_id' => $parent_id,
                    ]);
                    if ($updated) {
                        $updatedCount++;
                    }
                } else {
                    /** Иначе создаем новую категорию */
                    $parent_id = null;
                    if (isset($category['parent_id'])) {
                        $parent    = Category::getUserCategoryByExternalId(auth()->user()->id, $category['parent_id']);
                        $parent_id = $parent?->id;
                    }

                    $categoryInDb = Category::create(
                        [
                            'title'       => $category['title'],
                            'user_id'     => auth()->user()->id,
                            'parent_id'   => $parent_id,
                            'image'       => $category['image'] ?? '',
                            'status'      => $category['status'],
                            'external_id' => $category['id'],
                        ]
                    );

                    if ($categoryInDb) {
                        $createdCount++;
                    }
                }

                /** Если у категории не указана связка с системной категорией - пробуем это сделать на лету */
                if (empty($categoryInDb->system_category_id)) {
                    SyncHelper::autoSyncCategory($categoryInDb);
                }
            }

            /** переносим временные фото в основную папку */
            if ($imagesToMove) {
                foreach ($imagesToMove as $item) {
                    $this->moveImageFromTmp($item, 'categories');
                }
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.created_updated_count',
                        [
                            'all'     => count($categories),
                            'created' => $createdCount,
                            'updated' => $updatedCount,
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

    public function destroy(CategoryDeleteRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $categories = $request->json('categories');
            if ( ! $categories) {
                throw new ApiException(trans('api_errors.need_categories'));
            }

            $additionalInfo = [];

            $categoryIds = collect($categories)->map(function ($item, $key) use ($categories, &$additionalInfo) {
                $validator = Validator::make($item, ['id' => 'required|string',]);

                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $item);

                    return false;
                }

                $categoryInDb = Category::getUserCategoryByExternalId(auth()->user()->id, $item['id']);
                if ($categoryInDb) {
                    return $categoryInDb->id;
                }

                return false;
            })->reject(function ($value) {
                return $value === false;
            })->all();

            $deletedCount = Category::destroy($categoryIds);

            return $this->successResponse([
                [
                    'msg'            => trans('imports.deleted_count',
                        [
                            'all'     => count($categories),
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

            $categories = Category::tree()->depthFirst()->where('user_id', auth()->user()->id)->get();

            foreach ($categories as $category) {
                $result[] = new CategoryDTO([
                    'id'        => $category->external_id ?? '',
                    'title'     => $category->title,
                    'parent_id' => $category->parent->external_id ?? null,
                    'image'     => $category->getImageUrlAttribute(true),
                    'status'    => $category->status,
                ]);
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
