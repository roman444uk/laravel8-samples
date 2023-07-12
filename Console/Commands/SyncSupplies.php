<?php

namespace App\Console\Commands;

use App\Jobs\Orders\GetSuppliesFromMarketplace;
use App\Models\Integration;
use Illuminate\Console\Command;

class SyncSupplies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:supplies {marketplace}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение и обновление поставок';

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
    public function handle()
    {
        $marketPlace = $this->argument('marketplace');

        if ( ! empty($marketPlace)) {
            /** Выбираем все активные интеграции пользователей для указанного маркетплейса */
            $integrations = Integration::where('type', $marketPlace)->active()->get();
            
            $usersData    = [];
            foreach ($integrations as $integration) {
                /** Если у интеграции (импорта) не включена настройка Получать заказы из МП - пропускаем */
                if (empty($integration->settings['import']['orders']['import_status'])) {
                    continue;
                }

                switch ($marketPlace) {
                    case 'wildberries':
                        $api_token = getIntegrationSetting($integration, 'api_token');

                        if (empty($api_token)) {
                            break;
                        }

                        $usersData[$integration->user_id] = [
                            'api_token' => $api_token,
                        ];
                        break;
                    default:
                        break;
                }
            }

            GetSuppliesFromMarketplace::dispatch($usersData, $marketPlace);
        }
    }
}
