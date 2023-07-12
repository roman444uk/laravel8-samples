<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use App\Models\Category;
use App\Models\Country;
use App\Models\Product;
use App\Models\System\Attribute;
use App\Models\System\AttributeValue;
use App\Services\Shop\AttributeService;
use App\Services\Shop\CategoryService;
use App\Services\Shop\ProductService;
use App\Services\Shop\VariationService;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;
use Illuminate\Support\Str;

class AddProductConversation extends Conversation
{
    protected BotController $botController;
    protected ?Product $product;
    protected $data = [];
    protected $messages = [];
    protected $categoryAttributes = [];
    protected $categoryService;
    protected $variationService;
    protected $productService;
    protected $attributeService;
    protected $need_composition;
    protected $compositions = [];

    public function __construct()
    {
        $this->attributeService = new AttributeService();
        $this->categoryService  = new CategoryService();
        $this->variationService = new VariationService($this->attributeService);
        $this->productService   = new ProductService($this->categoryService, $this->variationService,
            $this->attributeService);
    }


    public function init(BotController $botController)
    {
        $this->botController             = $botController;
        $this->product                   = new Product();
        $this->product->user_id          = $this->botController->user_id;
        $this->product->title            = null;
        $this->product->image            = null;
        $this->product->description      = '';
        $this->product->category_id      = null;
        $this->product->country_id       = null;
        $this->product->weight           = null;
        $this->product->length           = null;
        $this->product->width            = null;
        $this->product->height           = null;
        $this->product->brand_id         = null;
        $this->product->sku              = null;
        $this->product->barcode          = null;
        $this->product->status           = 'published';
        $this->product->meta_title       = null;
        $this->product->meta_description = null;
        $this->product->meta_keywords    = null;
    }

    public function addTitle()
    {
        $this->botAsk('Введите название товара '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer, 'addDescription')) {
                        return;
                    }
                    $this->product->title = $answer->getText();
                    $this->botController->reply($this->bot, '<b>Название товара:</b> '.$this->product->title, [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
                    ], 'title');
                    $this->addDescription();
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
            ]);
    }

    public function addDescription()
    {
        $this->botAsk('Введите описание товара '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer, 'addCategory')) {
                        return;
                    }
                    $this->product->description = $answer->getText();
                    $this->botController->reply($this->bot, '<b>Описание товара:</b> '.$this->product->description, [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
                    ], 'description');
                    $this->addCategory();
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
            ]);
    }

    public function addCategory($parent_id = 0, $page = 1)
    {
        $this->botAsk('Выберите категорию товара '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer, 'addCountry')) {
                        return;
                    }
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
                        $this->addCategory($parent_id, $page);
                    } elseif (preg_match('/^product\_category\_id\_/', $answer->getText())) {
                        $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
                        $temp                       = explode('_',
                            str_replace('product_category_id_', '', $answer->getText()));
                        $this->product->category_id = array_pop($temp);
                        $breadcrumbs                = [];
                        if ($this->product->category_id) {
                            $titles = Category::whereIn('id', array($this->product->category_id))->pluck('title');
                            foreach ($titles as $title) {
                                $breadcrumbs[] = $title;
                            }
                            $category = Category::whereIn('id', array($this->product->category_id))->first();
                            if ($category->system_category) {
                                $this->need_composition = $category->system_category->settings['need_composition'];
                            }
                        }
                        $this->botController->reply($this->bot,
                            '<b>Категория товара:</b> '.implode(' > ', $breadcrumbs), [
                                'parse_mode'   => 'html',
                                'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                    ['Выйти без сохранения'])
                            ], 'category_id');
                        $this->addCountry();
                    } else {
                        $this->addCategory();
                    }
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->getListCategory($this->bot, $parent_id, $page,
                    [
                        [
                            "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x2705)),
                            "callback_data" => 'product_category_id_'
                        ]
                    ], ['output' => 'json', 'hasSystemCategory' => true])
            ]);
    }

    public function addCountry($page = 1, $keyword = false)
    {
        $actionsProductKeyboard = ['Выйти без сохранения'];
        if (isset($this->product->country_id) && ! empty($this->product->country_id)) {
        } else {
            $this->botController->reply($this->bot, '<b>Страна производства товара:</b> _____________', [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard, [],
                    'menu', true)
            ], 'country_id');
        }
        $additionalParams                = [
            'parse_mode' => 'html', 'reply_markup' => $this->botController->getListCountry($this->bot, $page, 'json',
                $keyword)
        ];
        $additionalParams['system_data'] = array('page' => $page, 'keyword' => $keyword);
        $message                         = 'Выберите страну производства '.iconv('UCS-4LE', 'UTF-8',
                pack('V', 0x1F447));
        if ($keyword) {
            $message .= "\n".'Поиск: '.$keyword;
        }
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($answer->getText()) {
                if ($this->controllerKeyboard($answer, 'addWeight')) {
                    return;
                }
                $conversation         = $this->bot->getStoredConversation();
                $additionalParameters = unserialize($conversation['additionalParameters']);
                $page                 = $additionalParameters['system_data']['page'];
                $keyword              = $additionalParameters['system_data']['keyword'];
                if ($answer->isInteractiveMessageReply()) {
                    if (preg_match('/^listCountry\_/', $answer->getText())) {
                        $page = 1;
                        $temp = explode('_page_', $answer->getText());
                        if (isset($temp[1])) {
                            $page = intval($temp[1]);
                        }
                        $this->addCountry($page, $keyword);
                    } elseif (preg_match('/^selectCountry\_/', $answer->getText())) {
                        $temp                      = explode('_', $answer->getText());
                        $this->product->country_id = array_pop($temp);
                        $breadcrumbs               = [];
                        if ($this->product->country_id) {
                            $titles = Country::whereIn('id', array($this->product->country_id))->pluck('title_ru');
                            foreach ($titles as $title) {
                                $breadcrumbs[] = $title;
                            }
                        }
                        $this->botController->reply($this->bot,
                            '<b>Страна производства товара:</b> '.implode(' > ', $breadcrumbs), [
                                'parse_mode'   => 'html',
                                'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                    ['Выйти без сохранения'])
                            ], 'country_id');
                        $this->addWeight();
                    } elseif ($answer->getText() == 'searchExit') {
                        $this->addCountry();
                    } else {
                        $this->addCountry($page, $keyword);
                    }
                } else {
                    $this->addCountry(1, $answer->getText());
                }
            }
        }, $additionalParams);
    }

    public function addWeight()
    {
        $this->botAsk('Введите вес, г '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)), function (BotManAnswer $answer) {
            if ($answer->getText()) {
                if ($this->controllerKeyboard($answer, 'addLength')) {
                    return;
                }
                $this->product->weight = $answer->getText();
                $this->botController->reply($this->bot, '<b>Вес товара:</b> '.$this->product->weight.' г.', [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
                ], 'weight');
                $this->addLength();
            }
        }, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
        ]);
    }

    public function addLength()
    {
        $this->botAsk('Введите длину, мм '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer, 'addWidth')) {
                        return;
                    }
                    $this->product->length = $answer->getText();
                    $this->botController->reply($this->bot, '<b>Длина товара:</b> '.$this->product->length.' мм', [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
                    ], 'length');
                    $this->addWidth();
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
            ]);
    }

    public function addWidth()
    {
        $this->botAsk('Введите ширину, мм '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer, 'addHeight')) {
                        return;
                    }
                    $this->product->width = $answer->getText();
                    $this->botController->reply($this->bot, '<b>Ширина товара:</b> '.$this->product->width.' мм', [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
                    ], 'width');
                    $this->addHeight();
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
            ]);
    }

    public function addHeight()
    {
        $this->botAsk('Введите высоту, мм '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    if ($this->controllerKeyboard($answer, 'addParams')) {
                        return;
                    }
                    $this->product->height = $answer->getText();
                    $this->botController->reply($this->bot, '<b>Высота товара:</b> '.$this->product->height.' мм', [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
                    ], 'height');
                    if ($this->need_composition) {
                        $this->addComposition();
                    } else {
                        $this->addParams();
                    }
                }
            }, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'])
            ]);
    }

    public function addComposition($page = 1, $keyword = false)
    {
        $compositionDictionary = Attribute::where([
            'settings->system_name' => 'composition'
        ])->firstOrFail();
        
        if (empty($this->compositions)) {
            $message = '<b>Состав товара:</b> _____________';
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'], [],
                    'menu', true)
            ], 'composition');
        }
        
        $message = 'Выберите компонент состава '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        if ($keyword) {
            $message .= "\n".'Поиск: '.$keyword;
        }
        
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($answer->getText()) {
                $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
                if ($answer->isInteractiveMessageReply()) {
                    $conversation         = $this->bot->getStoredConversation();
                    $additionalParameters = unserialize($conversation['additionalParameters']);
                    $compositionAttrId    = $additionalParameters['system_data']['compositionAttrId'];
                    $page                 = $additionalParameters['system_data']['page'];
                    $keyword              = $additionalParameters['system_data']['keyword'];
                    if ($this->controllerKeyboard($answer, 'addWeight')) {
                        return;
                    }
                    if (preg_match('/^listAttributeValue\_/', $answer->getText())) {
                        $page = 1;
                        $temp = explode('_page_', $answer->getText());
                        if (isset($temp[1])) {
                            $page = intval($temp[1]);
                        }
                        $this->addComposition($page, $keyword);
                    } elseif (preg_match('/^selectAttributeValue\_/', $answer->getText())) {
                        $temp                                                 = explode('_', $answer->getText());
                        $attribute_value_id                                   = array_pop($temp);
                        $this->compositions[count($this->compositions)]['id'] = $attribute_value_id;
                        $breadcrumbs                                          = [];
                        if (count($this->compositions)) {
                            $titles = AttributeValue::where('id', $attribute_value_id)->pluck('title');
                            foreach ($titles as $title) {
                                $breadcrumbs[] = $title;
                            }
                        }
                        $this->addCompositionPercent($compositionAttrId, $attribute_value_id);
                    } elseif ($answer->getText() == 'searchExit') {
                        $this->addComposition(1, false);
                    } else {
                        $this->addComposition();
                    }
                } else {
                    $this->addComposition(1, $answer->getText());
                }
            }
        }, [
            'parse_mode'                                       => 'html',
            'reply_markup'                                     => $this->botController->getListAttributeValues($this->bot, $compositionDictionary->id,
                $page, [],
                ['output' => 'json'], $keyword), 'system_data' => array(
                'compositionAttrId' => $compositionDictionary->id, 'keyword' => $keyword, 'page' => $page
            )
        ]);
    }

    public function addCompositionPercent($compositionAttrId, $attribute_value_id)
    {
        $title                           = AttributeValue::where('id', $attribute_value_id)->value('title');
        $additionalParams['parse_mode']  = 'html';
        $additionalParams['system_data'] = array(
            'compositionAttrId' => $compositionAttrId, 'attribute_value_id' => $attribute_value_id
        );
        $message                         = 'Введите процент для '.$title.': '.iconv('UCS-4LE', 'UTF-8',
                pack('V', 0x1F447));
        $this->botAsk($message, function (BotManAnswer $answer) {
            $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
            $conversation         = $this->bot->getStoredConversation();
            $additionalParameters = unserialize($conversation['additionalParameters']);
            $compositionAttrId    = $additionalParameters['system_data']['compositionAttrId'];
            $attribute_value_id   = $additionalParameters['system_data']['attribute_value_id'];
            if ($answer->getText()) {
                if (intval($answer->getText()) >= 1 && intval($answer->getText()) <= 100) {
                    $percent = 0;
                    foreach ($this->compositions as $key => $composition) {
                        if (isset($this->compositions[$key]['value'])) {
                            $percent += $this->compositions[$key]['value'];
                        }
                    }
                    $percent = 100 - $percent;
                    foreach ($this->compositions as $key => $composition) {
                        if ($composition['id'] == $attribute_value_id) {
                            $this->compositions[$key]['value'] = min(intval($answer->getText()), $percent);
                        }
                    }
                    $message = '<b>Состав товара:</b> ';
                    $sostav  = array();
                    foreach ($this->compositions as $key => $composition) {
                        $title    = AttributeValue::where('id', $composition['id'])->value('title');
                        $sostav[] = $title.' '.$composition['value'].'%';
                    }
                    $message .= implode(', ', $sostav);
                    $this->botController->reply($this->bot, $message, [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Выйти без сохранения'],
                            [], 'menu', true)
                    ], 'composition');
                    $percent = 0;
                    foreach ($this->compositions as $key => $composition) {
                        if (isset($this->compositions[$key]['value'])) {
                            $percent += $this->compositions[$key]['value'];
                        }
                    }
                    if ($percent >= 100) {
                        $this->addParams();
                    } else {
                        $this->addComposition();
                    }
                } else {
                    $this->addCompositionPercent($compositionAttrId, $attribute_value_id);
                }
            }
        }, $additionalParams);
    }

    public function addParams()
    {
        $category              = Category::findOrFail($this->product->category_id);
        $system_category       = $category->system_category;
        $categoryAttributes    = (new CategoryService)->getAttributes($category);
        $requiredAttributes    = [];
        $notRequiredAttributes = [];
        $variationAttributes   = [];
        if ( ! empty($categoryAttributes['attributes'])) {
            foreach ($categoryAttributes['attributes'] as $attribute) {
                $attribute['subject'] = 'attribute';
                if ($attribute['required']) {
                    $requiredAttributes[] = $attribute;
                } else {
                    $notRequiredAttributes[] = $attribute;
                }
            }
        }
        if ( ! empty($categoryAttributes['variationAttributes'])) {
            foreach ($categoryAttributes['variationAttributes'] as $attribute) {
                $attribute['subject'] = 'variation';
                if ( ! empty($categoryAttributes['modificationAttributes'])) {
                    foreach ($categoryAttributes['modificationAttributes'] as $modification) {
                        $attribute['modifications'][] = $modification;
                    }
                }
                $variationAttributes[] = $attribute;
            }
        }

        $this->categoryAttributes = array_merge($requiredAttributes, $variationAttributes, $notRequiredAttributes);
        if ( ! empty($this->categoryAttributes)) {
            $this->addRequiredParam(reset($this->categoryAttributes));
        }
    }

    public function addRequiredParam($attribute, $page = 1, $keyword = false)
    {
        if ( ! empty($attribute)) {
            if ($attribute['subject'] == 'variation') {
                $this->addVariations($attribute);

                return;
            }

            $additionalParams       = ['parse_mode' => 'html'];
            $actionsProductKeyboard = ['Выйти без сохранения'];
            if ( ! $attribute['required']) {
                $actionsProductKeyboard[] = 'Сохранить';
            }
            if (isset($this->data['attributes'][$attribute['id']]) && ! empty($this->data['attributes'][$attribute['id']])) {
                if ($attribute['type'] == 'dictionary') {
                    if ($attribute['is_collection']) {
                        $actionsProductKeyboard[] = 'Пропустить шаг';
                    }
                    $titles      = AttributeValue::whereIn('id',
                        $this->data['attributes'][$attribute['id']])->pluck('title');
                    $breadcrumbs = [];
                    if ( ! empty($titles)) {
                        foreach ($titles as $title) {
                            $breadcrumbs[] = $title;
                        }
                    }
                    $this->botController->reply($this->bot,
                        '<b>'.$attribute['title'].':</b> '.implode(', ', $breadcrumbs), [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                $actionsProductKeyboard, [], 'menu', true)
                        ], 'attribute_'.$attribute['id']);
                }
            } else {
                $this->botController->reply($this->bot, '<b>'.$attribute['title'].':</b> _____________', [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard, [],
                        'menu', true)
                ], 'attribute_'.$attribute['id']);
            }

            $additionalParams = ['parse_mode' => 'html'];
            $message          = 'Введите "'.$attribute['title'].'" '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
            if ($attribute['type'] == 'dictionary') {
                if ( ! empty($this->data['attributes'][$attribute['id']])) {
                    $message = '"'.$attribute['title'].'", выберите ещё вариант '."\n".' или пропустите шаг '.iconv('UCS-4LE',
                            'UTF-8', pack('V', 0x1F447));
                } else {
                    $message = 'Выберите "'.$attribute['title'].'" '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                }
                if ($keyword) {
                    $message .= "\n".'Поиск: '.$keyword;
                }
                $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'],
                    $page, [], ['output' => 'json'], $keyword);
            } else {
            }
            $additionalParams['system_data'] = array('attribute' => $attribute, 'page' => $page, 'keyword' => $keyword);
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer, 'addRequiredParam')) {
                    return;
                }
                if ($answer->getText()) {
                    $conversation         = $this->bot->getStoredConversation();
                    $additionalParameters = unserialize($conversation['additionalParameters']);
                    $page                 = $additionalParameters['system_data']['page'];
                    $keyword              = $additionalParameters['system_data']['keyword'];
                    $attribute            = $additionalParameters['system_data']['attribute'];
                    $attribute            = reset($this->categoryAttributes);
                    if ($answer->isInteractiveMessageReply()) {
                        if (preg_match('/^listAttributeValue\_/', $answer->getText())) {
                            $page = 1;
                            $temp = explode('_page_', $answer->getText());
                            if (isset($temp[1])) {
                                $page = intval($temp[1]);
                            }
                            $this->addRequiredParam(reset($this->categoryAttributes), $page, $keyword);
                        } elseif (preg_match('/^selectAttributeValue\_/', $answer->getText())) {
                            $temp                                         = explode('_', $answer->getText());
                            $attribute_id                                 = array_pop($temp);
                            $this->data['attributes'][$attribute['id']][] = $attribute_id;
                            if ($attribute['type'] == 'dictionary') {
                                $this->data['dictionaries'][] = $attribute['id'];
                            }
                            if ($attribute['is_collection']) {
                                $actionsProductKeyboard = ['Выйти без сохранения', 'Пропустить шаг'];
                                $titles                 = AttributeValue::whereIn('id',
                                    $this->data['attributes'][$attribute['id']])->pluck('title');
                                $breadcrumbs            = [];
                                if ( ! empty($titles)) {
                                    foreach ($titles as $title) {
                                        $breadcrumbs[] = $title;
                                    }
                                }
                                $message = '<b>'.$attribute['title'].':</b> '.implode(', ', $breadcrumbs);
                            } else {
                                $actionsProductKeyboard = ['Выйти без сохранения'];
                                $message                = '<b>'.$attribute['title'].':</b> '.AttributeValue::where('id',
                                        $attribute_id)->value('title');
                                $this->botController->reply($this->bot, $message, [
                                    'parse_mode'   => 'html',
                                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                        $actionsProductKeyboard)
                                ], 'attribute_'.$attribute['id']);
                                array_shift($this->categoryAttributes);
                            }
                            $this->addRequiredParam(reset($this->categoryAttributes));
                        } elseif ($answer->getText() == 'searchExit') {
                            $this->addRequiredParam($attribute, 1, false);
                        }
                    } else {
                        if ($attribute['type'] == 'dictionary') {
                            $this->addRequiredParam($attribute, 1, $answer->getText());
                        } else {
                            $attribute                                  = array_shift($this->categoryAttributes);
                            $this->data['attributes'][$attribute['id']] = $answer->getText();
                            $actionsProductKeyboard                     = ['Выйти без сохранения', 'Пропустить шаг'];
                            $this->botController->reply($this->bot,
                                '<b>'.$attribute['title'].':</b> '.$this->data['attributes'][$attribute['id']], [
                                    'parse_mode'   => 'html',
                                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                        $actionsProductKeyboard)
                                ], 'attribute_'.$attribute['id']);
                            $this->addRequiredParam(reset($this->categoryAttributes));
                        }
                    }
                }
            }, $additionalParams);
        } else {
            $this->saveProduct();
        }
    }

    public function addVariations($attribute)
    {
        $actionsProductKeyboard = ['Выйти без сохранения'];
        $message                = '<b><u>Вариации товара</u></b>';
        $this->botController->reply($this->bot, $message, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
        ], 'submenu');
        $this->addVariation($attribute);
    }

    public function getMessageVariation($variationKey)
    {
        $message = '';
        if ($variationKey >= 0) {
            $message = "<b><u>Вариация товара №".($variationKey + 1)."</u></b> "."\n";
            if (isset($this->data['variations'][$variationKey]['attributes'])) {
                foreach ($this->data['variations'][$variationKey]['attributes'] as $attribute_id => $attribute_value_id) {
                    $message .= '<b>'.(Attribute::where('id',
                            $attribute_id)->value('title')).':</b> '.(AttributeValue::where('id',
                            $attribute_value_id)->value('title'))."\n";
                }
            }
            if (isset($this->data['variations'][$variationKey]['sku'])) {
                $message .= '<b>Артикул:</b> '.$this->data['variations'][$variationKey]['sku']."\n";
            }
            if (isset($this->data['variations'][$variationKey]['status'])) {
                if ($this->data['variations'][$variationKey]['status'] == 'published') {
                    $message .= '<b>Статус:</b> Включено'."\n";
                }
                if ($this->data['variations'][$variationKey]['status'] == 'unpublished') {
                    $message .= '<b>Статус:</b> Выключено'."\n";
                }
            }
            if (isset($this->data['variations'][$variationKey]['images'])) {
                $message .= '<b>Изображения вариации товара:</b> Загружено '.count($this->data['variations'][$variationKey]['images']).' фото'."\n";
            }
            $message .= "\n";
            if (isset($this->data['variations'][$variationKey]['items'])) {
                foreach ($this->data['variations'][$variationKey]['items'] as $modificationKey => $item) {
                    $message .= "<b><u>Модификация №".($variationKey + 1)."-".($modificationKey + 1)."</u></b> "."\n";
                    if (isset($item['attributes'])) {
                        foreach ($item['attributes'] as $attribute_id => $attribute_value_id) {
                            $attribute = Attribute::where('id', $attribute_id)->first();
                            if ($attribute->type == 'dictionary') {
                                $message .= '<b>'.$attribute->title.':</b> '.(AttributeValue::where('id',
                                        $attribute_value_id)->value('title'))."\n";
                            } else {
                                $message .= '<b>'.$attribute->title.':</b> '.$attribute_value_id."\n";
                            }
                        }
                    }
                    if (isset($item['barcode'])) {
                        $message .= '<b>Штрих-код:</b> '.$item['barcode']."\n";
                    }
                    if (isset($item['sku'])) {
                        $message .= '<b>Артикул для озона:</b> '.$item['barcode']."\n";
                    }
                    $message .= "\n";
                }
            }
            $message .= "\n";
        }

        return $message;
    }


    public function addVariation($attribute, $page = 1, $triggerPagination = false, $keyword = false)
    {
        $actionsProductKeyboard = ['Выйти без сохранения'];
        $additionalParams       = ['parse_mode' => 'html'];
        $variationKey           = 0;
        if (isset($this->data['variations'])) {
            foreach ($this->data['variations'] as $key => $variation) {
                if (isset($variation['attributes']) || isset($variation['sku']) || isset($variation['status']) || isset($variation['images'])) {
                    $variationKey = $key;
                }
                if (isset($variation['attributes']) && isset($variation['sku']) && isset($variation['status']) && isset($variation['images'])) {
                    $variationKey = $key + 1;
                }
            }
        }
        if ( ! isset($this->data['variations'][$variationKey]['attributes'])) {
            $this->data['variations'][$variationKey]['attributes'] = [];
        }
        if ( ! $triggerPagination) {
            if ($variationKey) {
                $actionsProductKeyboard[] = 'Пропустить шаг';
            }
            $message = "<b>Вариация товара №".($variationKey + 1)."</b> ";
            if ($variationKey) {
                $message .= "или пропустите шаг";
            }
            if ($attribute['type'] == 'dictionary') {
                $this->botController->reply($this->bot, $message, [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard, [],
                        'menu', true)
                ], 'pause');
            } else {
                $this->botController->reply($this->bot, $message, [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                ], 'pause');
            }
        }
        $message          = 'Введите "'.$attribute['title'].'" '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        $additionalParams = ['parse_mode' => 'html'];
        if ($attribute['type'] == 'dictionary') {
            $message = 'Выберите "'.$attribute['title'].'" '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
            if ($keyword) {
                $message .= "\n".'Поиск: '.$keyword;
            }
            $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'], $page,
                [], ['output' => 'json'], $keyword);
        }
        $additionalParams['system_data'] = array('attribute' => $attribute, 'page' => $page, 'keyword' => $keyword);
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer, 'addVariation')) {
                return;
            }
            $conversation         = $this->bot->getStoredConversation();
            $additionalParameters = unserialize($conversation['additionalParameters']);
            $page                 = $additionalParameters['system_data']['page'];
            $keyword              = $additionalParameters['system_data']['keyword'];
            $attribute            = $additionalParameters['system_data']['attribute'];
            if ($answer->isInteractiveMessageReply()) {
                $actionsProductKeyboard = ['Выйти без сохранения'];
                $additionalParams       = ['parse_mode' => 'html'];
                if (preg_match('/^listAttributeValue\_/', $answer->getText())) {
                    $page = 1;
                    $temp = explode('_page_', $answer->getText());
                    if (isset($temp[1])) {
                        $page = intval($temp[1]);
                    }
                    $this->addVariation(reset($this->categoryAttributes), $page, true, $keyword);
                } elseif ($answer->getText() == 'searchExit') {
                    $this->addVariation(reset($this->categoryAttributes), 1, false, false);
                } elseif (preg_match('/^selectAttributeValue\_/', $answer->getText())) {
                    $variationKey                                                            = $this->getCurrentVariationKey();
                    $temp                                                                    = explode('_',
                        $answer->getText());
                    $attribute_id                                                            = array_pop($temp);
                    $attribute                                                               = reset($this->categoryAttributes);
                    $this->data['variations'][$variationKey]['attributes'][$attribute['id']] = $attribute_id;
                    $message                                                                 = $this->getMessageVariation($variationKey);
                    $this->botController->reply($this->bot, $message, [
                        'parse_mode'   => 'html',
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                    ], 'variation_'.($variationKey + 1));
                    $message = 'Введите "Артикул" вариации '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                    $this->botAsk($message, function (BotManAnswer $answer) {
                        if ($answer->getText()) {
                            $actionsProductKeyboard = ['Выйти без сохранения'];
                            $additionalParams       = ['parse_mode' => 'html'];
                            if ($this->controllerKeyboard($answer)) {
                                return;
                            }
                            $variationKey                                   = $this->getCurrentVariationKey();
                            $this->data['variations'][$variationKey]['sku'] = $answer->getText();
                            $message                                        = $this->getMessageVariation($variationKey);
                            $this->botController->reply($this->bot, $message, [
                                'parse_mode'   => 'html',
                                'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                    $actionsProductKeyboard)
                            ], 'variation_'.($variationKey + 1));
                            $question = BotManQuestion::create('Выберите статус вариации товара '.iconv('UCS-4LE',
                                    'UTF-8', pack('V', 0x1F447)))->callbackId('product_variation_status');
                            $question->addButtons([
                                Button::create('Включено')->value('published'),
                                Button::create('Выключено')->value('unpublished')
                            ]);
                            $this->botAsk($question, function (BotManAnswer $answer) {
                                if ($answer->getText()) {
                                    $actionsProductKeyboard = ['Выйти без сохранения', 'Пропустить шаг'];
                                    $additionalParams       = ['parse_mode' => 'html'];
                                    if ($this->controllerKeyboard($answer)) {
                                        return;
                                    }
                                    $variationKey                                      = $this->getCurrentVariationKey();
                                    $this->data['variations'][$variationKey]['status'] = $answer->getText();
                                    $message                                           = $this->getMessageVariation($variationKey);
                                    $this->botController->reply($this->bot, $message, [
                                        'parse_mode'   => 'html',
                                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                            $actionsProductKeyboard)
                                    ], 'variation_'.($variationKey + 1));
                                    $this->botAskForImages(iconv('UCS-4LE', 'UTF-8',
                                            pack('V', 0x2199)).' Загрузите сжатые изображения вариации товара',
                                        function ($images) {
                                            if ( ! empty($images)) {
                                                $this->botController->setCache($this->bot->getMessage()->getPayload()['message_id']);
                                                $actionsProductKeyboard = ['Выйти без сохранения'];
                                                $variationKey           = $this->getCurrentVariationKey();
                                                foreach ($images as $image) {
                                                    $this->data['variations'][$variationKey]['images'][] = $image->getUrl();
                                                }
                                                $attribute = reset($this->categoryAttributes);
                                                $message   = $this->getMessageVariation($variationKey);
                                                $this->botController->reply($this->bot, $message, [
                                                    'parse_mode'   => 'html',
                                                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                                        $actionsProductKeyboard)
                                                ], 'variation_'.($variationKey + 1));
                                                if (isset($attribute['modifications'])) {
                                                    $this->addModifications($attribute);
                                                } else {
                                                    $this->addVariation($attribute);
                                                }
                                            }
                                        },
                                        function (BotManAnswer $answer) {
                                            if ($this->controllerKeyboard($answer)) {
                                                return;
                                            }
                                        },
                                        [
                                            'parse_mode'   => 'html',
                                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                                $actionsProductKeyboard)
                                        ]);
                                }
                            });
                        }
                    }, $additionalParams);
                }
            } else {
                if ($attribute['type'] == 'dictionary') {
                    $this->addVariation($attribute, 1, false, $answer->getText());
                }
            }
        }, $additionalParams);
    }

    public function addModifications($attribute)
    {
        $actionsProductKeyboard = ['Выйти без сохранения'];
        $variationKey           = $this->getCurrentVariationKey();
        $modificationKey        = $this->getCurrentVariationModificationKey();
        $message                = '<b><u>Модификация вариации товара №'.($variationKey + 1).'-'.($modificationKey + 1).'</u></b>'."\n";
        if ($modificationKey) {
            $message                  .= 'Или пропустите шаг'."\n";
            $actionsProductKeyboard[] = 'Пропустить шаг';
        }
        $this->botController->reply($this->bot, $message, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
        ], 'pause');
        $this->addModification(reset($attribute['modifications']));
    }

    public function addModification($attribute, $page = 1, $triggerPagination = false, $keyword = false)
    {
        $modificationKey        = 0;
        $actionsProductKeyboard = ['Выйти без сохранения'];
        $additionalParams       = ['parse_mode' => 'html'];
        $variationKey           = $this->getCurrentVariationKey();
        $modificationKey        = $this->getCurrentVariationModificationKey();
        $message                = 'Введите "'.$attribute['title'].'" модификации '.iconv('UCS-4LE', 'UTF-8',
                pack('V', 0x1F447));
        if ($attribute['type'] == 'dictionary') {
            $message                          = 'Выберите "'.$attribute['title'].'" модификации '.iconv('UCS-4LE',
                    'UTF-8', pack('V', 0x1F447));
            $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'], $page,
                [], ['output' => 'json'], $keyword);
        }
        $additionalParams['system_data'] = array(
            'attribute'       => $attribute, 'page' => $page, 'keyword' => $keyword, 'variationKey' => $variationKey,
            'modificationKey' => $modificationKey
        );
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer, 'addModification')) {
                return;
            }
            $conversation         = $this->bot->getStoredConversation();
            $additionalParameters = unserialize($conversation['additionalParameters']);
            $page                 = $additionalParameters['system_data']['page'];
            $keyword              = $additionalParameters['system_data']['keyword'];
            $attribute            = $additionalParameters['system_data']['attribute'];
            $variationKey         = $additionalParameters['system_data']['variationKey'];
            $modificationKey      = $additionalParameters['system_data']['modificationKey'];
            $attribute            = array();
            $attribute_           = reset($this->categoryAttributes);
            $variationKey         = $this->getCurrentVariationKey();
            $modificationKey      = $this->getCurrentVariationModificationKey();
            foreach ($attribute_['modifications'] as $attributeKey => $item) {
                if ( ! isset($this->data['variations'][$variationKey]['items'][$modificationKey]['attributes'][$item['id']])) {
                    $attribute = $item;
                    break;
                }
            }
            if ($answer->getText()) {
                if ($answer->isInteractiveMessageReply()) {
                    $actionsProductKeyboard = ['Выйти без сохранения'];
                    $additionalParams       = ['parse_mode' => 'html'];


                    if (preg_match('/^listAttributeValue\_/', $answer->getText())) {
                        $page = 1;
                        $temp = explode('_page_', $answer->getText());
                        if (isset($temp[1])) {
                            $page = intval($temp[1]);
                        }
                        $this->addModification($attribute_['modifications'][$attributeKey], $page, true, $keyword);
                    } elseif (preg_match('/^selectAttributeValue\_/', $answer->getText())) {
                        if ( ! empty($this->messages)) {
                            $this->botController->setCache(array_pop($this->messages));
                        }
                        $temp                                                                                               = explode('_',
                            $answer->getText());
                        $attribute_id                                                                                       = array_pop($temp);
                        $this->data['variations'][$variationKey]['items'][$modificationKey]['attributes'][$attribute['id']] = $attribute_id;
                        $message                                                                                            = $this->getMessageVariation($variationKey);
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                $actionsProductKeyboard)
                        ], 'variation_'.($variationKey + 1));
                        if (isset($attribute_['modifications'][$attributeKey + 1])) {
                            $this->addModification($attribute_['modifications'][$attributeKey + 1]);
                        } else {
                            $this->addModificationStaticParams();
                        }
                    } elseif ($answer->getText() == 'searchExit') {
                        $this->addModification($attribute, 1, false, false);
                    }
                } else {
                    $actionsProductKeyboard = ['Выйти без сохранения'];
                    $additionalParams       = ['parse_mode' => 'html'];
                    $message                = $this->getMessageVariation($variationKey);
                    if (isset($attribute_['modifications'][$attributeKey + 1]) && $attribute_['modifications'][$attributeKey + 1]['type'] == 'dictionary') {
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                $actionsProductKeyboard, [], 'menu', true)
                        ], 'variation_'.($variationKey + 1));
                    } else {
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                $actionsProductKeyboard)
                        ], 'variation_'.($variationKey + 1));
                    }
                    if ($attribute['type'] == 'dictionary') {
                        $this->addModification($attribute, 1, false, $answer->getText());
                    } else {
                        $this->data['variations'][$variationKey]['items'][$modificationKey]['attributes'][$attribute['id']] = $answer->getText();
                        if (isset($attribute_['modifications'][$attributeKey + 1])) {
                            $this->addModification($attribute_['modifications'][$attributeKey + 1]);
                        } else {
                            $this->addModificationStaticParams();
                        }
                    }
                }
            }
        }, $additionalParams);
    }

    public function addModificationStaticParams()
    {
        $actionsProductKeyboard = ['Выйти без сохранения', 'Пропустить шаг'];
        $additionalParams       = [
            'parse_mode' => 'html', 'reply_markup' => $this->addProductKeyboard($actionsProductKeyboard)
        ];
        $message                = 'Введите "Штрих-Код" модификации '."\n".'или пропустите шаг для автоматической генерации'.iconv('UCS-4LE',
                'UTF-8', pack('V', 0x1F447));
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ( ! empty($this->messages)) {
                $this->botController->setCache(array_pop($this->messages));
            }
            $actionsProductKeyboard                                                        = [
                'Выйти без сохранения', 'Пропустить шаг'
            ];
            $additionalParams                                                              = [
                'parse_mode' => 'html', 'reply_markup' => $this->addProductKeyboard($actionsProductKeyboard)
            ];
            $variationKey                                                                  = $this->getCurrentVariationKey();
            $modificationKey                                                               = $this->getCurrentVariationModificationKey();
            $this->data['variations'][$variationKey]['items'][$modificationKey]['barcode'] = $answer->getText();
            $message                                                                       = $this->getMessageVariation($variationKey);
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
            ], 'variation_'.($variationKey + 1));
            $message = 'Введите "Артикул" модификации для озона '."\n".'или пропустите шаг для автоматической генерации'.iconv('UCS-4LE',
                    'UTF-8', pack('V', 0x1F447));
            $this->botAsk($message, function (BotManAnswer $answer) {
                $actionsProductKeyboard                                                    = [
                    'Выйти без сохранения', 'Пропустить шаг'
                ];
                $additionalParams                                                          = [
                    'parse_mode' => 'html', 'reply_markup' => $this->addProductKeyboard($actionsProductKeyboard)
                ];
                $variationKey                                                              = $this->getCurrentVariationKey();
                $modificationKey                                                           = $this->getCurrentVariationModificationKey();
                $this->data['variations'][$variationKey]['items'][$modificationKey]['sku'] = $answer->getText();
                $message                                                                   = $this->getMessageVariation($variationKey);
                $this->botController->reply($this->bot, $message, [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                ], 'variation_'.($variationKey + 1));
                $attribute = reset($this->categoryAttributes);
                if (isset($attribute['modifications'])) {
                    $this->addModifications($attribute);
                } else {
                    $this->addVariation($attribute);
                }
            }, $additionalParams);
        }, $additionalParams);
    }

    public function getCurrentVariationKey()
    {
        $variationKey = 0;
        if (isset($this->data['variations'])) {
            foreach ($this->data['variations'] as $key => $variation) {
                if (isset($variation['attributes']) || isset($variation['sku']) || isset($variation['status']) || isset($variation['images'])) {
                    $variationKey = $key;
                }
                //if(isset($variation['attributes']) && isset($variation['sku']) && isset($variation['status']) && isset($variation['images'])) $variationKey=$key+1;
            }
        }

        return $variationKey;
    }

    public function getCurrentVariationModificationKey()
    {
        $modificationKey = 0;
        if (isset($this->data['variations'])) {
            $variationKey = $this->getCurrentVariationKey();
            if (isset($this->data['variations'][$variationKey]['items'])) {
                foreach ($this->data['variations'][$variationKey]['items'] as $key => $item) {
                    if (isset($item['attributes']) && isset($item['barcode']) && isset($item['sku'])) {
                        $modificationKey = $key + 1;
                    }
                }
            }
        }

        return $modificationKey;
    }

    public function addSku()
    {
        $this->botAsk('Введите артикул товара '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
                    if ($this->controllerKeyboard($answer, 'addBarCode')) {
                        return;
                    }
                    $this->product->sku = $answer->getText();
                    $response           = $this->bot->reply('<b>Артикул товара:</b> '.$this->product->sku,
                        ['parse_mode' => 'html', 'reply_markup' => $this->addProductKeyboard(['exit'])])->getContent();
                    $response           = json_decode($response);
                    if (isset($response->ok) && $response->ok) {
                        $this->messages[] = $response->result->message_id;
                    }
                    $this->addBarCode();
                }
            }, ['parse_mode' => 'html', 'reply_markup' => $this->addProductKeyboard(['exit'])]);
    }

    public function addBarCode()
    {
        $this->botAsk('Введите штрих-код товара '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
            function (BotManAnswer $answer) {
                if ($answer->getText()) {
                    $this->botController->setCache($answer->getMessage()->getPayload()['message_id']);
                    if ($this->controllerKeyboard($answer, 'addDescription')) {
                        return;
                    }
                    $this->product->sku = $answer->getText();
                    $response           = $this->bot->reply('<b>Штрих-код товара:</b> '.$this->product->sku,
                        ['parse_mode' => 'html', 'reply_markup' => $this->addProductKeyboard(['exit'])])->getContent();
                    $response           = json_decode($response);
                    if (isset($response->ok) && $response->ok) {
                        $this->messages[] = $response->result->message_id;
                    }
                    $this->addDescription();
                }
            }, ['parse_mode' => 'html', 'reply_markup' => $this->addProductKeyboard(['exit'])]);
    }

    public function run()
    {
        $this->addTitle();
    }

    public function saveProduct()
    {
        $this->product->meta_title       = $this->product->title;
        $this->product->meta_description = $this->product->description;
        $this->product->meta_keywords    = $this->product->title;
        foreach ($this->data['attributes'] as $attribute_id => $value) {
            if (is_array($value)) {
                $this->data['attributes'][$attribute_id] = implode(';', $value);
            }
        }
        if ( ! empty($this->data['variations'])) {
            foreach ($this->data['variations'] as $key => $variation) {
                if (isset($variation['sku'])) {
                    $this->data['variations'][$key]['id'] = Str::uuid();
                    if (isset($variation['items'])) {
                        foreach ($variation['items'] as $k => $item) {
                            $this->data['variations'][$key]['items'][$k]['id'] = false;
                        }
                    }
                } else {
                    unset($this->data['variations'][$key]);
                }
            }
        }
        $this->productService->save($this->data, $this->product);
        if ($this->need_composition && ! empty($this->compositions)) {
            $this->product->update(['compositions' => $this->compositions]);
        }
        $this->botController->listProduct($this->bot);

        return;
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

    public function addProductKeyboard($actions = [])
    {
        $btn = [];
        foreach ($actions as $action) {
            switch ($action) {
                case 'exit':
                    $btn[] = ["text" => "Выйти без сохранения", "callback_data" => '/exit_add_product'];
                    break;
                case 'save':
                    $btn[] = ["text" => "Сохранить", "callback_data" => '/save_add_product'];
                    break;
                case 'next':
                    $btn[] = ["text" => "Пропустить шаг", "callback_data" => '/next_add_product'];
                    break;
            }
        }

        return json_encode(["keyboard" => [$btn], "resize_keyboard" => true]);
    }

    public function controllerKeyboard($answer, $step = false)
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
                $this->botController->listProduct($this->bot);
                break;
            case 'Пропустить шаг':
                $trigger = true;
                if ($step) {
                    switch ($step) {
                        case 'addRequiredParam':
                        case 'addVariation':
                            array_shift($this->categoryAttributes);
                            if ( ! empty($this->categoryAttributes)) {
                                $this->addRequiredParam(reset($this->categoryAttributes));
                            } else {
                                $this->saveProduct();
                            }
                            break;
                        case 'addModification':
                            if ( ! empty($this->categoryAttributes)) {
                                $this->addVariation(reset($this->categoryAttributes));
                            }
                            break;
                    }
                }
                break;
            case 'Сохранить':
                $trigger = true;
                $this->saveProduct();
                break;
        }

        return $trigger;
    }
}

