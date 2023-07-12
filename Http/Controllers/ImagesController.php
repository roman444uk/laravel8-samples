<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Image;
use App\Traits\ImageHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImagesController extends Controller
{
    use ImageHelper;

    /**
     * @param Request $request
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        $validated = $request->validate([
            'uuid'  => ['required', 'uuid', 'bail'],
            'image' => sprintf('bail|required|image|mimes:jpg,png,jpeg,gif|max:%s|dimensions:min_width=%s,min_height=%s,max_width=%s,max_height=%s',
                config('images.max_file_size'), config('images.min_width'), config('images.min_height'),
                config('images.max_width'), config('images.max_height'))
        ]);

        try {
            $image = $this->uploadTmpImage($validated['uuid'], $validated['image']);

            return response()->json([
                'success' => true,
                'uuid'    => $image->uuid,
                'path'    => $image->image,
            ]);
        } catch (ApiException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => [$e->getMessage()],
            ];

            return response($response, $e->getCode());
        }
    }

    /**
     * Method delete user image by uuid or path
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', 'string'],
        ]);

        $where = ['user_id' => auth()->id()];

        if (Str::isUuid($validated['image'])) {
            $where['uuid'] = $validated['image'];
        } else {
            $where['image'] = $validated['image'];
        }

        /** если это временное фото - удаляем фото и запись из бд */
        $image = Image::where($where)->first();
        if ($image) {
            try {
                Storage::delete($image->image);
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            } finally {
                $image->delete();
            }
        } else {
            /** иначе удаляем только фото */
            try {
                Storage::delete($validated['image']);
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            }
        }

        return response()->json(['success' => true]);
    }
}
