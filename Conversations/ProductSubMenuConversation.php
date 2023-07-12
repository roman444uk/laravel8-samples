<?php

namespace App\Conversations;

use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;

class ProductSubMenuConversation extends Conversation
{
    public function subMenuProductShow()
    {
        $question = BotManQuestion::create("Меню товаров");
        $question->addButtons([
            Button::create('Список товаров')->value('listProduct'),
            Button::create('Добавить товар')->value('addProduct'),
            Button::create('Главное меню')->value('mainMenu')
        ]);

        $this->ask($question, function (BotManAnswer $answer) {
            if ($answer->isInteractiveMessageReply()) {
                $this->bot->sendRequest('deleteMessage', [
                    'chat_id'    => $answer->getMessage()->getPayload()['chat']['id'],
                    'message_id' => $answer->getMessage()->getPayload()['message_id']
                ]);
                switch ($answer->getValue()) {
                    case 'listProduct':
                        $this->bot->startConversation(new ListProductConversation());
                        break;
                    case 'addProduct':
                        $this->bot->startConversation(new AddProductConversation());
                        break;
                    case 'mainMenu':
                        $this->bot->startConversation(new MainMenuConversation());
                        break;
                }
            }
        });
    }

    public function run()
    {
        $this->subMenuProductShow();
    }
}