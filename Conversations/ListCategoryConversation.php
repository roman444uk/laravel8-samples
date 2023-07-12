<?php

namespace App\Conversations;

use App\Models\Category;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;

class ListCategoryConversation extends Conversation
{

    public function listCategory()
    {
        $keyboardBtn[] = ["text" => "Ваши категории", "callback_data" => '/categories'];
        $reply_markup = json_encode(["keyboard" => [$keyboardBtn], "resize_keyboard" => true]);
        $categories = Category::tree()->depthFirst()->where('user_id', 30)->orderBy('id')->get()->toArray();
        $buttonsCategories = array();
        foreach ($categories as $category) {
            $buttonsCategories[] = [
                Button::create($category['title'])->value('editCategory_'.$category['id']),
                Button::create('Сопоставить')->value('mergeCategory_'.$category['id'])
            ];
        }
        if ( ! empty($buttonsCategories)) {
            $question = BotManQuestion::create("Список категорий");
            $question->addButtons($buttonsCategories);
        } else {
            $question = BotManQuestion::create("Список категорий пуст");
        }
        $this->ask($question, function (BotManAnswer $answer) {
            if ($answer->isInteractiveMessageReply()) {
                switch ($answer->getValue()) {
                    case 'categories':
                        $this->bot->startConversation(new CategorySubMenuConversation());
                        break;
                }
            }
        }, ['parse_mode' => 'html', 'reply_markup' => $reply_markup]);
    }

    public function run()
    {
        $this->listCategory();
    }
}

