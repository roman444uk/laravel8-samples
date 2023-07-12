<?php

namespace App\Console\Commands;

use App\Models\Tnved\TnvedGroup;
use App\Models\Tnved\TnvedItem;
use App\Models\Tnved\TnvedSection;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Storage;
use ZipArchive;

class TnvedParse extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tnved:parse';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parser TNVED';

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
     * @return void
     */
    public function handle(): void
    {
        if (Storage::disk('local')->exists('tnved/json/json.zip')) {
            $zip = new ZipArchive();
            $status = $zip->open(Storage::disk('local')->path('tnved/json/json.zip'));
            if ($status !== true) {
                throw new \Exception("Архив не удалось открыть");
            }
            else{
                $zip->extractTo(Storage::disk('local')->path('tnved/json/'));
                $zip->close();
            }
        }
        
        dump('Архив распакован, обрабатываем');
        
        /** Обрабатываем файл с разделами */
        if (Storage::disk('local')->exists('tnved/json/00000010.json')) {
            try {
                $file     = Storage::disk('local')->get('tnved/json/00000010.json');
                $sections = json_decode($file);
                foreach ($sections as $i => $section) {
                    $sectionInDb = TnvedSection::firstOrCreate(
                        ['section_id' => str_pad($i + 1, 2, '0', STR_PAD_LEFT)],
                        [
                            'title' => $section->TEXT,
                        ]
                    );

                    $fileGroupId = str_pad($section->ID, 8, '0', STR_PAD_LEFT);
                    if (Storage::disk('local')->exists(sprintf('tnved/json/%s.json', $fileGroupId))) {
                        $fileGroups = Storage::disk('local')->get(sprintf('tnved/json/%s.json', $fileGroupId));
                        $groups     = json_decode($fileGroups);
                        foreach ($groups as $group) {
                            TnvedGroup::firstOrCreate(
                                [
                                    'section_id' => $sectionInDb->section_id,
                                    'group_id'   => $group->CODE,
                                ],
                                [
                                    'title' => $group->TEXT,
                                ]
                            );

                            $this->prepareItem($group->CODE, $group->ID);
                        }
                    }
                }
            } catch (FileNotFoundException $e) {
                logger()->error('не найден файл');
            }
        }
    }

    private function prepareItem(string $groupCode, string $ID, int $parentId = null)
    {
        $fileItemId = str_pad($ID, 8, '0', STR_PAD_LEFT);
        if (Storage::disk('local')->exists(sprintf('tnved/json/%s.json', $fileItemId))) {
            try {
                $fileItems = Storage::disk('local')->get(sprintf('tnved/json/%s.json', $fileItemId));
                $items     = json_decode($fileItems);

                foreach ($items as $item) {
                    $itemInDb = TnvedItem::firstOrCreate(
                        [
                            'group_id'  => $groupCode,
                            'item_id'   => $item->CODE,
                            'parent_id' => $parentId
                        ],
                        [
                            'title'      => $item->TEXT,
                            'full_code'  => $item->CODE,
                            'start_date' => $item->DBEGIN ?? null,
                            'end_date'   => $item->DEND ?? null,
                        ]
                    );

                    $this->prepareItem($groupCode, $item->ID, $itemInDb->id);
                }
            } catch (FileNotFoundException $e) {
            }
        }
    }
}
