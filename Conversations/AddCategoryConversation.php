<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use App\Models\Category;
use App\Models\System\Category as SystemCategory;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use Illuminate\Support\Facades\DB;

class AddCategoryConversation extends Conversation
{
    protected BotController $botController;
    protected Category $category;

    public function init(BotController $botController)
    {
        $this->botController              = $botController;
        $this->category                   = new Category();
        $this->category->user_id          = $this->botController->user_id;
        $this->category->title            = null;
        $this->category->description      = null;
        $this->category->parent_id        = null;
        $this->category->image            = null;
        $this->category->status           = 'published';
        $this->category->meta_title       = null;
        $this->category->meta_description = null;
        $this->category->meta_keywords    = null;
    }

    public function addTitle()
    {
        $this->botAsk('Введите название категории '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer)) {
                        return;
                    }
                    $this->category->title = $answer->getText();
                    $this->botController->reply($this->bot, '<b>Название категории:</b> '.$this->category->title, [
                        'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                            ['Выйти без сохранения', 'Пропустить шаг'])
                    ], 'title');
                    $this->addParent();
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
            ]);
    }

    public function addParent($parent_id = 0, $page = 1)
    {
        $this->botAsk('Выберите родительскую категорию '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        if (preg_match('/^listCategory\_/', $answer->getText())) {
                            $parent_id = 0;
                            $page      = 1;
                            $temp      = explode('_page_', $answer->getText());
                            if (isset($temp[0])) {
                                $tmp       = explode('_', str_replace('listCategory_', '', $temp[0]));
                                $parent_id = array_pop($tmp);
                            }
                            if (isset($temp[1])) {
                                $page = intval($temp[1]);
                            }
                            $this->addParent($parent_id, $page);
                        } elseif (preg_match('/^category\_parent\_id\_/', $answer->getText())) {
                            $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
                            $temp                      = explode('_',
                                str_replace('category_parent_id_', '', $answer->getText()));
                            $this->category->parent_id = array_pop($temp);
                            $breadcrumbs               = [];
                            if ($this->category->parent_id) {
                                $titles = DB::table('categories')->whereIn('id',
                                    array($this->category->parent_id))->pluck('title');
                                foreach ($titles as $title) {
                                    $breadcrumbs[] = $title;
                                }
                            }
                            $this->botController->reply($this->bot,
                                '<b>Родительская категория:</b> '.implode(' > ', $breadcrumbs), [
                                    'parse_mode'   => 'html',
                                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                        ['Выйти без сохранения'])
                                ], 'parent');
                            $this->addSystemCategory();
                        }
                    } else {
                        if ($this->controllerKeyboard($answer, 'parent_id')) {
                            return;
                        }
                        $this->addParent();
                    }
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->getListCategory($this->bot, $parent_id, $page,
                    $actionButtons = [
                        [
                            "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x2705)),
                            "callback_data" => 'category_parent_id_'
                        ]
                    ], ['output' => 'json'])
            ]);
    }

    public function addSystemCategory($parent = '0', $page = 1)
    {
        $this->botAsk('Установите связь с системной категорией '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        if (preg_match('/^listSystemCategory\_/', $answer->getText())) {
                            $page = 1;
                            $temp = explode('_page_', $answer->getText());
                            if (isset($temp[0])) {
                                $parent = str_replace('listSystemCategory_', '', $temp[0]);
                            }
                            if (isset($temp[1])) {
                                $page = intval($temp[1]);
                            }
                            $this->addSystemCategory($parent, $page);
                        } elseif (preg_match('/^system\_category\_id\_/', $answer->getText())) {
                            $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
                            $parent                             = explode('_',
                                str_replace('system_category_id_', '', $answer->getText()));
                            $this->category->system_category_id = array_pop($parent);
                            $breadcrumbs                        = [];
                            if ($this->category->system_category_id) {
                                $titles = SystemCategory::whereIn('id',
                                    array_merge($parent, array($this->category->system_category_id))
                                )->pluck('title');
                                foreach ($titles as $title) {
                                    $breadcrumbs[] = $title;
                                }
                            }
                            $this->botController->reply($this->bot,
                                '<b>Системная категория:</b> '.implode(' > ', $breadcrumbs), [
                                    'parse_mode'   => 'html',
                                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                        ['Выйти без сохранения'])
                                ], 'system');
                            $this->addStatus();
                        }
                    } else {
                        if ($this->controllerKeyboard($answer)) {
                            return;
                        }
                        $this->addSystemCategory();
                    }
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->getListSystemCategory($this->bot, $parent, $page,
                    $actionButtons = [
                        [
                            "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x2705)),
                            "callback_data" => 'system_category_id_'
                        ]
                    ], 'json')
            ]);
    }

    public function addStatus()
    {
        $additionalParams                 = ['parse_mode' => 'html'];
        $btn                              = [];
        $btn[]                            = ["text" => "Включена", "callback_data" => "published"];
        $btn[]                            = ["text" => "Выключена", "callback_data" => "unpublished"];
        $inline_keyboard[]                = $btn;
        $additionalParams['reply_markup'] = json_encode([
            "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
        ]);
        $this->botAsk('Выберите статус категории '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer)) {
                        return;
                    }
                    $this->category->status = $answer->getText();
                    $status                 = $this->category->status == 'published' ? 'Включена' : 'Выключена';
                    $this->botController->reply($this->bot, '<b>Статус:</b> '.$status, [
                        'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                            ['Выйти без сохранения', 'Сохранить', 'Пропустить шаг'])
                    ], 'status');
                    $this->addDescription();
                }
            }, $additionalParams);
    }

    public function addDescription()
    {
        $this->botAsk('Введите описание категории '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
                    if ($this->controllerKeyboard($answer, 'description')) {
                        return;
                    }
                    $this->category->description = $answer->getText();
                    $this->botController->reply($this->bot,
                        '<b>Описание категории:</b> '.$this->category->description, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                ['Выйти без сохранения', 'Сохранить'])
                        ], 'description');
                    $this->addImage();
                }
            }, [
                'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                    ['Выйти без сохранения', 'Сохранить', 'Пропустить шаг'])
            ]);
    }

    public function addImage()
    {
        $this->botAskForImages(iconv('UCS-4LE', 'UTF-8', pack('V', 0x2199)).' Загрузите сжатое изображение категории',
            function ($images) {
                $this->botController->setCache($this->bot->getMessage()->getPayload()['message_id']);
                if ( ! empty($images)) {
                    $image                 = array_shift($images);
                    $this->category->image = $image->getUrl();
                    $this->saveCategory();
                }
            },
            function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                $this->addImage();
            },
            [
                'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                ['Выйти без сохранения', 'Сохранить'])
            ]);
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
        $this->addTitle();
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

    public function controllerKeyboard($answer, $step = false)
    {
        $this->botController->setCache($answer->getMessage()->getPayload()['message_id'], 'message');
        $trigger = false;
        switch ($answer->getText()) {
            case 'Выйти без сохранения':
                $trigger = true;
                $this->botController->listCategory($this->bot);
                break;
            case 'Пропустить шаг':
                if ($step == 'parent_id') {
                    $this->botController->reply($this->bot, '<b>Родительская категория:</b> отсутствует', [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
                    ], 'parent');
                    $this->addSystemCategory();
                    $trigger = true;
                } elseif ($step == 'description') {
                    $this->botController->reply($this->bot, '<b>Описание категории:</b> отсутствует', [
                        'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                            ['Выйти без сохранения', 'Сохранить'])
                    ], 'description');
                    $this->addImage();
                    $trigger = true;
                }
                break;
            case 'Сохранить':
                $trigger = true;
                $this->saveCategory();
                break;
        }

        return $trigger;
    }
}

