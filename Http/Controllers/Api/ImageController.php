<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ImageRequest;
use App\Traits\ImageHelper;
use Illuminate\Database\QueryException;

class ImageController extends BaseApiController
{
    use ImageHelper;

    public function upload(ImageRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $this->uploadTmpImage($request->get('uuid'), $request->file('image'));

            return $this->successResponse([
                [
                    'msg' => trans('imports.image_upload_success')
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
