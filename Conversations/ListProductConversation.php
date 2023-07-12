<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;

class ListProductConversation extends Conversation
{
    protected BotController $botController;

    public function listProduct($page = 1, $keyword = false)
    {
        $additionalParams                 = ['parse_mode' => 'html'];
        $additionalParams['reply_markup'] = $this->botController->getListProduct($this->bot, $keyword, $page, ['title'],
            ['output' => 'json']);
        $additionalParams['system_data']  = array('page' => $page, 'keyword' => $keyword);
        $message                          = '<b>Список товаров</b>';
        if ($keyword) {
            $message .= "\n".'<b>Поиск:</b> '.$keyword;
        }
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            if ($answer->getText()) {
                $conversation         = $this->bot->getStoredConversation();
                $additionalParameters = unserialize($conversation['additionalParameters']);
                $page                 = $additionalParameters['system_data']['page'];
                $keyword              = $additionalParameters['system_data']['keyword'];
                if ($answer->isInteractiveMessageReply()) {
                    $page = 1;
                    $temp = explode('_page_', $answer->getText());
                    if (isset($temp[1])) {
                        $page = $temp[1];
                    }
                    $temp = explode('_', $temp[0]);
                    switch ($temp[0]) {
                        case 'listProduct':
                            $this->listProduct($page, $keyword);
                            break;
                        case 'editProduct':
                            $this->botController->editProduct($this->bot, $temp[1]);
                            break;
                        case 'deleteProduct':
                            $this->botController->deleteProduct($this->bot, $temp[1]);
                            break;
                        case 'searchExit':
                            $this->listProduct(1, false);
                            break;
                    }
                } else {
                    $this->listProduct(1, $answer->getText());
                }
            }
        }, $additionalParams);
    }

    public function listProductPriceQuantity($page = 1, $keyword = false)
    {
        $additionalParams = ['parse_mode' => 'html'];
        //$additionalParams['reply_markup'] = $this->BotmanController->getListProduct($this->bot, $page, ['title', 'price', 'quantity'], ['output'=>'json'], $keyword);

        $inline_keyboard                  = [];
        $btn                              = [];
        $btn[]                            = ["text" => 'Размер', "callback_data" => 'size'];
        $btn[]                            = ["text" => 'Со скидкой', "callback_data" => 'size1'];
        $btn[]                            = ["text" => 'Без скидки', "callback_data" => 'size2'];
        $btn[]                            = ["text" => '% скидки', "callback_data" => 'size3'];
        $inline_keyboard[]                = $btn;
        $btn                              = [];
        $btn[]                            = ["text" => '44', "callback_data" => 'size'];
        $btn[]                            = ["text" => '1000000', "callback_data" => 'size1'];
        $btn[]                            = ["text" => '1000000', "callback_data" => 'size2'];
        $btn[]                            = ["text" => '10', "callback_data" => 'size3'];
        $inline_keyboard[]                = $btn;
        $additionalParams['reply_markup'] = json_encode(["inline_keyboard"         => $inline_keyboard,
                                                         "resize_keyboard"         => true,
                                                         'input_field_placeholder' => 'Введите текст для поиска...'
        ]);

        $message = '<b>Остатки и цены</b>';
        if ($keyword) {
            $message .= "\n".'<b>Поиск:</b> '.$keyword;
        }
        $this->botAsk($message, function (BotManAnswer $answer) use ($keyword) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            if ($answer->getText()) {
                if ($answer->isInteractiveMessageReply()) {
                    $page = 1;
                    $temp = explode('_page_', $answer->getText());
                    if (isset($temp[1])) {
                        $page = $temp[1];
                    }
                    $temp = explode('_', $temp[0]);
                    switch ($temp[0]) {
                        case 'listProduct':
                            $this->listProduct($page, $keyword);
                            break;
                        case 'editProduct':
                            $this->botController->editProduct($this->bot, $temp[1]);
                            break;
                        case 'deleteProduct':
                            $this->botController->deleteProduct($this->bot, $temp[1]);
                            break;
                        case 'searchExit':
                            $this->listProductPriceQuantity(1, false);
                            break;
                    }
                } else {
                    $this->listProductPriceQuantity(1, $answer->getText());
                }
            }
        }, $additionalParams);
    }

    public function init(BotController $botController)
    {
        $this->botController = $botController;
    }

    public function run()
    {
        $this->listProduct();
    }

    public function botAsk($question, $next, $additionalParameters = [], $type = 'message')
    {
        $this->botController->reply($this->bot, $question, $additionalParameters);
        $this->bot->storeConversation($this, $next, $question, $additionalParameters);
        $this->botController->setCache($this->bot->getMessage()->getPayload()['message_id'], $type);

        return $this;
    }

    public function controllerKeyboard($answer, $step = false)
    {
        $conversation         = $this->bot->getStoredConversation();
        $additionalParameters = unserialize($conversation['additionalParameters']);
        $this->botController->setCache($answer->getMessage()->getPayload()['message_id'], 'message');
        $trigger = false;
        switch ($answer->getText()) {
            case 'Список товаров':
                $trigger = true;
                $this->listProduct(1, false);
                break;
            case 'Остатки и цены':
                $trigger = true;
                $this->listProductPriceQuantity(1, false);
                break;
            case 'Ваши товары':
                $trigger = true;
                $this->botController->productMenu($this->bot);
                break;
        }

        return $trigger;
    }
}

