<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use App\Models\Category;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;

class DeleteCategoryConversation extends Conversation
{
    protected BotController $botController;
    protected ?Category $category;

    public function confirm()
    {
        $additionalParams = ['parse_mode' => 'html'];
        $btn              = [];
        $message          = '<b>Категория:</b> '.$this->category->getParentNames();
        $this->botController->reply($this->bot, $message, $additionalParams, 'title');
        $btn[]                            = ["text" => "Да", "callback_data" => "yes"];
        $btn[]                            = ["text" => "Нет", "callback_data" => "no"];
        $inline_keyboard[]                = $btn;
        $additionalParams['reply_markup'] = json_encode([
            "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
        ]);
        $this->botAsk('Вы, действительно хотите удалить категорию?', function (BotManAnswer $answer) {
            if ($answer->getText()) {
                if ($answer->isInteractiveMessageReply()) {
                    $parent_id = $this->category->parent_id ? $this->category->parent_id : 0;
                    if ($answer->getText() == 'yes') {
                        $this->category->delete();
                    }
                    $this->botController->listCategory($this->bot, $parent_id);
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

    public function init(BotController $botController, $category_data)
    {
        $this->botController = $botController;
        $category_id         = array_pop($category_data);
        $this->category      = Category::where([
            ['user_id', $this->botController->user_id], ['id', $category_id]
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
            case 'Список категорий':
                $trigger = true;
                $this->botController->listCategory($this->bot);
                break;
            case 'Добавить категорию':
                $trigger = true;
                $this->botController->addCategory($this->bot);
                break;
            case 'Главное меню':
                $trigger = true;
                $this->botController->mainMenu($this->bot);
                break;
        }

        return $trigger;
    }
}

