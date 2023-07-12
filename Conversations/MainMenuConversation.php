<?php

namespace App\Conversations;

use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;

class MainMenuConversation extends Conversation
{
    public function menuShow()
    {
        $question = BotManQuestion::create("Главное меню");
        $question->addButtons([
            Button::create('Ваши категории')->value('categories'),
            Button::create('Ваши товары')->value('products')
        ]);
        $this->ask($question, function (BotManAnswer $answer) {
            if ($answer->isInteractiveMessageReply()) {
                $this->bot->sendRequest('deleteMessage', [
                    'chat_id'    => $answer->getMessage()->getPayload()['chat']['id'],
                    'message_id' => $answer->getMessage()->getPayload()['message_id']
                ]);
                switch ($answer->getValue()) {
                    case 'categories':
                        $this->bot->startConversation(new CategorySubMenuConversation());
                        break;
                    case 'products' :
                        $this->bot->startConversation(new ProductSubMenuConversation());
                        break;
                }
            }
        });
    }

    public function run()
    {
        $this->menuShow();
    }
}