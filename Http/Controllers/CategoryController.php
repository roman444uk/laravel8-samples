<?php

namespace App\Http\Controllers;

use App\DataTables\CategoriesDataTable;
use App\Http\Requests\Categories\CategoryAddRequest;
use App\Http\Requests\Categories\CategoryEditRequest;
use App\Models\Category;
use App\Services\Shop\CategoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(CategoriesDataTable $dataTable)
    {
        return $dataTable->render('pages.categories.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $categories = Category::tree()->depthFirst()->where('user_id', auth()->user()->id)->orderBy('id')->get();

        return view('pages.categories.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(CategoryAddRequest $request)
    {
        try {
            return $request->addCategory();
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return Response
     */
    public function edit(int $id)
    {
        $currentCategory = $this->getModel($id);

        $categories = Category::tree()->depthFirst()->where('user_id', auth()->user()->id)->orderBy('id')->get();

        return view('pages.categories.edit', compact('currentCategory', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param CategoryEditRequest $request
     * @param int $id
     *
     * @return Response
     * @throws AuthorizationException
     */
    public function update(CategoryEditRequest $request, int $id)
    {
        try {
            return $request->editCategory($id);
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return Response
     * @throws AuthorizationException
     */
    public function destroy(int $id)
    {
        try {
            $category = $this->getModel($id);

            $category->delete();

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('categories.index')]);
            }

            return redirect(route('categories.index'));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function massDelete(Request $request)
    {
        $validated = $request->validate([
            'categories'   => 'array|required',
            'categories.*' => 'required|int'
        ], ['categories.required' => trans('categories.categories_required')]);

        $categoriesIds = $validated['categories'];

        /** Удаляем по одной, чтобы можно было повесить observer при необходимости */
        foreach ($categoriesIds as $categoryId) {
            Category::where(['id' => $categoryId, 'user_id' => auth()->user()->id])->delete();
        }

        if (request()->wantsJson()) {
            return response()->json(['success' => true, 'redirect' => route('products.index')]);
        }

        return response()->redirectTo(route('products.index'));
    }

    /**
     * @param int $id
     *
     * @return Application|JsonResponse|RedirectResponse|Redirector
     * @throws AuthorizationException
     */
    public function status(int $id)
    {
        try {
            $category = Category::where('user_id', auth()->user()->id)->findOrFail($id);

            $category->status = match ($category->status) {
                'published' => 'unpublished',
                'unpublished' => 'published',
            };

            $category->save();

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('categories.index')]);
            }

            return redirect(route('categories.index'));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }


    /**
     * @param Request $request
     * @param CategoryService $categoryService
     *
     * @return JsonResponse
     */
    public function getParams(Request $request, CategoryService $categoryService)
    {
        $validated = $request->validate(['category_id' => ['required', 'int']]);

        $category = Category::my()->findOrFail($validated['category_id']);

        $system_category = $category->system_category;

        if (empty($system_category)) {
            return new JsonResponse(
                ['message' => trans('syncs.category_need_sync', ['category' => $category->title])], 422
            );
        }

        $categoryAttributes = $categoryService->getAttributes($category);

        $data = [
            'success'                => true,
            'need_composition'       => ! empty($system_category->settings['need_composition']),
            'attributes'             => $categoryAttributes['attributes'],
            'variationAttributes'    => $categoryAttributes['variationAttributes'],
            'modificationAttributes' => $categoryAttributes['modificationAttributes'],
        ];

        return new JsonResponse($data);
    }

    /**
     * @param int $id
     *
     * @return Category|null
     */
    private function getModel(int $id): ?Category
    {
        return Category::where('user_id', auth()->user()->id)->findOrFail($id);
    }
}
