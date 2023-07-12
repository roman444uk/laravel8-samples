<?php

namespace App\Console\Commands;

use App\Jobs\Orders\GetOrdersFromMarketplace;
use App\Models\Integration;
use Illuminate\Console\Command;

class SyncUserOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:user-orders {marketplace}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получение и обновление заказов';

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
                    case 'ozon':
                        $client_id = getIntegrationSetting($integration, 'client_id');
                        $api_token = getIntegrationSetting($integration, 'api_token');

                        if (empty($client_id) || empty($api_token)) {
                            break;
                        }

                        $usersData[$integration->user_id] = [
                            'client_id' => $client_id,
                            'api_token' => $api_token,
                        ];
                        break;
                    case 'wildberries':
                        $api_token = getIntegrationSetting($integration, 'api_token');

                        if (empty($api_token)) {
                            break;
                        }

                        $usersData[$integration->user_id] = [
                            'api_token' => $api_token,
                        ];
                        break;
                }
            }

            GetOrdersFromMarketplace::dispatch($usersData, $marketPlace);
        }
    }
}
