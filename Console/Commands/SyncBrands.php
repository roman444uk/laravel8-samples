<?php

namespace App\Console\Commands;

use App\Services\Ozon\ImportHelper;
use Illuminate\Console\Command;

class SyncBrands extends Command
{
    use ImportHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:brands {--marketplace=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Синхронизация брендов';

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
                break;
            case 'ozon':
                $this->importAllBrands();
                break;
        }
    }
}
