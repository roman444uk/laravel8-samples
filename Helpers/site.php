<?php

use App\Enums\DictionaryTypes;
use App\Models\Currency;
use App\Models\PriceList;
use App\Models\System\Attribute;
use App\Models\Tax;

if ( ! function_exists('hashids_encode')) {
    function hashids_encode($id)
    {
        return \App\Facades\Hashids::encode($id);
    }
}

if ( ! function_exists('hashids_decode')) {
    function hashids_decode($value)
    {
        $decoded_value = \App\Facades\Hashids::decode($value);

        if (empty($decoded_value)) {
            return null;
        }

        if (count($decoded_value) == 1) {
            return $decoded_value[0];
        }

        return $decoded_value;
    }
}

if ( ! function_exists('getActiveImportTypes')) {
    function getActiveImportTypes()
    {
        return collect(config('imports.types'))->filter(function ($type, $key) {
            return $type['status'] === 1;
        })->pluck('title', 'key');
    }
}

if ( ! function_exists('getAttributeIdByMarketplaceId')) {
    function getAttributeIdByMarketplaceId($attributesMarketplace, string $marketPlaceId)
    {
        return \Illuminate\Support\Arr::exists($attributesMarketplace, $marketPlaceId) ? $attributesMarketplace->get(
            $marketPlaceId
        )['id'] : '';
    }
}

if ( ! function_exists('getAttributeValueByMarketplaceId')) {
    function getAttributeValueByMarketplaceId($attributesMarketplace, string $marketPlaceId)
    {
        return \Illuminate\Support\Arr::exists($attributesMarketplace, $marketPlaceId) ? $attributesMarketplace->get(
            $marketPlaceId
        )['name'] : '';
    }
}

if ( ! function_exists('getRemoteFileSize')) {
    function getRemoteFileSize(string $url): int
    {
        ini_set('default_socket_timeout', 5);
        $headers = [];
        foreach (collect(get_headers($url, true)) as $key => $value) {
            $headers[strtolower($key)] = $value;
        }

        return (int)$headers['content-length'];
    }
}

if ( ! function_exists('getRemoteFileMime')) {
    function getRemoteFileMime(string $url): string
    {
        ini_set('default_socket_timeout', 5);
        $headers = [];
        foreach (collect(get_headers($url, true)) as $key => $value) {
            $headers[strtolower($key)] = $value;
        }

        return $headers['content-type'] ?? '';
    }
}

if ( ! function_exists('generateBarcode')) {
    function generateBarcode(): string
    {
        return 7888 .str_pad(round((time() - rand(1000, 9999)) / 10), 10, '0', STR_PAD_LEFT);
    }
}

if ( ! function_exists('getMarketplacePriceTypes')) {
    function getMarketplacePriceTypes(string $marketPlace): array
    {
        return match ($marketPlace) {
            'default' => ['base', 'purchase', 'presale'],
            default => ['base', 'presale'],
        };
    }
}

if ( ! function_exists('getExportMarketPlaceSetting')) {
    function getExportMarketPlaceSetting(
        \App\Models\Export $export,
        string $marketPlace,
        string $settingKey,
        ?string $default = null
    ): mixed {
        return $export->settings[$marketPlace][$settingKey] ?? $default;
    }
}

if ( ! function_exists('getSettingValue')) {
    function getSettingValue(
        object $object,
        string $settingKey,
        ?string $default = null
    ): mixed {
        $settingKeys = explode('.', $settingKey);

        $value = $object->settings;
        while ($value && $currentKey = current($settingKeys)) {
            $value = $value[$currentKey] ?? null;

            next($settingKeys);
        }

        return $value ?? $default;
    }
}

if ( ! function_exists('getIntegrationSetting')) {
    function getIntegrationSetting(
        \App\Models\Integration $integration,
        string $settingKey,
        ?string $default = null
    ): mixed {
        return $integration->settings[$settingKey] ?? $default;
    }
}

if ( ! function_exists('getIntegrationExportSetting')) {
    function getIntegrationExportSetting(
        \App\Models\Integration $integration,
        string $settingKey,
        ?string $default = null
    ): mixed {
        return $integration->settings['export'][$settingKey] ?? $default;
    }
}

if ( ! function_exists('getIntegrationImportSetting')) {
    function getIntegrationImportSetting(
        \App\Models\Integration $integration,
        string $settingKey,
        ?string $default = null
    ): mixed {
        return $integration->settings['import'][$settingKey] ?? $default;
    }
}

if ( ! function_exists('getUserActivities')) {
    function getUserActivities(): array
    {
        $user       = Auth::user();
        $activities = [];
        if ($user) {
            $subjects = [
                \App\Models\Import::class,
                \App\Models\Export::class,
            ];
            $events   = [
                'success',
                'error',
            ];

            $activities = \Spatie\Activitylog\Models\Activity::causedBy($user)
                ->whereIn('subject_type', $subjects)
                ->whereIn('event', $events)
                ->orderBy('created_at',
                    'DESC')->limit(50)->get()->map(function ($item) {
                    $title = '';

                    switch ($item->subject_type) {
                        case \App\Models\Import::class:
                            $title = trans('menu.imports');
                            break;
                        case \App\Models\Export::class:
                            $title = trans('menu.export');
                            break;
                    }

                    return [
                        'created_at'  => $item->created_at->format('d.m.Y H:i:s'),
                        'type'        => $item->subject_type,
                        'title'       => $title,
                        'description' => $item->description,
                        'event'       => $item->event,
                        'class'       => $item->event === 'error' ? 'text-danger' : 'text-success',
                    ];
                })->toArray();
        }

        return $activities;
    }
}

if ( ! function_exists('getNotificationInfo')) {
    function getNotificationInfo(\Illuminate\Notifications\DatabaseNotification $notification): array
    {
        $title = trans('panel.action_is_required');
        $state = 'warning';
        $icon  = 'icons/duotune/general/gen044.svg';
        switch ($notification->type) {
            case \App\Notifications\Export\UserSetExportMainProduct::class:
            case \App\Notifications\Export\DuplicateSkuError::class:
            case \App\Notifications\Export\DuplicateNotVariationsError::class:
                $icon  = 'icons/duotune/general/gen044.svg';
                $state = 'danger';
                break;
        }

        return [
            'title'       => $notification->data['subject'] ?? $title,
            'description' => $notification->data['message'] ?? '',
            'time'        => $notification->created_at->format('d.m H:i'),
            'icon'        => $icon,
            'state'       => $notification->data['type'] ?? $state
        ];
    }
}

if ( ! function_exists('getImportMarketPlaceSetting')) {
    function getImportMarketPlaceSetting(
        \App\Models\Import $import,
        string $marketPlace,
        string $settingKey,
        ?string $default = null
    ): mixed {
        return $import->settings?->{$marketPlace}?->{$settingKey} ?? $default;
    }
}

if ( ! function_exists('prepareSynonyms')) {
    function prepareSynonyms(): array
    {
        $synonyms = [];
        if ( ! empty(\request()->get('synonyms'))) {
            $synonymsArr = explode(PHP_EOL, \request()->get('synonyms'));
            if ( ! empty($synonymsArr)) {
                $synonyms = array_map(fn($item) => trim($item), $synonymsArr);
            }
        }

        return $synonyms;
    }
}

if ( ! function_exists('prepareArrayValueFromTextInput')) {
    function prepareArrayValueFromTextInput($inputName): array
    {
        $values = [];
        if ( ! empty(\request()->get($inputName))) {
            $valuesArr = explode(PHP_EOL, \request()->get($inputName));
            if ( ! empty($valuesArr)) {
                $values = array_map(fn($item) => trim($item), $valuesArr);
            }
        }

        return $values;
    }
}

if ( ! function_exists('getCategoryAutocompleteFields')) {
    /**
     * @param array $settings
     * @param array $marketPlace
     *
     * @return string
     */
    function getCategoryAutocompleteFields(array $settings, array $marketPlace): string
    {
        $key        = SystemCategory::getMarketPlaceIdKey($marketPlace['name']);
        $id         = $settings[$key] ?? '';
        $dictionary = $id ? \App\Models\Dictionary::where([
            'marketplace' => $marketPlace['name'], 'id' => (int)$id, 'type' => \App\Enums\DictionaryTypes::CATEGORY,
        ])->first() : null;
        $titleValue = $dictionary?->title;

        return <<<html
<div class="fv-row row align-items-center mt-5">
    <div class="col-xl-4">
        <div class="fs-6 mt-2 mb-3">
            {$marketPlace['title']}
        </div>
    </div>
    <div class="col-xl-8" id="{$marketPlace['name']}Value">
        <input type="hidden" name="settings[$key]" 
               value="$id">
        <input type="text" class="form-control mb-2" 
               value="$titleValue">
    </div>
</div>
html;
    }
}

if ( ! function_exists('getSystemAttributeTypes')) {
    function getSystemAttributeTypes(): array
    {
        return collect(\App\Enums\SystemAttributeTypes::cases())->pluck('value')->toArray();
    }
}

if ( ! function_exists('getSystemAttributeValueTypes')) {
    function getSystemAttributeValueTypes(): array
    {
        return collect(\App\Enums\SystemAttributeValueTypes::cases())->pluck('value')->toArray();
    }
}

if ( ! function_exists('getDictionaryTypes')) {
    function getDictionaryTypes(): array
    {
        return collect(\App\Enums\DictionaryTypes::cases())->pluck('value')->toArray();
    }
}

if ( ! function_exists('strwordcount')) {
    function strwordcount(string $string): int
    {
        $eng = array_merge(range('A', 'Z'), range('a', 'z'));

        return str_word_count($string, 0,
            "АаБбВвГгДдЕеЁёЖжЗзИиЙйКкЛлМмНнОоПпРрСсТтУуФфХхЦцЧчШшЩщЪъЫыЬьЭэЮюЯя".implode('', $eng));
    }
}

if ( ! function_exists('getBaseFormWord')) {
    function getBaseFormWord(string $title): string
    {
        $morphyTitle = $title;
        try {
            /** Пытаемся найти базовую форму слова (лемму) */
            $morphy          = new cijic\phpMorphy\Morphy();
            $lemmatizeTitles = $morphy->getBaseForm(Str::upper($title));
            /** Если нашли - меняем title на лемму */
            if ($lemmatizeTitles) {
                $morphyTitle = Str::lower(current($lemmatizeTitles));
            }
        } catch (\Exception $e) {
            logger()->error($e);
        } finally {
            return $morphyTitle;
        }
    }
}

if ( ! function_exists('mergeMixedValues')) {
    function mergeMixedValues(array|object $object1, array|object $object2): array
    {
        $return = [];

        $object1 = is_array($object1) ? $object1 : (array)$object1;
        $object2 = is_array($object2) ? $object2 : (array)$object2;

        foreach ($object1 as $attribute => $value) {
            if (isset($object2[$attribute])) {
                if (is_object($value) || is_array($value)) {
                    $return[$attribute] = mergeMixedValues($value, $object2[$attribute]);
                } else {
                    $return[$attribute] = $object2[$attribute];
                }
            } else {
                $return[$attribute] = $value;
            }
        }

        foreach (array_diff_key($object2, $object1) as $attribute => $value) {
            $return[$attribute] = $value;
        }

        return $return;
    }
}

if ( ! function_exists('getDefaultPriceList')) {
    function getDefaultPriceList(\App\Models\User $user): PriceList
    {
        $priceList = PriceList::active()->where('user_id', $user->id)->orderBy('created_at', 'DESC')->first();

        if ( ! $priceList) {
            $tax      = Tax::orderBy('created_at', 'DESC')->first();
            $currency = Currency::orderBy('created_at', 'DESC')->first();

            $priceList = PriceList::create([
                'title'       => 'Default price list',
                'user_id'     => $user->id,
                'status'      => 'published',
                'tax_id'      => $tax ? $tax->id : 1,
                'currency_id' => $currency ? $currency->id : 1,
            ]);
        }

        return $priceList;
    }
}

if ( ! function_exists('getProductTitleTypes')) {
    function getProductTitleTypes()
    {
        $types = [
            ['value' => 'default', 'title' => trans('products.titles.default')]
        ];
        foreach (getActiveMarketPlaces() as $marketPlace) {
            $types[] = ['value' => $marketPlace['name'], 'title' => $marketPlace['title']];
        }

        return $types;
    }
}

if ( ! function_exists('getProductMarketplaceTitle')) {
    function getProductMarketplaceTitle(\App\Models\Product $product, string $marketPlace)
    {
        $title = $product->title;

        foreach ($product->titles ?? [] as $item) {
            if ($item['type'] === $marketPlace && ! empty($item['value'])) {
                $title = $item['value'];
                break;
            }
        }

        return $title;
    }
}

if ( ! function_exists('array_merge_unique')) {
    function array_merge_unique(array ...$arrays): array
    {
        return array_unique(array_merge(...$arrays), SORT_REGULAR);
    }
}

if ( ! function_exists('getAllColors')) {
    /** Получаем все значения системной характеристики Цвет */
    function getAllColors()
    {
        return \Illuminate\Support\Facades\Cache::remember('all_system_colors', 3600, function () {
            $color          = Attribute::where('title', 'ilike', 'цвет')->first();
            $colorValuesArr = \DB::select('select sav.title, s.title as synonym from system_attribute_values as sav left join synonyms as s on s.object_id = sav.id where (s.type = ? or s.type IS NULL) and sav.attribute_id = ?',
                [DictionaryTypes::ATTRIBUTE_VALUE->value, $color->id]);

            $colorValues = [];
            foreach ($colorValuesArr as $colorValue) {
                $colorValues[] = Str::lower($colorValue->title);
                if ( ! empty($colorValue->synonym)) {
                    $colorValues[] = Str::lower($colorValue->synonym);
                }
            }

            return array_unique($colorValues);
        });
    }
}

if ( ! function_exists('get_file_extension')) {
    function get_file_extension($file): bool|string
    {
        $fileInfo = explode('.', $file);

        return end($fileInfo);
    }
}

if ( ! function_exists('is_video_file')) {
    function is_video_file($file): bool
    {
        $ext = get_file_extension($file);

        if (in_array($ext, ['mp4', 'mov', 'webm', 'avi', 'mkv', 'wmv'])) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists('getLastError')) {
    function getLastError(): string
    {
        return (string)preg_replace('#^\w+\(.*?\): #', '', error_get_last()['message'] ?? '');
    }
}

