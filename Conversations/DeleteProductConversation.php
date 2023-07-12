<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use App\Models\Product;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;

class DeleteProductConversation extends Conversation
{
    protected BotController $botController;
    protected ?Product $product;

    public function confirm()
    {
        $additionalParams = ['parse_mode' => 'html'];
        $btn              = [];
        $message          = '<b>Товар:</b> '.$this->product->sku;
        $this->botController->reply($this->bot, $message, $additionalParams, 'title');
        $btn[]                            = ["text" => "Да", "callback_data" => "yes"];
        $btn[]                            = ["text" => "Нет", "callback_data" => "no"];
        $inline_keyboard[]                = $btn;
        $additionalParams['reply_markup'] = json_encode([
            "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
        ]);
        $this->botAsk('Вы, действительно хотите удалить этот товар?', function (BotManAnswer $answer) {
            if ($answer->getText()) {
                if ($answer->isInteractiveMessageReply()) {
                    if ($answer->getText() == 'yes') {
                        $this->product->delete();
                    }
                    $this->botController->listProduct($this->bot);
                } else {
                    if ($this->controllerKeyboard($answer)) {
                        return;
                    }
                    $this->confirm();
                }
            }
        }, $additionalParams);
    }

    public function run()
    {
        $this->confirm();
    }

    public function init(BotController $botController, $product_id)
    {
        $this->botController = $botController;
        $this->product       = Product::where([
            ['user_id', $this->botController->user_id], ['id', $product_id]
        ])->first();
    }

    public function botAsk($question, $next, $additionalParameters = [], $type = 'message')
    {
        $this->botController->reply($this->bot, $question, $additionalParameters);
        $this->bot->storeConversation($this, $next, $question, $additionalParameters);
        $this->botController->setCache($this->bot->getMessage()->getPayload()['message_id'], $type);

        return $this;
    }

    public function controllerKeyboard($answer)
    {
        $this->botController->setCache($answer->getMessage()->getPayload()['message_id'], 'message');
        $trigger = false;
        switch ($answer->getText()) {
            case 'Список товаров':
                $trigger = true;
                $this->botController->listProduct($this->bot);
                break;
            case 'Добавить товар':
                $trigger = true;
                $this->botController->addProduct($this->bot);
                break;
            case 'Главное меню':
                $trigger = true;
                $this->botController->mainMenu($this->bot);
                break;
        }

        return $trigger;
    }
}

