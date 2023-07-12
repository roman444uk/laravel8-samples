<?php

namespace App\Http\Controllers;

use App\DataTables\ImportDataTable;
use App\Events\YmlImportMessage;
use App\Http\Requests\Imports\ImportAddRequest;
use App\Http\Requests\Imports\ImportEditRequest;
use App\Jobs\ImportProductsFromMarketplaces;
use App\Jobs\ProductFromYml;
use App\Models\Import;
use App\Models\PriceList;
use App\Services\Yml\YmlParserService;
use App\Traits\ProductImportHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    use ProductImportHelper;

    private $ymlService;

    public function __construct(YmlParserService $ymlService)
    {
        $this->middleware(
            'role:admin',
            ['only' => ['index', 'create', 'store', 'edit', 'update', 'status', 'destroy']]
        );
        $this->ymlService = $ymlService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(ImportDataTable $dataTable)
    {
        return $dataTable->render('pages.imports.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $price_lists = PriceList::where('user_id', auth()->user()->id)->active()->get();

        return view('pages.imports.create', compact('price_lists'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(ImportAddRequest $request)
    {
        try {
            $import = new Import();

            $validated     = $request->validated();
            $price_list_id = optional(optional($validated)['settings'])['price_list_id'] ?? [];
            if ( ! $price_list_id) {
                $validated['settings']['price_list_id'] = [];
            }

            $import->fill($validated);

            $import->user_id = auth()->user()->id;

            if ($import->type === 'api') {
                $import->token = Str::uuid();
            }

            $import->save();

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('imports.edit', $import->hashed_id)]);
            }

            return redirect(route('imports.edit', $import->hashed_id));
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
     * @param \App\Models\Import $import
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Import $import)
    {
        return redirect(route('imports.index'));
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
            $import = Import::where('user_id', auth()->user()->id)->findOrFail($id);

            $price_lists = PriceList::where('user_id', auth()->user()->id)->active()->get();

            $importStatistics = null;
            if (in_array($import->type, ['wildberries'])) {
                $importStatistics = $this->getProductImportLogs($import);
            }

            return view('pages.imports.edit', compact('import', 'price_lists', 'importStatistics'));
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
     * @param ImportAddRequest $request
     * @param                  $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(ImportEditRequest $request, $hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $import = Import::where('user_id', auth()->user()->id)->findOrFail($id);
            switch ($import->type) {
                case 'wildberries':
                case 'ozon':
                    $request->validate(getMarketPlaceRulesImport($import->type));
                    break;
                case 'yml':
                    $request->validate(getMarketPlaceRulesImport($import->type));

                    YmlImportMessage::dispatch(auth()->user(), 'Загрузка файла...', 10);

                    if ($request->hasFile('file')) {
                        $file = Storage::disk('public')->putFile(generateUploadPath('yml',
                            $request->file('file')->getClientOriginalName()),
                            $request->file('file'), 'public');

                        ProductFromYml::dispatch($file, $this->ymlService, $import->user, $import);
                    } else {
                        ProductFromYml::dispatch($request->url, $this->ymlService, $import->user, $import);
                    }

                    break;
                default:
                    break;
            }

            $validated     = $request->validated();
            $price_list_id = optional(optional($validated)['settings'])['price_list_id'] ?? [];
            if ( ! $price_list_id) {
                $validated['settings']['price_list_id'] = [];
            }

            $import->update($validated);

            if ($request->wantsJson()) {
                return response()->json([
                    'success'  => true,
                    'redirect' => $import->type === 'yml' ? false : route('imports.edit', $import->hashed_id)
                ]);
            }

            return redirect(route('imports.edit', $import->hashed_id));
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
     * @param $id
     *
     * @return \Illuminate\Http\Response
     * @throws AuthorizationException
     */
    public function destroy($hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $import = Import::where('user_id', auth()->user()->id)->findOrFail($id);

            $import->delete();

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('imports.index')]);
            }

            return redirect(route('imports.index'));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    /**
     * @param $id
     *
     * @return \Illuminate\Http\Response
     * @throws AuthorizationException
     */
    public function status($hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $import = Import::where('user_id', auth()->user()->id)->findOrFail($id);

            $import->status = match ($import->status) {
                'published' => 'unpublished',
                'unpublished' => 'published',
            };

            $import->save();

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('imports.index')]);
            }

            return redirect(route('imports.index'));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    public function token_refresh($hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $import = Import::where('user_id', auth()->user()->id)->findOrFail($id);

            if (in_array($import->type, ['api', '1c'])) {
                $import->token = Str::uuid();
            }

            $import->update(['token' => $import->token]);

            \Session::flash('success', trans('imports.token_refresh_success'));

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('imports.edit', $import->hashed_id)]);
            }

            return redirect(route('imports.edit', $import->hashed_id));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    public function run($hash_id)
    {
        try {
            $id     = hashids_decode($hash_id);
            $import = Import::where('user_id', auth()->user()->id)->findOrFail($id);

            switch ($import->type) {
                case 'wildberries':
                case 'ozon':
                    ImportProductsFromMarketplaces::dispatch($import);
                    break;
            }

            \Session::flash('success', trans('imports.run_success'));

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('imports.edit', $import->hashed_id)]);
            }

            return redirect(route('imports.edit', $import->hashed_id));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }
}
