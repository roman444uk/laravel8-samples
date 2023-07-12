<?php

namespace App\Http\Controllers;

use App\Http\Requests\Export\StoreRequest;
use App\Http\Requests\Export\UpdateRequest;
use App\Jobs\ProductsExportToMarketplaces;
use App\Models\Export;
use App\Models\ExportInfo;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Tax;
use App\Services\MarketPlaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ExportController extends Controller
{

    function __construct()
    {
        $this->middleware(
            'role:admin',
            ['only' => ['index', 'create', 'store', 'edit', 'update', 'status', 'destroy', 'show']]
        );
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $exports = Export::where('user_id', auth()->user()->id)->orderBy('created_at', 'DESC')->get();

        return view('pages.exports.index', compact('exports'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $taxes       = Tax::active()->get();
        $price_lists = PriceList::active()->where('user_id', auth()->user()->id)->get();

        return view('pages.exports.create', compact('taxes', 'price_lists'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(StoreRequest $request)
    {
        try {
            $export = new Export();

            $export->fill($request->validated());
            $export->user_id = auth()->user()->id;

            $export->save();

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('exports.edit', $export->hashed_id)]);
            }

            return redirect(route('exports.index', $export->hashed_id));
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
     * @param $hash_id
     *
     * @return \Illuminate\Http\Response
     */
    public function show($hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $export = Export::where('user_id', auth()->user()->id)->findOrFail($id);

            $statistics = [];

            return view('pages.exports.show', compact('export', 'statistics'));
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param $hash_id
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $export = Export::where('user_id', auth()->user()->id)->findOrFail($id);

            $taxes       = Tax::active()->get();
            $price_lists = PriceList::active()->where('user_id', auth()->user()->id)->get();

            return view('pages.exports.edit', compact('export', 'taxes', 'price_lists'));
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param $hash_id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateRequest $request, $hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $export = Export::where('user_id', auth()->user()->id)->findOrFail($id);

            $export->update($request->validated());

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('exports.index')]);
            }

            return redirect(route('exports.index'));
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
     * @param $hash_id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($hash_id)
    {
        //
    }

    /**
     * @param Export $export
     * @param string $marketPlace
     *
     * @return JsonResponse
     */
    public function getWarehouses(Export $export, string $marketPlace)
    {
        $marketPlaceService = new MarketPlaceService($marketPlace);

        return $marketPlaceService->getProvider()->getWarehouses($export);
    }

    public function run($hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $export = Export::where('user_id', auth()->user()->id)->findOrFail($id);

            /** Если выгрузка отключена */
            if ($export->status !== 'published') {
                return redirect(route('exports.show',
                    $export->hashed_id))->withErrors(trans('exports.errors.export_unpublished'));
            }

            $price_list = $export->price_list;
            /** Если прайс-лист отключен  */
            if ($price_list->status !== 'published') {
                return redirect(route('exports.show',
                    $export->hashed_id))->withErrors(trans('exports.errors.price_list_unpublished'));
            }

            $products = $price_list->products;
            /** Если нет активных товаров */
            if ( ! $products) {
                return redirect(route('exports.show',
                    $export->hashed_id))->withErrors(trans('exports.errors.products_empty'));
            }

            $productIds     = $products->pluck('id');
            $productsPrices = ProductPrice::where(['type' => Product::PRICE_TYPE])
                ->whereIn('object_id', $productIds)->get();

            $productsToExport = [];
            foreach ($products as $product) {
                $prices = $productsPrices->where('object_id', $product->id);
                /** Товары без установленных цен или неопубликованные - пропускаем */
                if ($prices->count() === 0 || $product->status !== 'published') {
                    continue;
                }

                $productsToExport[] = $product->id;
            }

            if ($productsToExport) {
                foreach (getActiveMarketPlaces() as $marketPlace) {
                    /** Если маркетплейс активен */
                    if ( ! empty($export->settings[$marketPlace['name']]['status'])) {
                        ProductsExportToMarketplaces::dispatch($productsToExport, $marketPlace['name'], $export);
                    }
                }

                return redirect(request()->headers->get('referer'))->with('success',
                    trans('exports.job_send_success'));
            } else {
                return redirect(request()->headers->get('referer'))->withErrors(trans('exports.errors.products_empty'));
            }
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }
}
