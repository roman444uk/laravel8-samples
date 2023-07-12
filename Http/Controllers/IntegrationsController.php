<?php

namespace App\Http\Controllers;

use App\Enums\Import\ImportTaskStatuses;
use App\Events\YmlImportMessage;
use App\Exceptions\ApiException;
use App\Http\Requests\Integrations\IntegrationAddRequest;
use App\Http\Requests\Integrations\IntegrationEditRequest;
use App\Jobs\ImportProductsFromMarketplaces;
use App\Jobs\ProductFromYml;
use App\Jobs\ProductsExportToMarketplaces;
use App\Models\Import\ImportTask;
use App\Models\Integration;
use App\Models\Pages\Page;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariation;
use App\Models\ProductVariationItem;
use App\Models\Tax;
use App\Models\User;
use App\Notifications\IntegrationAdminHelp;
use App\Services\DefaultMarketPlaceProvider;
use App\Services\MarketPlaceService;
use App\Services\Ozon\OzonProvider;
use App\Services\Wildberries\WildberriesProvider;
use App\Services\Yml\YmlParserService;
use App\Traits\IntegrationHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Request;
use Session;

class IntegrationsController extends Controller
{
    use IntegrationHelper;

    /**
     * @var YmlParserService
     */
    private YmlParserService $ymlService;

    /**
     * @param YmlParserService $ymlService
     */
    public function __construct(YmlParserService $ymlService)
    {
        $this->ymlService = $ymlService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function index()
    {
        $contentPage = Page::find(config('imports.prompt_page_integrations'));

        $integrationsConf = collect(config('imports.types'))->all();

        $marketplaces = [];
        $dataSources  = [];

        $priceListDefault = getDefaultPriceList(auth()->user());
        $taxDefault       = Tax::first();

        foreach ($integrationsConf as $integrationConf) {
            $integration = Integration::firstOrCreate(
                ['user_id' => auth()->id(), 'type' => $integrationConf['key']],
                [
                    'name'   => 'Default', 'price_list_id' => $priceListDefault->id,
                    'tax_id' => $taxDefault->id, 'status' => 'unpublished'
                ]);
            /** Глобальный статус интеграции в системе */
            $integration->system_status = $integrationConf['status'];
            if (in_array($integration->type, getAllMarketPlaceNames())) {
                $marketplaces[] = $integration;
            } else {
                $dataSources[] = $integration;
            }
        }

        return view('pages.integrations.index', compact('contentPage', 'marketplaces', 'dataSources'));
    }

    /**
     * Store a newly created resource in storage.
     *
     *
     * @param IntegrationAddRequest $request
     * @param int $id
     *
     * @return Response
     */
    public function store(IntegrationAddRequest $request, int $id)
    {
        try {
            $integration = Integration::where('user_id', auth()->id())->findOrFail($id);

            $this->checkRequest($request, $integration);

            /** заполнение значениям перед проверкой связи */
            $validated = $request->validated();
            $settings  = mergeMixedValues($integration->settings ?? [], $validated['settings'] ?? []);
            $integration->fill(['settings' => $settings]);

            $lkItemsCount = $this->checkConnection($integration);

            /** сохранение настроек */
            $settings['lk_connected']   = true;
            $settings['lk_items_count'] = $lkItemsCount;
            if (in_array($integration->type, ['api', 'yml', '1c'])) {
                $settings['lk_init'] = true;
            }

            $this->applyDefaultSettings($settings);

            $integration->update([
                'status'   => $validated['status'] ?? $integration->status,
                'settings' => $settings,
                'tax_id'   => $validated['tax_id'] ?? $integration->tax_id,
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'  => true,
                    'redirect' => $integration->type === 'yml' ? false : route('integrations.edit', $integration)
                ]);
            }

            return redirect(route('integrations.edit', $integration));
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        } catch (\Throwable $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => [$e->getMessage()],
            ];

            return response($response, 422);
        }
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
        $integration = Integration::where('user_id', auth()->id())->findOrFail($id);
        $marketplace = $this->getMarketPlace($integration->type);

        switch ($integration->type) {
            case 'ozon':
                $contentPageId = config('imports.prompt_page_integrations_ozon');
                break;
            case 'wildberries':
                $contentPageId = config('imports.prompt_page_integrations_wb');
                break;
            default:
                $contentPageId = null;
        }
        $contentPage = Page::find($contentPageId);

        $importStatistics = [];
        if (in_array($integration->type, getAllMarketPlaceNames())) {
            $importStatistics = $this->getIntegrationLogs($integration);
        }

        $importStatistics = collect($importStatistics)->sortByDesc('created_at');
        $price_lists      = PriceList::active()->my()->get();
        $taxes            = Tax::active()->get();

        return view('pages.integrations.edit', compact('integration', 'marketplace',
            'price_lists', 'taxes', 'importStatistics', 'contentPage'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param IntegrationEditRequest $request
     * @param int $id
     *
     * @return Response
     */
    public function update(IntegrationEditRequest $request, int $id)
    {
        try {
            $integration = Integration::where('user_id', auth()->id())->findOrFail($id);
            $validated   = $request->validated();

            /** Сохранение настроек */
            $settings = mergeMixedValues($integration->settings ?? [], $validated['settings'] ?? []);

            $status = ! empty($settings['import']['status']) || ! empty($settings['export']['status'])
                ? 'published' : 'unpublished';

            if ( ! empty($validated['status'])) {
                $status = $validated['status'];
            }

            $integration->update([
                'status'   => $status,
                'settings' => $settings,
                'tax_id'   => $validated['tax_id'] ?? $integration->tax_id,
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'  => true,
                    'redirect' => route('integrations.index')
                ]);
            }

            return redirect(route('integrations.index'));
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * @param IntegrationAddRequest $request
     * @param int $id
     *
     * @return JsonResponse
     */
    public function storeKeys(IntegrationAddRequest $request, int $id)
    {
        $integration = Integration::where('user_id', auth()->id())->findOrFail($id);

        $validated = $request->validated();
        $settings  = mergeMixedValues($integration->settings ?? [], $validated['settings'] ?? []);

        $integration->update(['settings' => $settings]);

        return response()->json(['success' => true]);
    }

    /**
     * @param IntegrationEditRequest $request
     * @param int $id
     *
     * @return Response
     */
    public function storeInit(IntegrationEditRequest $request, int $id)
    {
        $integration = Integration::where('user_id', auth()->id())->findOrFail($id);

        try {
            $validated = $request->validated();
            $settings  = mergeMixedValues($integration->settings ?? [], $validated['settings'] ?? []);

            $settings['lk_init']          = $request->boolean('settings.lk_init');
            $settings['import']['status'] = (int)$settings['lk_init'];
            $validated['settings']        = $settings;
            $validated['status']          = $settings['lk_init'] ? 'published' : 'unpublished';

            $integration->update($validated);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'  => true,
                    'redirect' => $integration->type === 'yml' ? false : route('integrations.edit', $integration)
                ]);
            }

            return redirect(route('integrations.edit', $integration));
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * Send a new email help request notification.
     *
     * @param int $id
     *
     * @return RedirectResponse
     */
    public function sendHelpMail(int $id)
    {
        $integration = Integration::where('user_id', auth()->id())->findOrFail($id);

        $admins = User::whereHas('roles', function ($q) {
            $q->where('name', 'admin');
        })->get();

        Notification::send($admins, new IntegrationAdminHelp($integration, auth()->user()));

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Проверка соединения с маркетплейсом
     *
     * @param Integration $integration
     *
     * @return int
     */
    private function checkConnection(Integration $integration): int
    {
        $marketPlaceService = new MarketPlaceService();
        $marketPlaceService->setProvider(new DefaultMarketPlaceProvider());

        switch ($integration->type) {
            case 'wildberries':
                if ( ! empty(getIntegrationSetting($integration, 'api_token'))) {
                    $marketPlaceService->setProvider(new WildberriesProvider());
                }
                break;
            case 'ozon':
                if ( ! empty(getIntegrationSetting($integration, 'api_token'))
                    && ! empty(getIntegrationSetting($integration, 'client_id'))
                ) {
                    $marketPlaceService->setProvider(new OzonProvider());
                }
                break;
        }

        return $marketPlaceService->getProvider()->checkConnection($integration) ?? 0;
    }


    /**
     * @param FormRequest $request
     * @param Integration $integration
     *
     * @return void
     */
    private function checkRequest(FormRequest $request, Integration $integration)
    {
        switch ($integration->type) {
            case 'yml':
                $request->validate(getMarketPlaceRulesImport($integration->type));

                YmlImportMessage::dispatch(auth()->user(), 'Загрузка файла...', 10);

                if ($request->hasFile('file')) {
                    $file = Storage::disk('public')->putFile(generateUploadPath('yml',
                        $request->file('file')->getClientOriginalName()),
                        $request->file('file'), 'public');
                    ProductFromYml::dispatch($file, $this->ymlService, $integration->user, $integration);
                } else {
                    ProductFromYml::dispatch($request->url, $this->ymlService, $integration->user, $integration);
                }

                break;
            default:
                break;
        }
    }

    /**
     * @param $type
     *
     * @return array
     */
    private function getMarketPlace($type)
    {
        return collect(config('marketplaces.modules'))->first(function ($item) use ($type) {
            if ($item['name'] == $type) {
                return true;
            }
        });
    }

    /**
     * Обновление токена
     *
     * @param int $id
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\JsonResponse|RedirectResponse|\Illuminate\Routing\Redirector
     * @throws AuthorizationException
     */
    public function token_refresh(int $id)
    {
        try {
            $integration = Integration::where('user_id', auth()->user()->id)->findOrFail($id);

            if (in_array($integration->type, ['api', '1c'])) {
                $settings = array_merge($integration->settings ?? [], ['token' => Str::uuid()]);
                $integration->update(['settings' => $settings]);
            }

            Session::flash('success', trans('imports.token_refresh_success'));

            if (request()->wantsJson()) {
                return response()->json([
                    'success'  => true,
                    'redirect' => route('integrations.edit', $integration)
                ]);
            }

            return redirect(route('integrations.edit', $integration));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    /**
     * Получение складов с мп
     *
     * @param int $id
     *
     * @return JsonResponse
     */
    public function getWarehouses(int $id)
    {
        $integration = Integration::where('user_id', auth()->user()->id)->findOrFail($id);

        $marketPlaceService = new MarketPlaceService($integration->type);

        return $marketPlaceService->getProvider()->getWarehouses($integration);
    }

    /**
     * Экспорт товаров по кнопке
     *
     * @param int $id
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|RedirectResponse|Response|\Illuminate\Routing\Redirector
     */
    public function exportRun(int $id)
    {
        try {
            $integration = Integration::where('user_id', auth()->user()->id)->findOrFail($id);

            /** Если выгрузка отключена */
            if ($integration->status !== 'published' || empty(getIntegrationExportSetting($integration, 'status'))) {
                if (Request::wantsJson()) {
                    throw new ApiException(
                        sprintf('%s %s', trans('exports.errors.export_unpublished'), Str::ucfirst($integration->type))
                    );
                }

                return redirect(route('integrations.edit',
                    $integration))->withErrors(trans('exports.errors.export_unpublished'));
            }

            $price_list = $integration->price_list;
            /** Если прайс-лист отключен  */

            if ($price_list->status !== 'published') {
                if (Request::wantsJson()) {
                    throw new ApiException(
                        trans('exports.errors.price_list_unpublished')
                    );
                }

                return redirect(route('integrations.edit',
                    $integration))->withErrors(trans('exports.errors.price_list_unpublished'));
            }

            $products = $price_list->products;
            /** Если нет активных товаров */
            if ( ! $products->count()) {
                if (Request::wantsJson()) {
                    throw new ApiException(
                        trans('exports.errors.products_empty')
                    );
                }

                return redirect(route('integrations.edit',
                    $integration))->withErrors(trans('exports.errors.products_empty'));
            }

            $productIds     = $products->pluck('id');
            $productsPrices = ProductPrice::where(['type' => Product::PRICE_TYPE])
                ->whereIn('object_id', $productIds)->get()->toArray();

            $productsPrices = array_merge(
                $productsPrices,
                ProductPrice::where(['type' => ProductVariation::PRICE_TYPE])->whereIn('object_id',
                    $productIds)->get()->toArray(),
                ProductPrice::where(['type' => ProductVariationItem::PRICE_TYPE])->whereIn('object_id',
                    $productIds)->get()->toArray()
            );

            $productsPrices = collect($productsPrices)->keyBy('object_id');

            $productsToExport = [];
            foreach ($products as $product) {
                $prices = $productsPrices->get($product->id);
                /** Товары без установленных цен или неопубликованные - пропускаем */
                if (empty($prices) || $product->status !== 'published') {
                    continue;
                }

                $productsToExport[] = $product->id;
            }

            if ($productsToExport) {
                ProductsExportToMarketplaces::dispatch($productsToExport, $integration);

                if (Request::wantsJson()) {
                    Session::flash('success', trans('exports.job_send_success'));

                    return;
                }

                return redirect(request()->headers->get('referer'))->with('success',
                    trans('exports.job_send_success'));
            } else {
                if (Request::wantsJson()) {
                    throw new ApiException(trans('exports.errors.products_empty'));
                }

                return redirect(request()->headers->get('referer'))->withErrors(trans('exports.errors.products_empty'));
            }
        } catch (ApiException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * Импорт товаров по кнопке
     *
     * @param int $id
     *
     * @return \Illuminate\Contracts\Foundation\Application|JsonResponse|RedirectResponse|\Illuminate\Routing\Redirector
     * @throws AuthorizationException
     */
    public function importRun(int $id)
    {
        try {
            $integration = Integration::where('user_id', auth()->user()->id)->findOrFail($id);

            if ($integration->status !== 'published' || empty(getIntegrationImportSetting($integration, 'status'))) {
                throw new ApiException(
                    sprintf('%s %s', trans('integrations.validation.import_off'), Str::ucfirst($integration->type))
                );
            }

            $count = ImportTask::where(['user_id' => auth()->id()])
                ->whereIn('status', [ImportTaskStatuses::PROCESSING, ImportTaskStatuses::PENDING])->count();

            if ($count > 0) {
                throw new ApiException(trans('integrations.validation.import_processing'));
            }

            switch ($integration->type) {
                case 'wildberries':
                case 'ozon':
                    ImportProductsFromMarketplaces::dispatch($integration);
                    break;
            }

            Session::flash('success', trans('imports.run_success'));

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('integrations.edit', $integration)]);
            }

            return redirect(route('integrations.edit', $integration));
        } catch (ApiException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        } catch (\Exception $e) {
            return response()->json(['message' => trans('errors.forbidden')], 403);
        }
    }

    /**
     * @param array $settigns
     *
     * @return void
     */
    private function applyDefaultSettings(array &$settigns): void
    {
        $settigns['export']['products_group_active'] = $settigns['export']['products_group_active'] ?? 1;
    }
}
