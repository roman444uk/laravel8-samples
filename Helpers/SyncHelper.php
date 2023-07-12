<?php

namespace App\Helpers;

use App\Enums\DictionaryTypes;
use App\Models\AttributeToSystemAttribute;
use App\Models\Category;
use App\Models\Dictionary;
use App\Models\System\Attribute;
use App\Models\System\AttributeValue;
use App\Services\MarketPlaceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SystemCategory;

class SyncHelper
{
    /**
     * Метод отдает все связки пользовательских категорий с категориями маркетплейса (на основе связей MPS)
     *
     * @param string $marketplace
     *
     * @return Collection
     */
    public function getCategoriesToMarketPlace(string $marketplace): Collection
    {
        $key            = SystemCategory::getMarketPlaceIdKey($marketplace);
        $userCategories = Category::where('user_id', auth()->id())->get();

        $categoriesToMarketPlace = collect();

        foreach ($userCategories as $userCategory) {
            if ( ! empty($userCategory->system_category->settings[$key]) && $userCategory->system_category->status === 'published') {
                $categoriesToMarketPlace[$userCategory->id] = ['id' => $userCategory->system_category->settings[$key]];
            }
        }

        return $categoriesToMarketPlace;
    }

    public function getCategorySyncType(string $marketplace): string
    {
        return sprintf('Category\\%s', ucfirst($marketplace));
    }

    /**
     * @param string $marketplace
     * @param int $user_id
     *
     * @return Collection
     */
    public function getAttributesToMarketPlace(string $marketplace, int $user_id): Collection
    {
        $attributesSync = AttributeToSystemAttribute::where('user_id', $user_id)->get();

        $attributeToSystemAttributes = collect();

        foreach ($attributesSync as $item) {
            $marketplace_attributes = $item->system_attribute?->marketplace_attributes
                ->where('marketplace', $marketplace)->all() ?? [];

            if (count($marketplace_attributes)) {
                foreach ($marketplace_attributes as $marketplace_attribute) {
                    $attributeToSystemAttributes[] = [
                        'attribute_id'             => $item->attribute_id,
                        'system_attribute_id'      => $item->system_attribute_id,
                        'marketplace_attribute_id' => $marketplace_attribute->external_id,
                        'name'                     => $marketplace_attribute->title,
                        'system_title'             => $item->system_attribute->title,
                    ];
                }
            }
        }

        return $attributeToSystemAttributes;
    }

    /**
     * @param Category $currentCategory
     *
     * @return array
     */
    public function getUserCategoryAttributes(Category $currentCategory)
    {
        $attributes            = [];
        $requiredAttributes    = [];
        $collectionAttributes  = [];
        $attributesMarketplace = collect();

        foreach (getActiveMarketPlaces() as $marketPlace) {
            $key                   = SystemCategory::getMarketPlaceIdKey($marketPlace['name']);
            $marketPlaceCategoryId = $currentCategory->system_category->settings[$key] ?? null;

            if ( ! $marketPlaceCategoryId) {
                continue;
            }

            /** Получаем связанную категорию текущего маркетплейса */
            $marketplaceCategory = Dictionary::where([
                'type'        => DictionaryTypes::CATEGORY,
                'marketplace' => $marketPlace['name'],
                'id'          => $marketPlaceCategoryId
            ])->first();

            /** Если связь не установлена или категория маркетплейса не найдена */
            if (empty($marketPlaceCategoryId) || ! $marketplaceCategory) {
                continue;
            }

            $marketPlaceService = new MarketPlaceService($marketPlace['name']);
            /** Получаем все характеристики для категории маркетплейса */
            $categoryAttributes = $marketPlaceService->getProvider()->getCategoryAttributes($marketplaceCategory);

            /** Для каждой характеристики получаем сопоставленную системную характеристику */
            /** @var Dictionary $categoryAttribute */
            foreach ($categoryAttributes as $categoryAttribute) {
                $system_attribute = $categoryAttribute->system_attributes
                    ->where('status', 'published')->where('pivot.marketplace', $marketPlace['name'])->first();
                if ($system_attribute) {
                    if ( ! empty($categoryAttribute->settings['required'])) {
                        $requiredAttributes[] = $system_attribute->id;
                    }

                    if ( ! empty($categoryAttribute->settings['is_collection'])) {
                        $collectionAttributes[] = $system_attribute->id;
                    }

                    $attributes[]      = $system_attribute;
                    $attributeToSystem = AttributeToSystemAttribute::where([
                        'user_id'             => auth()->id(),
                        'system_attribute_id' => $system_attribute->id
                    ])->first();

                    if ( ! empty($attributeToSystem->user_attribute)) {
                        $attributesMarketplace[$system_attribute->id] = [
                            'id'   => $attributeToSystem->user_attribute->id,
                            'name' => $attributeToSystem->user_attribute->name,
                        ];
                    }
                }
            }
        }

        $categoryAttributes = [];
        foreach ($attributes as $attribute) {
            if (in_array($attribute->id, $requiredAttributes)) {
                $attribute->settings = array_merge($attribute->settings ?? [], ['required' => true]);
            }

            if (in_array($attribute->id, $collectionAttributes)) {
                $attribute->settings = array_merge($attribute->settings ?? [], ['is_collection' => true]);
            }

            $categoryAttributes[$attribute->id] = $attribute;
        }

        $categoryAttributes = collect($categoryAttributes)->sortByDesc('settings.required')->sortBy('sort')->all();

        return compact('categoryAttributes', 'attributesMarketplace');
    }

    /**
     * @param \App\Models\System\Category $category
     *
     * @return string
     */
    public function getCategorySyncStatus(\App\Models\System\Category $category): string
    {
        $warning = 0;
        foreach (getActiveMarketPlaces() as $marketPlace) {
            $key = SystemCategory::getMarketPlaceIdKey($marketPlace['name']);
            if (empty($category->settings[$key])) {
                $warning++;
            } elseif ( ! Dictionary::where([
                'marketplace' => $marketPlace['name'],
                'type'        => DictionaryTypes::CATEGORY,
                'id'          => (int)$category->settings[$key]
            ])->exists()) {
                $warning++;
            }
        }

        /** Если не задано ни одной связи */
        if (count(getActiveMarketPlaces()) <= $warning) {
            return '<span class="badge badge-light-danger">'.trans('syncs.sync_error').'</span>';
        } elseif ($warning > 0) {
            return '<span class="badge badge-light-warning">'.trans('syncs.sync_warning').'</span>';
        }

        return '<span class="badge badge-light-success">'.trans('syncs.sync_success').'</span>';
    }

    /**
     * @param \App\Models\System\Category $category
     *
     * @return string
     */
    public function getCategoryAttributesSyncStatus(\App\Models\System\Category $category): string
    {
        $notSyncCategory    = 0;
        $notFoundCategory   = 0;
        $notFoundAttributes = 0;
        $warning            = 0;
        $error              = 0;

        foreach (getActiveMarketPlaces() as $marketPlace) {
            $key = SystemCategory::getMarketPlaceIdKey($marketPlace['name']);
            if (empty($category->settings[$key])) {
                $notSyncCategory++;
            } else {
                $marketPlaceCategory = Dictionary::where([
                    'marketplace' => $marketPlace['name'],
                    'type'        => DictionaryTypes::CATEGORY,
                    'id'          => (int)$category->settings[$key]
                ])->first();

                if ($marketPlaceCategory) {
                    $marketPlaceService = new MarketPlaceService($marketPlace['name']);
                    /** Получаем все характеристики для категории маркетплейса */
                    $categoryAttributes = $marketPlaceService->getProvider()->getCategoryAttributes($marketPlaceCategory);
                    if ($categoryAttributes->count()) {
                        /** @var Dictionary $categoryAttribute */
                        foreach ($categoryAttributes as $categoryAttribute) {
                            $required = ! empty($categoryAttribute->settings['required']);
                            if ($required && $categoryAttribute->system_attributes->count() < 1) {
                                $error++;
                            }
                            if ( ! $required && $categoryAttribute->system_attributes->count() < 1) {
                                $warning++;
                            }
                        }
                    } else {
                        $notFoundAttributes++;
                    }
                } else {
                    $notFoundCategory++;
                }
            }
        }

        if ($notFoundCategory || $notSyncCategory) {
            return '';
        }

        /** Если не задано ни одной связи */
        if ($notFoundAttributes == count(getActiveMarketPlaces())) {
            return '<span class="badge badge-danger">'.trans('syncs.sync_attributes_not').'</span>';
        } elseif ($error > 0) {
            return '<span class="badge badge-light-danger">'.trans('syncs.sync_error').'</span>';
        } elseif ($warning > 0) {
            return '<span class="badge badge-light-warning">'.trans('syncs.sync_warning').'</span>';
        }

        return '<span class="badge badge-light-success">'.trans('syncs.sync_success').'</span>';
    }

    /**
     * @param Category $category
     *
     * @return string
     */
    public function getUserCategorySyncStatus(Category $category): string
    {
        /** Если не задано связи с системной категорией */
        if (empty($category->system_category)) {
            return '<span class="badge badge-light-danger">'.trans('syncs.sync_error_one').'</span>';
        }

        return '<span class="badge badge-light-success">'.$category->system_category->getParentNames().'</span>';
    }

    /**
     * @param Category $category
     *
     * @return string
     */
    public function getUserCategoryAttributesSyncStatus(Category $category): string
    {
        if (empty($category->system_category)) {
            return '<span class="badge badge-light-danger">'.trans('syncs.sync_error').'</span>';
        }

        ['categoryAttributes' => $systemAttributes] = \App\Facades\SyncHelper::getUserCategoryAttributes($category);

        $warning = 0;
        $error   = 0;

        /** @var Attribute $systemAttribute */
        foreach ($systemAttributes as $systemAttribute) {
            $attribute = AttributeToSystemAttribute::where([
                'system_attribute_id' => $systemAttribute->id,
                'user_id'             => auth()->id(),
            ])->first();

            $required = ! empty($systemAttribute->settings['required']);
            if ($required && ! $attribute) {
                $error++;
            }
            if ( ! $required && ! $attribute) {
                $warning++;
            }
        }

        /** Если не задано ни одной связи */
        if ( ! $systemAttributes) {
            return '<span class="badge badge-danger">'.trans('syncs.sync_attributes_not').'</span>';
        } elseif ($error > 0) {
            return '<span class="badge badge-light-danger">'.trans('syncs.sync_error').'</span>';
        } elseif ($warning > 0) {
            return '<span class="badge badge-light-warning">'.trans('syncs.sync_warning').'</span>';
        }

        return '<span class="badge badge-light-success">'.trans('syncs.sync_success').'</span>';
    }

    /**
     * @param Category $category
     *
     * @return void
     */
    public function autoSyncCategory(Category $category): void
    {
        $title       = Str::lower($category->title);
        $morphyTitle = Str::lower($category->title);

        if (strwordcount($title) === 1) {
            $morphyTitle = getBaseFormWord($title);
        }

        /** Ищем системную категорию по названию и синонимам */
        $systemCategory = \App\Models\System\Category::leftJoin('synonyms', function ($join) {
            $join->on('system_categories.id', '=', 'synonyms.object_id');
            $join->on('synonyms.type', '=', \DB::raw("'".DictionaryTypes::CATEGORY->value."'"));
        })->where(
            fn($query) => $query
                ->whereRaw('lower(system_categories.title) = ? OR lower(system_categories.title) = ?',
                    [$title, $morphyTitle])
                ->orWhereRaw('lower(synonyms.title) = ? OR lower(synonyms.title) = ?',
                    [$title, $morphyTitle])
        )->where('system_categories.status', 'published')->first(['system_categories.*']);

        /** Если нашли - сохраняем связку */
        if ($systemCategory) {
            $category->system_category_id = $systemCategory->id;
            $category->save();

            logger()->info('автоматическая связь категорий',
                [
                    'user_id'         => $category->user_id,
                    'category'        => $category->getParentNames(),
                    'system_category' => $category->system_category->getParentNames()
                ]);
        }
    }

    /**
     * @param \App\Models\Attribute $attribute
     *
     * @return void
     */
    public function autoSyncAttribute(\App\Models\Attribute $attribute): void
    {
        $title       = Str::lower($attribute->name);
        $morphyTitle = Str::lower($attribute->name);

        if (strwordcount($title) === 1) {
            $morphyTitle = getBaseFormWord($title);
        }

        /** Ищем системную характеристику по названию и синонимам */
        $systemAttribute = Attribute::leftJoin('synonyms', function ($join) {
            $join->on('system_attributes.id', '=', 'synonyms.object_id');
            $join->on('synonyms.type', '=', \DB::raw("'".DictionaryTypes::ATTRIBUTE->value."'"));
        })->where(
            fn($query) => $query
                ->whereRaw('lower(system_attributes.title) = ? OR lower(system_attributes.title) = ?',
                    [$title, $morphyTitle])
                ->orWhereRaw('lower(synonyms.title) = ? OR lower(synonyms.title) = ?',
                    [$title, $morphyTitle])
        )->where('system_attributes.status', 'published')->first(['system_attributes.*']);

        /** Если нашли - сохраняем связку */
        if ($systemAttribute) {
            $attribute->system_attributes()->attach($systemAttribute->id, ['user_id' => $attribute->user_id]);

            logger()->info('автоматическая связь характеристик',
                [
                    'user_id'          => $attribute->user_id,
                    'attribute'        => $attribute->name,
                    'system_attribute' => $systemAttribute->title,
                ]);
        }
    }

    /**
     * @param \App\Models\Attribute $attribute
     * @param string $value
     *
     * @return AttributeValue|null
     */
    public function autoSyncAttributeValue(\App\Models\Attribute $attribute, string $value): ?AttributeValue
    {
        $title       = Str::lower($value);
        $morphyTitle = Str::lower($value);

        if (strwordcount($title) === 1) {
            $morphyTitle = getBaseFormWord($title);
        }

        /** В теории у нас может быть несколько связей (если пользователь так настроил), берем первую */
        $system_attribute = $attribute->system_attributes->first();
        if ($system_attribute) {
            /** Ищем значение характеристики в системных значениях */
            $attributeValue = \App\Facades\SyncHelper::getSystemAttributeValueByTitle($title, $morphyTitle,
                $system_attribute->id);
            if ($attributeValue) {
                logger()->info('автоматическая связь значения характеристики',
                    [
                        'user_id'                => $attribute->user_id,
                        'attribute'              => $attribute->name,
                        'attribute_value'        => $value,
                        'system_attribute_value' => $attributeValue->title,
                    ]);

                return $attributeValue;
            }
        }

        return null;
    }

    /**
     * @param string $title
     * @param string $morphyTitle
     * @param int $attributeId
     *
     * @return AttributeValue|null
     */
    public function getSystemAttributeValueByTitle(
        string $title,
        string $morphyTitle,
        int $attributeId
    ): ?AttributeValue {
        return AttributeValue::leftJoin('synonyms', function ($join) {
            $join->on('system_attribute_values.id', '=', 'synonyms.object_id');
            $join->on('synonyms.type', '=', \DB::raw("'".DictionaryTypes::ATTRIBUTE_VALUE->value."'"));
        })->where('attribute_id', $attributeId)
            ->where(
                fn($query) => $query
                    ->whereRaw('lower(system_attribute_values.title) = ? OR lower(system_attribute_values.title) = ?',
                        [$title, $morphyTitle])
                    ->orWhereRaw('lower(synonyms.title) = ? OR lower(synonyms.title) = ?', [$title, $morphyTitle])
            )->first(['system_attribute_values.*']);
    }
}
