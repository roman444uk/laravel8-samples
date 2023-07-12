<?php

namespace App\Http\Controllers;

use App\DataTables\BrandsDataTable;
use App\Models\Brand;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BrandController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(BrandsDataTable $dataTable)
    {
        return $dataTable->render('pages.brands.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('pages.brands.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255',
            ]);

            $brand = new Brand();
            $brand->fill(
                $request->only('title')
            );

            $brand->user_id = auth()->user()->id;
            $brand->save();

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('brands.index')]);
            }

            return redirect(route('brands.index'));

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
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $brand = Brand::where('user_id', auth()->user()->id)->findOrFail($id);

        return view('pages.brands.edit', compact('brand'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255',
            ]);

            $brand = Brand::where('user_id', auth()->user()->id)->findOrFail($id);

            $brand->fill(
                $request->only('title')
            );

            $brand->save();

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('brands.index')]);
            }

            return redirect(route('brands.index'));

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
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $brand = Brand::where('user_id', auth()->user()->id)->findOrFail($id);

            $brand->delete();

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('brands.index')]);
            }

            return redirect(route('brands.index'));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }
}
