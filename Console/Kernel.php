<?php

namespace App\Console;

use App\Jobs\Orders\CheckActsStatus;
use App\Jobs\Orders\SyncOrderProducts;
use App\Jobs\Ozon\SyncAttributeValuesByCategory;
use App\Jobs\SyncWarehouses;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        if ($this->app->isLocal()) {
            $schedule->command('telescope:prune')->daily();
        }

        /** Обновление связей товаров в заказах с товарами в мпс */
        $schedule->job(new SyncOrderProducts('ozon'))->everyTenMinutes();
        $schedule->job(new SyncOrderProducts('wildberries'))->everyTenMinutes();

        /** Проверка статуса актов озона */
        $schedule->job(new CheckActsStatus('ozon'))->everyTenMinutes();

        /** Обновление заказов раз в час */
        $schedule->command('sync:orders-statuses ozon')->hourlyAt(45);
        $schedule->command('sync:orders-statuses wildberries')->hourlyAt(45);

        /** Получение поставок */
        $schedule->command('sync:supplies wildberries')->everyThirtyMinutes();

        /** Обновление пользовательских складов */
        $schedule->job(new SyncWarehouses('ozon'))->everyThreeHours();
        $schedule->job(new SyncWarehouses('wildberries'))->everyThreeHours();
        
        /** Получение всех значений нужных справочников с Озона */
        $schedule->job(new SyncAttributeValuesByCategory())->dailyAt('22:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
