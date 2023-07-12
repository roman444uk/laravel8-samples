<?php

namespace App\Services;

use App\Contracts\MarketPlace;
use App\Services\Ozon\OzonProvider;
use App\Services\Wildberries\WildberriesProvider;

class MarketPlaceService
{
    protected MarketPlace $provider;

    public function __construct(?string $marketPlace = null)
    {
        if ($marketPlace) {
            match ($marketPlace) {
                'wildberries' => $this->setProvider(new WildberriesProvider()),
                'ozon' => $this->setProvider(new OzonProvider()),
                default => $this->setProvider(new DefaultMarketPlaceProvider()),
            };
        }
    }

    /**
     * @return MarketPlace
     */
    public function getProvider(): MarketPlace
    {
        return $this->provider;
    }

    /**
     * @param MarketPlace $provider
     */
    public function setProvider(MarketPlace $provider): void
    {
        $this->provider = $provider;
    }
}
