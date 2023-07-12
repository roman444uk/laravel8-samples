<?php

namespace App\Conversations;

use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;

class CategorySubMenuConversation extends Conversation
{
    public function subMenuCategoryShow()
    {
        $question = BotManQuestion::create("Меню категорий");
        $question->addButtons([
            Button::create('Список категорий')->value('listCategory'),
            Button::create('Добавить категорию')->value('addCategory'),
            Button::create('Сопоставление категорий')->value('mergeCategory'),
            Button::create('Главное меню')->value('mainMenu')
        ]);

        $this->ask($question, function (BotManAnswer $answer) {
            if ($answer->isInteractiveMessageReply()) {
                $this->bot->sendRequest('deleteMessage', [
                    'chat_id'    => $answer->getMessage()->getPayload()['chat']['id'],
                    'message_id' => $answer->getMessage()->getPayload()['message_id']
                ]);
                switch ($answer->getValue()) {
                    case 'listCategory':
                        $this->bot->startConversation(new ListCategoryConversation());
                        break;
                    case 'addCategory':
                        $this->bot->startConversation(new AddCategoryConversation());
                        break;
                    case 'mergeCategory':
                        $this->bot->startConversation(new MergeCategoryConversation());
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
        $this->subMenuCategoryShow();
    }
}