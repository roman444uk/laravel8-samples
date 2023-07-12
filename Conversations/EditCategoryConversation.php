<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use App\Models\Category;
use App\Models\System\Category as SystemCategory;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;

class EditCategoryConversation extends Conversation
{
    protected BotController $botController;
    protected ?Category $category;
    protected $currentKey = false;
    protected $keyExists = [
        'title'              => 'Название',
        'parent_id'          => 'Родительская категория',
        'system_category_id' => 'Системная категория',
        'description'        => 'Описание',
        'image'              => 'Изображение',
        'status'             => 'Статус'
    ];

    public function parentMenu()
    {
        if ( ! empty($this->category)) {
            $inline_keyboard = [];
            foreach ($this->keyExists as $key => $val) {
                $btn = [];
                if ($key == 'status') {
                    $value = $this->category->{$key} == 'published' ? 'включено' : 'выключено';
                } elseif ($key == 'parent_id') {
                    $value = $this->category->{$key} ? Category::where([
                        ['id', $this->category->{$key}], ['status', 'published']
                    ])->value('title') : 'отсутствует';
                } elseif ($key == 'system_category_id') {
                    $value = $this->category->{$key} ? SystemCategory::where([
                        ['id', $this->category->{$key}], ['status', 'published']
                    ])->value('title') : 'отсутствует';
                } elseif ($key == 'image') {
                    $value = $this->category->{$key} ? 'загружено' : 'отсутствует';
                } else {
                    $value = $this->category->{$key} ? $this->category->{$key} : 'отсутствует';
                }
                $btn[]             = ["text" => $val.': '.$value, "callback_data" => $key];
                $inline_keyboard[] = $btn;
            }
            $message = '<b>Категория:</b> '.$this->category->getParentNames();
            $this->botController->reply($this->bot, $message, [
                'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                    ['Выйти без сохранения', 'Сохранить'])
            ], 'submenu');
            $additionalParams                 = ['parse_mode' => 'html'];
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $message                          = 'Выберите пункт для редактирования '.iconv('UCS-4LE', 'UTF-8',
                    pack('V', 0x1F447));
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        $this->editParam($answer->getText());
                    } else {
                        if ($this->controllerKeyboard($answer)) {
                            return;
                        }
                        $this->parentMenu();
                    }
                }
            }, $additionalParams);
        }
    }

    public function editParam($key, $parent_id = 0, $page = 1)
    {
        $this->currentKey = $key;
        $oldValue         = $this->category->{$key};
        $message          = '<b>Редактирование параметра "'.$this->keyExists[$key].'"</b>';
        $keyboardActions  = ['Выйти к списку параметров'];
        if (in_array($this->currentKey, array('description', 'parent_id', 'image'))) {
            $keyboardActions[] = 'Очистить параметр';
        }
        $this->botController->reply($this->bot, $message, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $keyboardActions)
        ], 'subsubmenu');
        $additionalParams = ['parse_mode' => 'html'];
        $message          = '<b>Старое значение:</b> '.$oldValue."\n";
        if (in_array($this->currentKey, array('parent_id', 'system_category_id', 'status'))) {
            if ($this->currentKey == 'parent_id') {
                $additionalParams['reply_markup'] = $this->botController->getListCategory($this->bot, $parent_id,
                    $page, [
                        [
                            "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x2705)),
                            "callback_data" => 'category_parent_id_'
                        ]
                    ], ['output' => 'json']);
                $oldValue                         = $this->category->{$key} ? Category::where([
                    [
                        'id', $this->category->{$key}
                    ], ['status', 'published']
                ])->value('title') : 'отсутствует';
                $message                          = '<b>Старое значение:</b> '.$oldValue."\n";
            } elseif ($this->currentKey == 'system_category_id') {
                $additionalParams['reply_markup'] = $this->botController->getListSystemCategory($this->bot,
                    $parent_id, $page, [
                        [
                            "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x2705)),
                            "callback_data" => 'system_category_id_'
                        ]
                    ], 'json');
                $oldValue                         = $this->category->{$key} ? SystemCategory::where([
                    [
                        'id', $this->category->{$key}
                    ], ['status', 'published']
                ])->value('title') : 'отсутствует';
                $message                          = '<b>Старое значение:</b> '.$oldValue."\n";
            } elseif ($this->currentKey == 'status') {
                $btn                              = [];
                $btn[]                            = ["text" => "Включено", "callback_data" => "published"];
                $btn[]                            = ["text" => "Выключено", "callback_data" => "unpublished"];
                $inline_keyboard[]                = $btn;
                $additionalParams['reply_markup'] = json_encode([
                    "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
                ]);
                $oldValue                         = $this->category->{$key} == 'published' ? 'включено' : 'выключено';
                $message                          = '<b>Старое значение:</b> '.$oldValue."\n";
            }
            $message .= "Выберите ";
        } else {
            $message .= "Введите ";
        }
        $message .= 'новое значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        if ($this->currentKey == 'image') {
            $message = '<b>Старое изображение:</b> '.$oldValue."\n";
            $message .= iconv('UCS-4LE', 'UTF-8', pack('V', 0x2199)).' Загрузите сжатое изображение категории'."\n";
            $this->botAskForImages($message, function ($images) {
                $this->botController->setCache($this->bot->getMessage()->getPayload()['message_id']);
                if ( ! empty($images)) {
                    foreach ($images as $image) {
                        $this->category->{$this->currentKey} = $image->getUrl();
                        break;
                    }
                    $this->parentMenu();
                }
            },
                function (BotManAnswer $answer) {
                    if ($this->controllerKeyboard($answer)) {
                        return;
                    }
                    $this->editParam($this->currentKey);
                }, $additionalParams);
        } else {
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        if (in_array($this->currentKey, array('parent_id', 'system_category_id'))) {
                            if (preg_match('/^listCategory\_/',
                                    $answer->getText()) || preg_match('/^listSystemCategory\_/', $answer->getText())) {
                                $parent_id = 0;
                                $page      = 1;
                                $temp      = explode('_page_', $answer->getText());
                                if (isset($temp[0])) {
                                    $tmp       = explode('_',
                                        str_replace(array('listSystemCategory_', 'listCategory_'), '', $temp[0]));
                                    $parent_id = array_pop($tmp);
                                }
                                if (isset($temp[1])) {
                                    $page = intval($temp[1]);
                                }
                                $this->editParam($this->currentKey, $parent_id, $page);
                            } elseif (preg_match('/^category\_parent\_id\_/',
                                    $answer->getText()) || preg_match('/^system\_category\_id\_/',
                                    $answer->getText())) {
                                $temp                                = explode('_',
                                    str_replace(array('category_parent_id_', 'system_category_id_'), '',
                                        $answer->getText()));
                                $this->category->{$this->currentKey} = array_pop($temp);
                                $this->parentMenu();
                            }
                        } elseif ($this->currentKey == 'status') {
                            $this->category->{$this->currentKey} = $answer->getText();
                            $this->parentMenu();
                        }
                    } else {
                        if ($this->controllerKeyboard($answer)) {
                            return;
                        }
                        if (in_array($this->currentKey, array('description', 'title'))) {
                            $this->category->{$this->currentKey} = $answer->getText();
                            $this->parentMenu();
                        } else {
                            $this->editParam($this->currentKey);
                        }
                    }
                }
            }, $additionalParams);
        }
    }

    public function saveCategory()
    {
        $this->category->meta_title       = $this->category->title;
        $this->category->meta_description = $this->category->description;
        $this->category->meta_keywords    = $this->category->title;
        $this->category->save();
        $this->botController->listCategory($this->bot, $this->category->parent_id ? $this->category->parent_id : 0);

        return;
    }

    public function run()
    {
        $this->parentMenu();
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

    public function botAskForImages($question, $next, $repeat = null, $additionalParameters = [])
    {
        $additionalParameters['__getter']  = 'getImages';
        $additionalParameters['__pattern'] = Image::PATTERN;
        $additionalParameters['__repeat']  = ! is_null($repeat) ? $this->bot->serializeClosure($repeat) : $repeat;

        return $this->botAsk($question, $next, $additionalParameters);
    }

    public function controllerKeyboard($answer)
    {
        $this->botController->setCache($answer->getMessage()->getPayload()['message_id'], 'message');
        $trigger = false;
        switch ($answer->getText()) {
            case 'Выйти к списку параметров':
                $trigger = true;
                $this->parentMenu();
                break;
            case 'Выйти без сохранения':
                $trigger = true;
                $this->botController->listCategory($this->bot,
                    $this->category->parent_id ? $this->category->parent_id : 0);
                break;
            case 'Очистить параметр':
                $trigger                             = true;
                $this->category->{$this->currentKey} = null;
                $this->parentMenu();
                break;
            case 'Сохранить':
                $trigger = true;
                $this->saveCategory();
                break;
        }

        return $trigger;
    }
}

