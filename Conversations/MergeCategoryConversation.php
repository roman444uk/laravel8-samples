<?php

namespace App\Conversations;

use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;

class MergeCategoryConversation extends Conversation
{
    public function mergeCategory()
    {
        $question = BotManQuestion::create("Сопоставление категорий");
        $question->addButtons([
            Button::create('Меню категорий')->value('categories')
        ]);
        $this->ask($question, function (BotManAnswer $answer) {
            if ($answer->isInteractiveMessageReply()) {
                switch ($answer->getValue()) {
                    case 'categories':
                        $this->bot->startConversation(new CategorySubMenuConversation());
                        break;
                }
            }
        });
    }

    public function run()
    {
        $this->mergeCategory();
    }
}
