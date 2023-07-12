<?php

namespace App\Console\Commands;

use App\Enums\DictionaryTypes;
use App\Models\Dictionary;
use App\Services\Ozon\ImportHelper as OzonImportHelper;
use App\Services\Wildberries\Exceptions\TokenRequiredException;
use App\Services\Wildberries\WbClient;
use Illuminate\Console\Command;

class SyncCategories extends Command
{
    use OzonImportHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:categories {--marketplace=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync categories';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $marketplace = $this->option('marketplace');

        switch ($marketplace) {
            case 'wildberries':
                try {
                    $api        = new WbClient(config('exports.wb_token'));
                    $categories = $api->getAllCategories(50000);

                    if ($categories->count()) {
                        foreach ($categories as $category) {
                            if (empty($category['isVisible'])) {
                                continue;
                            }

                            $parentCategory = null;
                            if ( ! empty($category['parentName'])) {
                                $parentCategory = Dictionary::firstOrCreate([
                                    'marketplace' => $marketplace,
                                    'type'        => DictionaryTypes::CATEGORY,
                                    'value'       => $category['parentName']
                                ]);
                            }

                            Dictionary::updateOrCreate([
                                'marketplace' => $marketplace, 'type' => DictionaryTypes::CATEGORY,
                                'value'       => $category['objectName']
                            ], ['parent_id' => $parentCategory?->external_id]);
                        }
                    }

                    Dictionary::where(['marketplace' => $marketplace, 'type' => DictionaryTypes::CATEGORY])->chunk(100,
                        function ($categories) use ($api, $marketplace) {
                            /** @var Dictionary $category */
                            foreach ($categories as $category) {
                                $categoryCharacteristics = $api->getCategoryCharacteristics($category->value);
                                $attributes              = [];
                                foreach ($categoryCharacteristics as $characteristic) {
                                    $attributes[] = prepareWildberriesAttribute($characteristic);
                                }

                                $attributeIds         = [];
                                $requiredAttributeIds = [];

                                foreach ($attributes as $attribute) {
                                    $attributeInDb = Dictionary::updateOrCreate([
                                        'marketplace' => $marketplace,
                                        'type'        => DictionaryTypes::ATTRIBUTE,
                                        'external_id' => $attribute['name'],
                                        'value'       => $attribute['name'],
                                    ], ['settings' => $attribute['settings']]
                                    );

                                    $attributeIds[] = $attributeInDb->id;
                                    if ($attribute['settings']['is_required']) {
                                        $requiredAttributeIds[] = $attributeInDb->id;
                                    }
                                }

                                if ($categoryCharacteristics->count()) {
                                    $category->update(
                                        [
                                            'settings' => [
                                                'attributes'          => $attributeIds,
                                                'required_attributes' => $requiredAttributeIds
                                            ]
                                        ]);
                                }

                                usleep(100000);
                            }

                            sleep(1);
                        });
                } catch (TokenRequiredException $e) {
                    logger()->critical($e);
                }
                break;
            case 'ozon':
                $this->importAllCategories();
                break;
        }
    }
}
