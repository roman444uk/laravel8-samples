<?php

namespace App\Services\Wildberries;

use App\Models\Integration;
use App\Services\Wildberries\Exceptions\TokenRequiredException;
use App\Traits\WarehouseHelper;
use Throwable;

class WbWarehouseService
{
    use WarehouseHelper;

    private const MARKETPLACE = 'wildberries';

    /**
     * @param Integration $integration
     * @param bool $update
     *
     * @return array
     * @throws TokenRequiredException
     */
    public function warehousesFromApi(Integration $integration, bool $update = true): array
    {
        $api        = new WbClient(getIntegrationSetting($integration, 'api_token'));
        $warehouses = $api->getWarehouses();

        if ( ! empty($update)) {
            $this->updateWarehouses($integration->user_id, self::MARKETPLACE, $warehouses);
        }

        return $warehouses;
    }

    /**
     * Обновление складов пользователей
     *
     * @return void
     */
    public function syncWarehouses(): void
    {
        $integrations = Integration::active()->whereType(self::MARKETPLACE)->get();

        foreach ($integrations as $integration) {
            try {
                $this->warehousesFromApi($integration);
            } catch (Throwable $e) {
                logger()->info($e);
                continue;
            }
        }
    }
}
