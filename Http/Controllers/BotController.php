<?php

namespace App\Http\Controllers;

use App\Conversations\AddCategoryConversation;
use App\Conversations\AddProductConversation;
use App\Conversations\AuthConversation;
use App\Conversations\DeleteCategoryConversation;
use App\Conversations\DeleteProductConversation;
use App\Conversations\EditCategoryConversation;
use App\Conversations\EditProductConversation;
use App\Conversations\ListProductConversation;
use App\Conversations\RegisterConversation;
use App\Models\TelegramUser;
use App\Traits\BotHelper;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Cache\LaravelCache;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Telegram\TelegramDriver;
use Request;
use Throwable;

class BotController extends Controller
{
    use BotHelper;

    public ?int $user_id = null;
    public ?int $user_telegram_id = null;
    public int $itemCategoryPerPage = 10;
    public int $itemProductPerPage = 10;
    public int $itemCountryPerPage = 10;

    public function index(Request $request)
    {
        try {
            DriverManager::loadDriver(TelegramDriver::class);

            $config = [
                'telegram' => [
                    'token' => config('botman.telegram.token'),
                ]
            ];

            $bot = BotManFactory::create($config, new LaravelCache());

            $bot->hears('/start', BotController::class.'@start');
            $bot->hears('Регистрация', BotController::class.'@signup');
            $bot->hears('Авторизация', BotController::class.'@login');

            $bot->hears('Главное меню', BotController::class.'@mainMenu');

            $bot->hears('Ваши категории', BotController::class.'@categoryMenu');
            $bot->hears('Ваши товары', BotController::class.'@productMenu');
            $bot->hears('Выход из профиля', BotController::class.'@logout');

            $bot->hears('Список категорий', BotController::class.'@listCategory');
            $bot->hears('Добавить категорию', BotController::class.'@addCategory');

            $bot->hears('listCategory_{category_data}', BotController::class.'@listCategory');
            $bot->hears('editCategory_{category_data}', BotController::class.'@editCategory');
            $bot->hears('deleteCategory_{category_data}', BotController::class.'@deleteCategory');

            $bot->hears('Список товаров', BotController::class.'@listProduct');
            $bot->hears('Добавить товар', BotController::class.'@addProduct');

            $bot->fallback(BotController::class.'@unknownCommand');

            $bot->listen();
        } catch (Throwable $e) {
            logger()->error($e);
        }

        exit();
    }

    public function start(BotMan $bot)
    {
        if ( ! $this->user_id) {
            $this->checkExistTelegramUser($bot);
        }

        if ($this->user_id) {
            $this->mainMenu($bot);
        } else {
            $this->user_telegram_id = $bot->getMessage()->getSender();
            $this->setCache($bot->getMessage()->getPayload()['message_id'] ?? null);
            $message = "Привет @".$bot->getUser()->getUsername()."\n";
            $message .= "Требуется авторизация в системе"."\n";
            $message .= "Выберите пункт меню ";
            $message .= iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
            $this->reply($bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->setMenuKeyboard($bot, ['Авторизация', 'Регистрация'], ['Вход в систему'])
            ]);
        }
    }

    public function unknownCommand(BotMan $bot)
    {
        $this->checkExistTelegramUser($bot);

        $this->setCache($bot->getMessage()->getPayload()['message_id'] ?? null);
        $cache = $this->getCache();
        $this->clearChat($bot, 'menu');

        if (isset($cache['filter']) && ! empty($cache['filter'])) {
            $filter = array_pop($cache['filter']);
            switch ($filter) {
                case 'searchProduct':
                    $this->listProduct($bot, 1, $bot->getMessage()->getPayload()['text']);
                    break;
            }
        } else {
            $this->start($bot);
        }
    }

    public function login(BotMan $bot)
    {
        $this->user_telegram_id = $bot->getMessage()->getSender();
        $userInfo               = $this->checkExistTelegramUser($bot);
        if ( ! empty($userInfo)) {
            $this->setCache($bot->getMessage()->getPayload()['message_id']);
        } else {
            $conversation = new AuthConversation();
            $conversation->init($this);
            $bot->startConversation($conversation);
            exit();
        }
    }

    public function logout(BotMan $bot)
    {
        TelegramUser::whereTelegramId($bot->getMessage()->getSender())->update(['active' => 0]);
        $this->start($bot);
    }

    public function signup(BotMan $bot)
    {
        if ($this->user_id) {
            $this->mainMenu($bot);
        } else {
            $conversation = new RegisterConversation();
            $conversation->init($this);
            $bot->startConversation($conversation);
            exit();
        }
    }

    public function listCategory(BotMan $bot, $category_data = false)
    {
        $this->checkAuth($bot);
        $breadcrumbs = ['Главное меню', 'Ваши категории', 'Список категорий'];
        $this->getCategoryMenuKeyboard($bot, $breadcrumbs);
        $parent_id = 0;
        $page      = 1;
        if ($category_data) {
            $temp = explode('_page_', $category_data);
            if (isset($temp[0])) {
                $tmp       = explode('_', str_replace('listCategory_', '', $temp[0]));
                $parent_id = array_pop($tmp);
            }
            if (isset($temp[1])) {
                $page = intval($temp[1]);
            }
        }
        $actionButtons = [
            ["text" => iconv('UCS-4LE', 'UTF-8', pack('V', 0x270F)), "callback_data" => 'editCategory_'],
            ["text" => iconv('UCS-4LE', 'UTF-8', pack('V', 0x274C)), "callback_data" => 'deleteCategory_']
        ];
        $this->getListCategory($bot, $parent_id, $page, $actionButtons);
    }

    public function addCategory(BotMan $bot)
    {
        $this->checkAuth($bot);
        $breadcrumbs = ['Главное меню', 'Ваши категории', 'Добавить категорию'];
        $this->getCategoryMenuKeyboard($bot, $breadcrumbs);
        $conversation = new AddCategoryConversation();
        $conversation->init($this);
        $bot->startConversation($conversation);
    }

    public function editCategory(BotMan $bot, $category_data)
    {
        $this->checkAuth($bot);
        $category_data = explode('_', $category_data);
        $breadcrumbs   = ['Главное меню', 'Ваши категории', 'Редактировать категорию'];
        $this->setMenuKeyboard($bot, ['Выйти без сохранения', 'Сохранить'], $breadcrumbs);
        $conversation = new editCategoryConversation();
        $conversation->init($this, $category_data);
        $bot->startConversation($conversation);
    }

    public function deleteCategory(BotMan $bot, $category_data = false)
    {
        $this->checkAuth($bot);
        $breadcrumbs = ['Главное меню', 'Ваши категории', 'Удалить категорию'];
        $this->setMenuKeyboard($bot, ['Список категорий', 'Добавить категорию', 'Главное меню'], $breadcrumbs);
        $conversation = new DeleteCategoryConversation();
        $conversation->init($this, explode('_', $category_data));
        $bot->startConversation($conversation);
    }

    public function listProduct(BotMan $bot, $page = 1, $keyword = false)
    {
        $this->checkAuth($bot);
        $breadcrumbs = ['Главное меню', 'Ваши товары', 'Список товаров'];
        $this->setMenuKeyboard($bot, ['Список товаров', 'Остатки и цены', 'Ваши товары'], $breadcrumbs, 'menu', true);
        $conversation = new ListProductConversation();
        $conversation->init($this);
        $bot->startConversation($conversation);
    }

    public function addProduct(BotMan $bot)
    {
        $this->checkAuth($bot);
        $breadcrumbs = ['Главное меню', 'Ваши товары', 'Добавить товар'];
        $this->getProductMenuKeyboard($bot, $breadcrumbs);
        $conversation = new AddProductConversation();
        $conversation->init($this);
        $bot->startConversation($conversation);
    }

    public function editProduct(BotMan $bot, $product_id)
    {
        $this->checkAuth($bot);
        $breadcrumbs = ['Главное меню', 'Ваши товары', 'Редактировать товар'];
        $this->getProductMenuKeyboard($bot, $breadcrumbs);
        $conversation = new EditProductConversation();
        $conversation->init($this, $product_id);
        $bot->startConversation($conversation);
    }

    public function deleteProduct(BotMan $bot, $product_id = false)
    {
        $this->checkAuth($bot);
        $breadcrumbs = ['Главное меню', 'Ваши товары', 'Удалить товар'];
        $this->setMenuKeyboard($bot, ['Список товаров', 'Добавить товар', 'Главное меню'], $breadcrumbs);
        $conversation = new DeleteProductConversation();
        $conversation->init($this, $product_id);
        $bot->startConversation($conversation);
    }
}