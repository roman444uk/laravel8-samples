<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\File;
use App\Traits\FileHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    use FileHelper;

    /**
     * @param Request $request
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function upload(Request $request)
    {
        $fileType = $request->get('file_type');

        switch ($fileType) {
            case 'video':
                $mimes    = config('files.video_mimes');
                $max_size = config('files.max_video_size');
                break;
            case 'image':
                $mimes    = config('images.mimes');
                $max_size = config('images.max_file_size');
                break;
            default:
                $mimes    = config('files.files_mimes');
                $max_size = config('files.max_file_size');
                break;
        }


        $validated = $request->validate([
            'uuid' => ['required', 'uuid', 'bail'],
            'file' => sprintf(
                'bail|required|file|mimes:%s|max:%s', $mimes, $max_size
            ),
        ]);

        try {
            $image = $this->uploadTmpFile($validated['uuid'], $validated['file']);

            return response()->json([
                'success' => true,
                'uuid'    => $image->uuid,
                'path'    => $image->path,
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
            'file' => ['required', 'string'],
        ]);

        $where = ['user_id' => auth()->id()];

        if (Str::isUuid($validated['file'])) {
            $where['uuid'] = $validated['file'];
        } else {
            $where['path'] = $validated['file'];
        }

        /** Если это временный файл - удаляем файл и запись из бд */
        $image = File::where($where)->first();
        if ($image) {
            try {
                Storage::delete($image->path);
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            } finally {
                $image->delete();
            }
        } else {
            /** Иначе удаляем только файл */
            try {
                Storage::delete($validated['file']);
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            }
        }

        return response()->json(['success' => true]);
    }
}
