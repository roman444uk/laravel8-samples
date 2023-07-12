<?php

namespace App\Conversations;

use App\DTO\ProductVariationItemDTO;
use App\Http\Controllers\BotController;
use App\Models\Category;
use App\Models\Country;
use App\Models\Integration;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\System\Attribute;
use App\Models\System\AttributeValue;
use App\Models\Warehouse;
use App\Services\Shop\AttributeService;
use App\Services\Shop\CategoryService;
use App\Services\Shop\ProductService;
use App\Services\Shop\VariationService;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EditProductConversation extends Conversation
{
    protected BotController $botController;
    protected ?Product $product;
    protected $data = [];
    protected $messages = [];
    protected $categoryAttributes = [];
    protected $variationAttributes = [];
    protected $modificationAttributes = [];
    protected $keyExists = [
        'title'       => 'Название',
        'description' => 'Описание',
        'category_id' => 'Категория',
        'country_id'  => 'Страна производства',
        'weight'      => 'Вес, г',
        'length'      => 'Длина, мм',
        'width'       => 'Ширина, мм',
        'height'      => 'Высота, мм',
    ];
    protected $categoryService;
    protected $variationService;
    protected $productService;
    protected $attributeService;
    protected $currentKey;
    protected $currentOldData = [];
    protected $compositions = [];

    public function __construct()
    {
        $this->attributeService = new AttributeService();
        $this->categoryService  = new CategoryService();
        $this->variationService = new VariationService($this->attributeService);
        $this->productService   = new ProductService($this->categoryService, $this->variationService,
            $this->attributeService);
    }

    public function mainMenu()
    {
        $this->currentKey     = false;
        $this->currentOldData = [];
        if ( ! empty($this->product)) {
            $inline_keyboard   = [];
            $btn               = [];
            $btn[]             = [
                "text" => 'Осоновные параметры', "callback_data" => 'edit_product_required_attributes'
            ];
            $inline_keyboard[] = $btn;
            $btn               = [];
            $btn[]             = ["text" => 'Вариации товара', "callback_data" => 'edit_product_variations'];
            $inline_keyboard[] = $btn;
            $btn               = [];
            $btn[]             = ["text" => 'Дополнительные параметры', "callback_data" => 'edit_product_attributes'];
            $inline_keyboard[] = $btn;
            $btn               = [];
            $btn[]             = ["text" => 'Остатки', "callback_data" => 'edit_product_quantity'];
            $inline_keyboard[] = $btn;
            $btn               = [];
            $btn[]             = ["text" => 'Цены', "callback_data" => 'edit_product_price'];
            $inline_keyboard[] = $btn;
            $message           = '<b>Редактирование товара:</b> '.$this->product->sku;
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Список товаров', 'Сохранить'])
            ], 'submenu');
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $message                          = 'Выберите параметры для редактирования '.iconv('UCS-4LE', 'UTF-8',
                    pack('V', 0x1F447));
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        switch ($answer->getText()) {
                            case 'edit_product_required_attributes':
                                $this->attributeRequiredMenu();
                                break;
                            case 'edit_product_variations':
                                $this->variationMenu();
                                break;
                            case 'edit_product_attributes':
                                $this->attributeMenu();
                                break;
                            case 'edit_product_quantity':
                                $this->editProductQuantity();
                                break;
                            case 'edit_product_price':
                                $this->editProductPrice();
                                break;
                        }
                    } else {
                        $this->mainMenu();
                    }
                }
            }, $additionalParams);
        }
    }

    public function attributeRequiredMenu()
    {
        $this->currentKey     = false;
        $this->currentOldData = [];
        if ( ! empty($this->product)) {
            $inline_keyboard  = [];
            $need_composition = false;
            foreach ($this->keyExists as $key => $val) {
                $btn = [];
                if ($key == 'category_id') {
                    $category = Category::whereIn('id', array($this->product->{$key}))->first();
                    $value    = $category->title;
                    if ($category->system_category) {
                        if (isset($category->system_category->settings['need_composition'])) {
                            $need_composition = $category->system_category->settings['need_composition'];
                        }
                    }
                } elseif ($key == 'country_id') {
                    $value = $this->product->{$key} ? Country::where('id',
                        $this->product->{$key})->value('title_ru') : 'отсутствует';
                } else {
                    $value = $this->product->{$key} ? $this->product->{$key} : 'отсутствует';
                }
                $btn[]             = ["text" => $val.': '.$value, "callback_data" => $key];
                $inline_keyboard[] = $btn;
            }
            if ($need_composition) {
                if (isset($this->compositions) && $this->compositions) {
                    foreach ($this->compositions as $composition) {
                        $title    = AttributeValue::where('id', $composition['id'])->value('title');
                        $sostav[] = $title.' '.$composition['value'].'%';
                    }
                    $btn               = [];
                    $btn[]             = [
                        "text" => 'Состав: '.implode(', ', $sostav), "callback_data" => 'compositions'
                    ];
                    $inline_keyboard[] = $btn;
                } else {
                    $btn               = [];
                    $btn[]             = ["text" => 'Состав: отсутствует', "callback_data" => 'compositions'];
                    $inline_keyboard[] = $btn;
                }
            }

            $this->getCategoryAttributes();
            if ( ! empty($this->categoryAttributes)) {
                foreach ($this->categoryAttributes as $attribute) {
                    if ($attribute['required']) {
                        $attributeValues = 'отсутствует';
                        if (isset($this->data['attributes'][$attribute['id']])) {
                            $titles      = AttributeValue::whereIn('id',
                                $this->data['attributes'][$attribute['id']])->pluck('title');
                            $breadcrumbs = [];
                            if ( ! empty($titles)) {
                                foreach ($titles as $title) {
                                    $breadcrumbs[] = $title;
                                }
                            }
                            $attributeValues = implode(', ', $breadcrumbs);
                        }
                        $btn               = [];
                        $btn[]             = [
                            "text"          => $attribute['title'].': '.$attributeValues,
                            "callback_data" => 'attribute_'.$attribute['id']
                        ];
                        $inline_keyboard[] = $btn;
                    }
                }
            }

            $message = '<b>Редактирование товара:</b> '.$this->product->title;
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Меню редактирования'])
            ], 'submenu');
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $message                          = 'Выберите пункт для редактирования '.iconv('UCS-4LE', 'UTF-8',
                    pack('V', 0x1F447));
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        if (preg_match('/^attribute\_/', $answer->getText())) {
                            $this->editAttribute($answer->getText());
                        } elseif ($answer->getText() == 'compositions') {
                            $this->compositions = [];
                            $additionalParams   = ['parse_mode' => 'html'];
                            $message            = '<b>Редактирование параметра:</b> Состав';
                            $keyboardActions    = ['Список параметров'];
                            $this->botController->reply($this->bot, $message, [
                                'parse_mode'   => 'html',
                                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $keyboardActions)
                            ], 'subsubmenu');
                            $this->addComposition();
                        } else {
                            $this->editParam($answer->getText());
                        }
                    } else {
                        $this->attributeRequiredMenu()();
                    }
                }
            }, $additionalParams);
        }
    }

    public function attributeMenu()
    {
        $this->currentKey     = false;
        $this->currentOldData = [];
        if ( ! empty($this->product)) {
            $inline_keyboard = [];
            $this->getCategoryAttributes();
            if ( ! empty($this->categoryAttributes)) {
                foreach ($this->categoryAttributes as $attribute) {
                    if ( ! $attribute['required']) {
                        $attributeValues = 'неустановлено';
                        if (isset($this->data['attributes'][$attribute['id']]) && ! empty($this->data['attributes'][$attribute['id']])) {
                            if ($attribute['type'] == 'dictionary') {
                                $titles      = AttributeValue::whereIn('id',
                                    $this->data['attributes'][$attribute['id']])->pluck('title');
                                $breadcrumbs = [];
                                if ( ! empty($titles)) {
                                    foreach ($titles as $title) {
                                        $breadcrumbs[] = $title;
                                    }
                                }
                                $attributeValues = implode(', ', $breadcrumbs);
                            } else {
                                $attributeValues = is_array($this->data['attributes'][$attribute['id']]) ? implode(', ',
                                    $this->data['attributes'][$attribute['id']]) : $this->data['attributes'][$attribute['id']];
                            }
                        }
                        $btn               = [];
                        $btn[]             = [
                            "text"          => $attribute['title'].': '.$attributeValues,
                            "callback_data" => 'attribute_'.$attribute['id']
                        ];
                        $inline_keyboard[] = $btn;
                    }
                }
            }
            $message = '<b>Редактирование товара:</b> '.$this->product->title;
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Меню редактирования'])
            ], 'submenu');
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $message                          = 'Выберите пункт для редактирования '.iconv('UCS-4LE', 'UTF-8',
                    pack('V', 0x1F447));
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        $this->editAttribute($answer->getText());
                    } else {
                        $this->attributeMenu();
                    }
                }
            }, $additionalParams);
        }
    }

    public function variationMenu()
    {
        $this->currentKey     = false;
        $this->currentOldData = [];
        $message              = '<b>Редактирование товара:</b> '.$this->product->title;
        $this->botController->reply($this->bot, $message, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Меню редактирования'])
        ], 'submenu');
        $inline_keyboard = [];
        if (isset($this->data['variations']) && ! empty($this->data['variations'])) {
            foreach ($this->data['variations'] as $key => $variation) {
                if (isset($variation['attributes']) && ! empty($variation['attributes'])) {
                    $variationAttributes = [];
                    foreach ($variation['attributes'] as $variationAttribute) {
                        $variationAttributes[] = $variationAttribute;
                    }
                    $titles      = AttributeValue::whereIn('id', $variationAttributes)->pluck('title');
                    $breadcrumbs = [];
                    if ( ! empty($titles)) {
                        foreach ($titles as $title) {
                            $breadcrumbs[] = $title;
                        }
                    }
                    $btn               = [];
                    $btn[]             = [
                        "text"          => $variation['sku'].'/'.implode('/', $breadcrumbs),
                        "callback_data" => 'editVariationId_'.$key
                    ];
                    $btn[]             = [
                        "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x274C)),
                        "callback_data" => 'deleteVariationId_'.$key
                    ];
                    $inline_keyboard[] = $btn;
                }
            }
            $message = 'Выберите вариацию для редактирования '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        } else {
            $message = '<b>Внимание: в товаре отсутствуют вариации, сохранение приведёт к удалению всего товара!</b>'."\n";
            $message .= 'Добавьте вариацию для товара '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        }
        $btn                              = [];
        $btn[]                            = ["text" => "Добавить вариацию", "callback_data" => 'addVariation'];
        $inline_keyboard[]                = $btn;
        $additionalParams                 = ['parse_mode' => 'html'];
        $additionalParams['reply_markup'] = json_encode([
            "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
        ]);
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            if ($answer->getText()) {
                if ($answer->isInteractiveMessageReply()) {
                    if (preg_match('/^deleteVariationId\_/', $answer->getText())) {
                        $variationKey = intval(str_replace('deleteVariationId_', '', $answer->getText()));
                        if (isset($this->data['variations'][$variationKey])) {
                            $this->deleteVariation($variationKey);
                        } else {
                            $this->variationMenu();
                        }
                    } elseif (preg_match('/^editVariationId\_/', $answer->getText())) {
                        $variationKey = intval(str_replace('editVariationId_', '', $answer->getText()));
                        if (isset($this->data['variations'][$variationKey])) {
                            $this->editVariation($variationKey);
                        } else {
                            $this->variationMenu();
                        }
                    } elseif ($answer->getText() == 'addVariation') {
                        $this->addVariation(reset($this->variationAttributes));
                    }
                }
            }
        }, $additionalParams);
    }

    public function modificationMenu($variationKey)
    {
        $this->currentKey     = false;
        $this->currentOldData = [];
        $modificationKey      = 0;
        $message              = '<b>Редактирование товара:</b> '.$this->product->title;
        $this->botController->reply($this->bot, $message, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Список вариаций'])
        ], 'submenu');
        $inline_keyboard = [];
        if (isset($this->data['variations'][$variationKey]['items']) && ! empty($this->data['variations'][$variationKey]['items'])) {
            foreach ($this->data['variations'][$variationKey]['items'] as $key => $item) {
                if (isset($item['attributes']) && ! empty($item['attributes'])) {
                    $modificationAttributes = [];
                    /*
						foreach($item['attributes'] as $itemAttribute) 
							if(intval($itemAttribute)) $itemAttributes[]=$itemAttribute;
						$titles=AttributeValue::whereIn('id', $itemAttributes)->pluck('title');
						$breadcrumbs=[];
						if(!empty($titles)) foreach($titles as $title) $breadcrumbs[]=$title;
						*/
                    $btn               = [];
                    $btn[]             = [
                        "text" => $item['sku'], "callback_data" => 'editModificationId_'.$variationKey.'_'.$key
                    ];
                    $btn[]             = [
                        "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x274C)),
                        "callback_data" => 'deleteModificationId_'.$variationKey.'_'.$key
                    ];
                    $inline_keyboard[] = $btn;
                }
                $modificationKey = $key;
            }
            $modificationKey++;
            $message = 'Выберите модификацию для редактирования '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        } else {
            $message = '<b>В вариации отсутствуют модификации!</b>'."\n";
            $message .= 'Добавьте модификацию для вариации товара '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        }
        $btn                              = [];
        $btn[]                            = [
            "text" => "Добавить модификацию", "callback_data" => 'addModification_'.$variationKey.'_'.$modificationKey
        ];
        $inline_keyboard[]                = $btn;
        $additionalParams                 = ['parse_mode' => 'html'];
        $additionalParams['reply_markup'] = json_encode([
            "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
        ]);
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            if ($answer->getText()) {
                if ($answer->isInteractiveMessageReply()) {
                    $temp         = explode('_', $answer->getText());
                    $variationKey = 0;
                    if (isset($temp[1])) {
                        $variationKey = $temp[1];
                    }
                    $modificationKey = 0;
                    if (isset($temp[2])) {
                        $modificationKey = $temp[2];
                    }
                    switch ($temp[0]) {
                        case 'editModificationId':
                            $this->editModification($variationKey, $modificationKey);
                            break;
                        case 'deleteModificationId':
                            $this->deleteModification($variationKey, $modificationKey);
                            break;
                        case 'addModification':
                            $this->addModification($variationKey, $modificationKey,
                                reset($this->modificationAttributes));
                            break;
                    }
                }
            }
        }, $additionalParams);
    }

    public function addModification($variationKey, $modificationKey, $attribute, $page = 1, $keyword = false)
    {
        $actionsProductKeyboard = ['Выйти без сохранения'];
        $additionalParams       = ['parse_mode' => 'html'];
        $message                = 'Введите "'.$attribute['title'].'" модификации '.iconv('UCS-4LE', 'UTF-8',
                pack('V', 0x1F447));
        if ($attribute['type'] == 'dictionary') {
            $message                          = 'Выберите "'.$attribute['title'].'" модификации '.iconv('UCS-4LE',
                    'UTF-8', pack('V', 0x1F447));
            $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'], $page,
                [], ['output' => 'json'], $keyword);
        }
        $additionalParams['system_data'] = array(
            'attribute' => $attribute, 'variationKey' => $variationKey, 'modificationKey' => $modificationKey,
            'page'      => $page, 'keyword' => $keyword
        );
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer, 'addModification')) {
                return;
            }
            $conversation         = $this->bot->getStoredConversation();
            $additionalParameters = unserialize($conversation['additionalParameters']);
            $attribute            = $additionalParameters['system_data']['attribute'];
            $variationKey         = $additionalParameters['system_data']['variationKey'];
            $modificationKey      = $additionalParameters['system_data']['modificationKey'];
            $page                 = $additionalParameters['system_data']['page'];
            $keyword              = $additionalParameters['system_data']['keyword'];
            foreach ($this->modificationAttributes as $attributeKey => $modificationAttribute) {
                if ($modificationAttribute['id'] == $attribute['id']) {
                    break;
                }
            }
            $actionsProductKeyboard = ['Выйти без сохранения'];
            $additionalParams       = ['parse_mode' => 'html'];
            if ($answer->getText()) {
                if ($answer->isInteractiveMessageReply()) {
                    if (preg_match('/^listAttributeValue\_/', $answer->getText())) {
                        $page = 1;
                        $temp = explode('_page_', $answer->getText());
                        if (isset($temp[1])) {
                            $page = intval($temp[1]);
                        }
                        $this->addModification($variationKey, $modificationKey, $attribute, $page, $keyword);
                    } elseif (preg_match('/^selectAttributeValue\_/', $answer->getText())) {
                        $temp                                                                                               = explode('_',
                            $answer->getText());
                        $attribute_id                                                                                       = array_pop($temp);
                        $this->data['variations'][$variationKey]['items'][$modificationKey]['attributes'][$attribute['id']] = $attribute_id;
                        $message                                                                                            = $this->getMessageVariation($variationKey);
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                        ], 'variation_'.($variationKey + 1));
                        if (isset($attribute_['modifications'][$attributeKey + 1])) {
                            $this->addModification($attribute_['modifications'][$attributeKey + 1]);
                        } else {
                            $this->addModificationStaticParams($variationKey, $modificationKey);
                        }
                    } elseif ($answer->getText() == 'searchExit') {
                        $this->addModification($variationKey, $modificationKey, $attribute, 1, false);
                    }
                } else {
                    $message = $this->getMessageVariation($variationKey);
                    if (isset($this->modificationAttributes[$attributeKey + 1]) && $this->modificationAttributes[$attributeKey + 1]['type'] == 'dictionary') {
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard,
                                [], 'menu', true)
                        ], 'variation_'.($variationKey + 1));
                    } else {
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                        ], 'variation_'.($variationKey + 1));
                    }
                    if ($attribute['type'] == 'dictionary') {
                        $this->addModification($variationKey, $modificationKey, $attribute, 1, $answer->getText());
                    } else {
                        $this->data['variations'][$variationKey]['items'][$modificationKey]['attributes'][$attribute['id']] = $answer->getText();
                        if (isset($this->modificationAttributes[$attributeKey + 1])) {
                            $this->addModification($variationKey, $modificationKey,
                                $this->modificationAttributes[$attributeKey + 1]);
                        } else {
                            $this->addModificationStaticParams($variationKey, $modificationKey);
                        }
                    }
                }
            }
        }, $additionalParams);
    }

    public function addModificationStaticParams($variationKey, $modificationKey)
    {
        $actionsProductKeyboard          = ['Выйти без сохранения', 'Пропустить шаг'];
        $additionalParams                = [
            'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                $actionsProductKeyboard)
        ];
        $additionalParams['system_data'] = array(
            'variationKey' => $variationKey, 'modificationKey' => $modificationKey
        );
        $message                         = 'Введите "Штрих-Код" модификации '."\n".'или пропустите шаг для автоматической генерации'.iconv('UCS-4LE',
                'UTF-8', pack('V', 0x1F447));
        $this->botAsk($message, function (BotManAnswer $answer) {
            $conversation                                                                  = $this->bot->getStoredConversation();
            $additionalParameters                                                          = unserialize($conversation['additionalParameters']);
            $variationKey                                                                  = $additionalParameters['system_data']['variationKey'];
            $modificationKey                                                               = $additionalParameters['system_data']['modificationKey'];
            $actionsProductKeyboard                                                        = [
                'Выйти без сохранения', 'Пропустить шаг'
            ];
            $additionalParams                                                              = [
                'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                    $actionsProductKeyboard)
            ];
            $additionalParams['system_data']                                               = array(
                'variationKey' => $variationKey, 'modificationKey' => $modificationKey
            );
            $this->data['variations'][$variationKey]['items'][$modificationKey]['barcode'] = $answer->getText();
            $message                                                                       = $this->getMessageVariation($variationKey);
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
            ], 'variation_'.($variationKey + 1));
            $message = 'Введите "Артикул" модификации для озона '."\n".'или пропустите шаг для автоматической генерации'.iconv('UCS-4LE',
                    'UTF-8', pack('V', 0x1F447));
            $this->botAsk($message, function (BotManAnswer $answer) {
                $conversation                                                              = $this->bot->getStoredConversation();
                $additionalParameters                                                      = unserialize($conversation['additionalParameters']);
                $variationKey                                                              = $additionalParameters['system_data']['variationKey'];
                $modificationKey                                                           = $additionalParameters['system_data']['modificationKey'];
                $actionsProductKeyboard                                                    = [
                    'Выйти без сохранения', 'Пропустить шаг'
                ];
                $additionalParams                                                          = [
                    'parse_mode' => 'html', 'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                        $actionsProductKeyboard)
                ];
                $this->data['variations'][$variationKey]['items'][$modificationKey]['sku'] = $answer->getText();
                $message                                                                   = $this->getMessageVariation($variationKey);
                $this->botController->reply($this->bot, $message, [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                ], 'variation_'.($variationKey + 1));
                $this->modificationMenu($variationKey);
            }, $additionalParams);
        }, $additionalParams);
    }

    public function editModification($variationKey, $modificationKey)
    {
        $modification = false;
        if (isset($this->data['variations'][$variationKey]['items'][$modificationKey])) {
            $modification = $this->data['variations'][$variationKey]['items'][$modificationKey];
        }
        if ( ! empty($modification)) {
            $additionalParams = ['parse_mode' => 'html'];
            $inline_keyboard  = [];
            $btn              = [];
            $message          = '<b>Редактирование товара:</b> '.$this->product->title;
            $this->botController->reply($this->bot, $message, $additionalParams, 'submenu');
            foreach ($modification['attributes'] as $attribute_id => $modificationAttribute) {
                $value = '';
                foreach ($this->modificationAttributes as $attribute) {
                    if ($attribute['id'] == $attribute_id) {
                        if ($attribute['type'] == 'dictionary') {
                            $value = AttributeValue::where('id', $modificationAttribute)->value('title');
                        } else {
                            $value = $modificationAttribute;
                        }
                    }
                }
                $btn               = [];
                $btn[]             = [
                    "text"          => Attribute::where('id', $attribute_id)->value('title').': '.$value,
                    "callback_data" => "editModificationAttribute_".$attribute_id
                ];
                $inline_keyboard[] = $btn;
            }
            $btn                    = [];
            $btn[]                  = [
                "text" => "Штрих-код: ".$modification['barcode'], "callback_data" => "editModificationBarcode"
            ];
            $inline_keyboard[]      = $btn;
            $btn                    = [];
            $btn[]                  = [
                "text" => "Артикул: ".$modification['sku'], "callback_data" => "editModificationSku"
            ];
            $inline_keyboard[]      = $btn;
            $actionsProductKeyboard = ['Список модификаций'];
            $message                = '<b>Редактирование модификации:</b> '.$modification['sku'];
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
            ], 'subsubmenu');
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $additionalParams['system_data']  = array(
                'variationKey' => $variationKey, 'modificationKey' => $modificationKey
            );
            $this->botAsk('Выберите пункт для редактирования '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
                function (BotManAnswer $answer) {
                    if ($this->controllerKeyboard($answer)) {
                        return;
                    }
                    $conversation         = $this->bot->getStoredConversation();
                    $additionalParameters = unserialize($conversation['additionalParameters']);
                    $variationKey         = $additionalParameters['system_data']['variationKey'];
                    $modificationKey      = $additionalParameters['system_data']['modificationKey'];
                    if ($answer->isInteractiveMessageReply()) {
                        $temp = explode('_', $answer->getText());
                        switch ($temp[0]) {
                            case 'editModificationAttribute':
                                if (isset($temp[1])) {
                                    $attribute = Attribute::where('id', $temp[1])->first()->toArray();
                                    $this->editModificationAttribute($attribute, $variationKey, $modificationKey);
                                }
                                break;
                            case 'editModificationBarcode':
                                $this->editModificationStaticParam($variationKey, $modificationKey, 'barcode');
                                break;
                            case 'editModificationSku':
                                $this->editModificationStaticParam($variationKey, $modificationKey, 'sku');
                                break;
                        }
                    }
                }, $additionalParams);
        }
    }

    public function editModificationStaticParam($variationKey, $modificationKey, $paramKey)
    {
        $additionalParams['parse_mode'] = 'html';
        if ($paramKey == 'barcode') {
            $paramName = 'Штрих-код';
        } elseif ($paramKey == 'sku') {
            $paramName = 'Артикул';
        }
        $message = '<b>Редактирование параметра:</b> '.$paramName;
        $this->botController->reply($this->bot, $message, ['parse_mode' => 'html'], 'pause');
        $additionalParams['system_data'] = array(
            'variationKey' => $variationKey, 'modificationKey' => $modificationKey, 'paramKey' => $paramKey
        );
        $message                         = 'Введите значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            $conversation                                                                  = $this->bot->getStoredConversation();
            $additionalParameters                                                          = unserialize($conversation['additionalParameters']);
            $variationKey                                                                  = $additionalParameters['system_data']['variationKey'];
            $modificationKey                                                               = $additionalParameters['system_data']['modificationKey'];
            $paramKey                                                                      = $additionalParameters['system_data']['paramKey'];
            $this->data['variations'][$variationKey]['items'][$modificationKey][$paramKey] = $answer->getText();
            $this->editModification($variationKey, $modificationKey);
        }, $additionalParams);
    }

    public function editModificationAttribute($attribute, $variationKey, $modificationKey, $page = 1, $keyword = false)
    {
        $additionalParams['parse_mode'] = 'html';
        $actionsProductKeyboard         = ['Список модификаций'];
        $message                        = '<b>Редактирование параметра:</b> '.$attribute['title'];
        if ($keyword) {
            $message .= "\n".'Поиск: '.$keyword;
        }
        $this->botController->reply($this->bot, $message, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard, [], 'menu',
                true)
        ], 'pause');
        if ($attribute['type'] == 'dictionary') {
            $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'], $page,
                [], ['output' => 'json'], $keyword);
        }
        $additionalParams['system_data'] = array(
            'attribute' => $attribute, 'variationKey' => $variationKey, 'modificationKey' => $modificationKey,
            'page'      => $page, 'keyword' => $keyword
        );
        $message                         = 'Введите значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            $conversation         = $this->bot->getStoredConversation();
            $additionalParameters = unserialize($conversation['additionalParameters']);
            $attribute            = $additionalParameters['system_data']['attribute'];
            $variationKey         = $additionalParameters['system_data']['variationKey'];
            $modificationKey      = $additionalParameters['system_data']['modificationKey'];
            $page                 = $additionalParameters['system_data']['page'];
            $keyword              = $additionalParameters['system_data']['keyword'];
            if ($answer->isInteractiveMessageReply()) {
                $page = 1;
                $temp = explode('_page_', $answer->getText());
                if (isset($temp[1])) {
                    $page = $temp[1];
                }
                $page = max($page, 1);
                $temp = explode('_', $temp[0]);
                switch ($temp[0]) {
                    case 'listAttributeValue':
                        $this->editModificationAttribute($attribute, $variationKey, $modificationKey, $page, $keyword);
                        break;
                    case 'selectAttributeValue':
                        $this->data['variations'][$variationKey]['items'][$modificationKey]['attributes'][$attribute['id']] = $temp[1];
                        $this->editModification($variationKey, $modificationKey);
                        break;
                    case 'searchExit':
                        $this->editModificationAttribute($attribute, $variationKey, $modificationKey, 1, false);
                        break;
                }
            } else {
                if ($attribute['type'] == 'dictionary') {
                    $this->editModificationAttribute($attribute, $variationKey, $modificationKey, 1,
                        $answer->getText());
                } else {
                    $this->data['variations'][$variationKey]['items'][$modificationKey]['attributes'][$attribute['id']] = $answer->getText();
                    $this->editModification($variationKey, $modificationKey);
                }
            }
        }, $additionalParams);
    }

    public function deleteModification($variationKey, $modificationKey)
    {
        $variation = false;
        if (isset($this->data['variations'][$variationKey])) {
            $variation = $this->data['variations'][$variationKey];
        }
        if ( ! empty($variation)) {
            $additionalParams = ['parse_mode' => 'html'];
            $btn              = [];
            $message          = '<b>Редактирование товара:</b> '.$this->product->title;
            $this->botController->reply($this->bot, $message, $additionalParams, 'submenu');
            $variationAttributes = [];
            /*foreach($variation['items'][$modificationKey]['attributes'] as $modificationAttribute) if(intval($modificationAttribute)) $modificationAttributes[]=$modificationAttribute;
			$titles=AttributeValue::whereIn('id', $modificationAttributes)->pluck('title');
			$breadcrumbs=[];
			if(!empty($titles)) foreach($titles as $title) $breadcrumbs[]=$title;*/
            $message = '<b>Модификация:</b> '.$variation['items'][$modificationKey]['sku'];
            $this->botController->reply($this->bot, $message, $additionalParams, 'subsubmenu');
            $btn[]                            = ["text" => "Да", "callback_data" => "yes"];
            $btn[]                            = ["text" => "Нет", "callback_data" => "no"];
            $inline_keyboard[]                = $btn;
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $additionalParams['system_data']  = array(
                'variationKey' => $variationKey, 'modificationKey' => $modificationKey
            );
            $message                          = 'Вы, действительно хотите удалить модификацию?';
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                $conversation         = $this->bot->getStoredConversation();
                $additionalParameters = unserialize($conversation['additionalParameters']);
                $variationKey         = $additionalParameters['system_data']['variationKey'];
                $modificationKey      = $additionalParameters['system_data']['modificationKey'];
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        switch ($answer->getText()) {
                            case 'yes':
                                if (isset($this->data['variations'][$variationKey]['items'][$modificationKey])) {
                                    unset($this->data['variations'][$variationKey]['items'][$modificationKey]);
                                }
                                if ( ! empty($this->data['variations'][$variationKey]['items'])) {
                                    $data                                             = $this->data['variations'][$variationKey]['items'];
                                    $this->data['variations'][$variationKey]['items'] = [];
                                    foreach ($data as $v) {
                                        $this->data['variations'][$variationKey]['items'][] = $v;
                                    }
                                }
                                $this->modificationMenu($variationKey);
                                break;
                            case 'no':
                                $this->modificationMenu($variationKey);
                                break;
                        }
                    } else {
                        $this->modificationMenu($variationKey);
                    }
                }
            }, $additionalParams);
        }
    }

    public function addComposition($page = 1, $keyword = false)
    {
        $compositionDictionary = \App\Models\System\Attribute::where([
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
            if ($this->controllerKeyboard($answer, 'attributeRequiredMenu')) {
                return;
            }
            if ($answer->getText()) {
                if ($this->controllerKeyboard($answer, 'addWeight')) {
                    return;
                }
                $conversation         = $this->bot->getStoredConversation();
                $additionalParameters = unserialize($conversation['additionalParameters']);
                $compositionAttrId    = $additionalParameters['system_data']['compositionAttrId'];
                $page                 = $additionalParameters['system_data']['page'];
                $keyword              = $additionalParameters['system_data']['keyword'];
                if ($answer->isInteractiveMessageReply()) {
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
            if ($this->controllerKeyboard($answer, 'attributeRequiredMenu')) {
                return;
            }
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
                        'reply_markup' => $this->botController->setMenuKeyboard($this->bot, ['Список параметров'])
                    ], 'subsubmenu');
                    $percent = 0;
                    foreach ($this->compositions as $key => $composition) {
                        if (isset($this->compositions[$key]['value'])) {
                            $percent += $this->compositions[$key]['value'];
                        }
                    }
                    if ($percent >= 100) {
                        $this->product->compositions = $this->compositions;
                        $this->attributeRequiredMenu();
                    } else {
                        $this->addComposition();
                    }
                } else {
                    $this->addCompositionPercent($compositionAttrId, $attribute_value_id);
                }
            }
        }, $additionalParams);
    }

    public function editParam($key, $parent_id = 0, $page = 1, $keyword = false)
    {
        $this->currentKey = $key;
        $additionalParams = ['parse_mode' => 'html'];
        $oldValue         = $this->product->{$key};
        $message          = '<b>Редактирование параметра:</b> '.$this->keyExists[$key];
        $keyboardActions  = ['Список параметров'];
        if (in_array($key, array('description'))) {
            $keyboardActions[] = 'Очистить параметр';
        }
        if (in_array($key, array('country_id'))) {
            $additionalParams['reply_markup'] = $this->botController->setMenuKeyboard($this->bot, $keyboardActions, [],
                'menu', true);
        } else {
            $additionalParams['reply_markup'] = $this->botController->setMenuKeyboard($this->bot, $keyboardActions);
        }
        $this->botController->reply($this->bot, $message, $additionalParams, 'subsubmenu');
        $additionalParams = ['parse_mode' => 'html'];
        $message          = '<b>Старое значение:</b> '.$oldValue."\n";
        if (in_array($key, array('category_id', 'country_id'))) {
            if ($key == 'category_id') {
                $additionalParams['reply_markup'] = $this->botController->getListCategory($this->bot, $parent_id, $page,
                    [
                        [
                            "text"          => iconv('UCS-4LE', 'UTF-8', pack('V', 0x2705)),
                            "callback_data" => 'product_category_id_'
                        ]
                    ], ['output' => 'json']);
                $oldValue                         = $this->product->{$key} ? Category::where([
                    [
                        'id', $this->product->{$key}
                    ], ['status', 'published']
                ])->value('title') : 'отсутствует';
                $message                          = '<b>Старое значение:</b> '.$oldValue."\n";
            } elseif ($key == 'country_id') {
                $additionalParams['reply_markup'] = $this->botController->getListCountry($this->bot, $page, 'json',
                    $keyword);
                $oldValue                         = $this->product->{$key} ? Country::where('id',
                    $this->product->{$key})->value('title_ru') : 'отсутствует';
                $message                          = '<b>Старое значение:</b> '.$oldValue."\n";
            }
            $message .= "Выберите ";
        } else {
            $message .= "Введите ";
        }
        $additionalParams['system_data'] = array('page' => $page, 'key' => $key, 'keyword' => $keyword);
        $message                         .= 'новое значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        if (in_array($key, array('country_id'))) {
            if ($keyword) {
                $message .= "\n".'Поиск: '.$keyword;
            }
        }
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer, 'attributeRequiredMenu')) {
                return;
            }
            if ($answer->getText()) {
                $conversation         = $this->bot->getStoredConversation();
                $additionalParameters = unserialize($conversation['additionalParameters']);
                $page                 = $additionalParameters['system_data']['page'];
                $keyword              = $additionalParameters['system_data']['keyword'];
                $key                  = $additionalParameters['system_data']['key'];
                if ($answer->isInteractiveMessageReply()) {
                    if (preg_match('/^listCategory\_/', $answer->getText()) || preg_match('/^listCountry\_/',
                            $answer->getText())) {
                        $parent_id = 0;
                        $page      = 1;
                        $temp      = explode('_page_', $answer->getText());
                        if (isset($temp[0])) {
                            $tmp       = explode('_',
                                str_replace(array('listCategory_', 'listCountry_'), '', $temp[0]));
                            $parent_id = array_pop($tmp);
                        }
                        if (isset($temp[1])) {
                            $page = intval($temp[1]);
                        }
                        $this->editParam($key, $parent_id, $page, $keyword);
                    } elseif (preg_match('/^product\_category\_id\_/',
                            $answer->getText()) || preg_match('/^selectCountry\_/', $answer->getText())) {
                        $temp                               = explode('_',
                            str_replace(array('product_category_id_', 'selectCountry_'), '', $answer->getText()));
                        $this->product->{$this->currentKey} = array_pop($temp);
                        $this->attributeRequiredMenu();
                    } elseif ($answer->getText() == 'searchExit') {
                        $this->editParam($key, $parent_id = 0, $page = 1, false);
                    }
                } else {
                    if (in_array($key, array('country_id'))) {
                        $this->editParam($key, $parent_id = 0, $page = 1, $answer->getText());
                    } else {
                        $this->product->{$this->currentKey} = $answer->getText();
                        $this->attributeRequiredMenu();
                    }
                }
            }
        }, $additionalParams);
    }

    public function editVariationSku($variationKey)
    {
        $additionalParams['parse_mode'] = 'html';
        $message                        = '<b>Редактирование параметра:</b> Артикул';
        $this->botController->reply($this->bot, $message, ['parse_mode' => 'html'], 'pause');
        $additionalParams['system_data'] = array('variationKey' => $variationKey);
        $message                         = 'Введите значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            $conversation                                   = $this->bot->getStoredConversation();
            $additionalParameters                           = unserialize($conversation['additionalParameters']);
            $variationKey                                   = $additionalParameters['system_data']['variationKey'];
            $this->data['variations'][$variationKey]['sku'] = $answer->getText();
            $this->editVariation($variationKey);
        }, $additionalParams);
    }

    public function editVariationStatus($variationKey)
    {
        $additionalParams['parse_mode'] = 'html';
        $message                        = '<b>Редактирование параметра:</b> Статус';
        $this->botController->reply($this->bot, $message, ['parse_mode' => 'html'], 'pause');
        $additionalParams['system_data']  = array('variationKey' => $variationKey);
        $btn[]                            = ["text" => "Включено", "callback_data" => "published"];
        $btn[]                            = ["text" => "Выключено", "callback_data" => "unpublished"];
        $inline_keyboard[]                = $btn;
        $additionalParams['reply_markup'] = json_encode([
            "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
        ]);
        $message                          = 'Введите значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            $conversation         = $this->bot->getStoredConversation();
            $additionalParameters = unserialize($conversation['additionalParameters']);
            $variationKey         = $additionalParameters['system_data']['variationKey'];
            if ($answer->isInteractiveMessageReply()) {
                $this->data['variations'][$variationKey]['status'] = $answer->getText();
                $this->editVariation($variationKey);
            } else {
                $this->editVariationStatus($variationKey);
            }
        }, $additionalParams);
    }

    public function editVariationAttribute($attribute, $page = 1, $keyword = false)
    {
        $additionalParams['parse_mode'] = 'html';
        $actionsProductKeyboard         = ['Список вариаций'];
        $message                        = '<b>Редактирование параметра:</b> '.$attribute['title'];
        $this->botController->reply($this->bot, $message, [
            'parse_mode'   => 'html',
            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard, [], 'menu',
                true)
        ], 'pause');
        $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'], $page, [],
            ['output' => 'json'], $keyword);
        $additionalParams['system_data']  = array('attribute' => $attribute, 'page' => $page, 'keyword' => $keyword);
        $message                          = 'Введите значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
        if ($keyword) {
            $message .= "\n".'Поиск: '.$keyword;
        }
        $this->botAsk($message, function (BotManAnswer $answer) {
            if ($this->controllerKeyboard($answer)) {
                return;
            }
            $conversation         = $this->bot->getStoredConversation();
            $additionalParameters = unserialize($conversation['additionalParameters']);
            $attribute            = $additionalParameters['system_data']['attribute'];
            $page                 = $additionalParameters['system_data']['page'];
            $keyword              = $additionalParameters['system_data']['keyword'];
            if ($answer->isInteractiveMessageReply()) {
                $temp = explode('_page_', $answer->getText());
                $temp = explode('_', $temp[0]);
                switch ($temp[0]) {
                    case 'listAttributeValue':
                        $this->editVariationAttribute($attribute, $page, $keyword);
                        break;
                    case 'searchExit':
                        $this->editVariationAttribute($attribute);
                        break;
                    case 'selectAttributeValue':
                        $this->data['variations'][$attribute['variationKey']]['attributes'][$attribute['id']] = $temp[1];
                        $this->editVariation($attribute['variationKey']);
                        break;
                }
            } else {
                $this->editVariationAttribute($attribute, 1, $answer->getText());
            }
        }, $additionalParams);
    }

    public function editAttribute($attribute_key, $page = 1, $keyword = false)
    {
        if (isset($this->categoryAttributes[$attribute_key])) {
            $this->currentKey = $attribute_key;

            $attribute = $this->categoryAttributes[$attribute_key];

            $keyboardActions[] = 'Список параметров';
            if ( ! $attribute['required']) {
                $keyboardActions[] = 'Очистить параметр';
            }
            $message          = '<b>Редактирование параметра:</b> '.$attribute['title'];
            $additionalParams = ['parse_mode' => 'html'];
            if ($attribute['type'] == 'dictionary') {
                $additionalParams['reply_markup'] = $this->botController->setMenuKeyboard($this->bot, $keyboardActions,
                    [], 'menu', true);
            } else {
                $additionalParams['reply_markup'] = $this->botController->setMenuKeyboard($this->bot, $keyboardActions);
            }

            $this->botController->reply($this->bot, $message, $additionalParams, 'subsubmenu');

            if ( ! isset($this->currentOldData['id']) || $this->currentOldData['id'] != $attribute['id']) {
                $this->currentOldData                       = [
                    'id'    => $attribute['id'],
                    'value' => isset($this->data['attributes'][$attribute['id']]) ? $this->data['attributes'][$attribute['id']] : []
                ];
                $this->data['attributes'][$attribute['id']] = $attribute['type'] == 'dictionary' ? [] : null;
            }
            $additionalParams = ['parse_mode' => 'html'];
            $attributeValues  = 'неустановлено';
            if (isset($this->currentOldData['id']) && isset($this->currentOldData['value'])) {
                if ($attribute['type'] == 'dictionary' && is_array($this->currentOldData['value'])) {
                    $titles      = AttributeValue::whereIn('id', $this->currentOldData['value'])->pluck('title');
                    $breadcrumbs = [];
                    if ( ! empty($titles)) {
                        foreach ($titles as $title) {
                            $breadcrumbs[] = $title;
                        }
                    }
                    $attributeValues = implode(', ', $breadcrumbs);
                } elseif (is_array($this->currentOldData['value'])) {
                    $attributeValues = implode(', ', $this->currentOldData['value']);
                }
            }

            $message = '<b>Старое значение:</b> '.$attributeValues."\n";
            if ($attribute['type'] == 'dictionary') {
                if ( ! empty($this->data['attributes'][$attribute['id']])) {
                    if ($attribute['is_collection']) {
                        $titles      = AttributeValue::whereIn('id',
                            $this->data['attributes'][$attribute['id']])->pluck('title');
                        $breadcrumbs = [];
                        if ( ! empty($titles)) {
                            foreach ($titles as $title) {
                                $breadcrumbs[] = $title;
                            }
                        }
                        $message .= '<b>Новое значение:</b> '.implode(', ', $breadcrumbs)."\n";
                        $message .= 'Выберите ещё вариант или вернитесь к списку параметров '.iconv('UCS-4LE', 'UTF-8',
                                pack('V', 0x1F447));
                    }
                } else {
                    $message .= 'Введите новое значение '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                }
                if ($keyword) {
                    $message .= "\n".'Поиск: '.$keyword;
                }
                $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'],
                    $page, [], ['output' => 'json'], $keyword);
            }
            $additionalParams['system_data'] = array(
                'attribute_key' => $attribute_key, 'page' => $page, 'keyword' => $keyword
            );
            $this->botAsk($message, function (BotManAnswer $answer) {
                $attribute = $this->categoryAttributes[$this->currentKey];
                if ($this->controllerKeyboard($answer,
                    $attribute['required'] ? 'attributeRequiredMenu' : 'attributeMenu')) {
                    return;
                }
                if ($answer->getText()) {
                    $conversation         = $this->bot->getStoredConversation();
                    $additionalParameters = unserialize($conversation['additionalParameters']);
                    $page                 = $additionalParameters['system_data']['page'];
                    $keyword              = $additionalParameters['system_data']['keyword'];
                    $attribute_key        = $additionalParameters['system_data']['attribute_key'];
                    if ($answer->isInteractiveMessageReply()) {
                        if (preg_match('/^listAttributeValue\_/', $answer->getText())) {
                            $page = 1;
                            $temp = explode('_page_', str_replace('listAttributeValue_', '', $answer->getText()));
                            if (isset($temp[0])) {
                                $attribute_key = 'attribute_'.intval($temp[0]);
                            }
                            if (isset($temp[1])) {
                                $page = intval($temp[1]);
                            }
                            $this->editAttribute($attribute_key, $page, $keyword);
                        } elseif (preg_match('/^selectAttributeValue\_/', $answer->getText())) {
                            $temp                                         = explode('_', $answer->getText());
                            $attribute_id                                 = array_pop($temp);
                            $attribute                                    = $this->categoryAttributes[$this->currentKey];
                            $this->data['attributes'][$attribute['id']][] = $attribute_id;
                            if ($attribute['is_collection']) {
                                $this->editAttribute($this->currentKey);
                            } else {
                                if ($attribute['required']) {
                                    $this->attributeRequiredMenu();
                                } else {
                                    $this->attributeMenu();
                                }
                            }
                        } elseif ($answer->getText() == 'searchExit') {
                            $this->editAttribute($attribute_key, 1, false);
                        }
                    } else {
                        if ($attribute['type'] == 'dictionary') {
                            $this->editAttribute($attribute_key, 1, $answer->getText());
                        } else {
                            $this->data['attributes'][$attribute['id']][] = $answer->getText();
                            if ($attribute['required']) {
                                $this->attributeRequiredMenu();
                            } else {
                                $this->attributeMenu();
                            }
                        }
                    }
                }
            }, $additionalParams);
        } else {
            $this->mainMenu();
        }
    }

    public function addVariation($attribute, $page = 1, $keyword = false)
    {
        if ( ! empty($attribute)) {
            $actionsProductKeyboard = ['Список вариаций'];
            $additionalParams       = ['parse_mode' => 'html'];
            $message                = '<b>Добавление вариации</b>';
            if ($attribute['type'] == 'dictionary') {
                $this->botController->reply($this->bot, $message, [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard, [],
                        'menu', true)
                ], 'subsubmenu');
            } else {
                $this->botController->reply($this->bot, $message, [
                    'parse_mode'   => 'html',
                    'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                ], 'subsubmenu');
            }
            $additionalParams = ['parse_mode' => 'html'];
            $variationKey     = $this->getCurrentVariationKey();
            $message          = 'Введите "'.$attribute['title'].'" '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
            if ($attribute['type'] == 'dictionary') {
                $message = 'Выберите "'.$attribute['title'].'" '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                if ($keyword) {
                    $message .= "\n".'Поиск: '.$keyword;
                }
                $additionalParams['reply_markup'] = $this->botController->getListAttributeValues($this->bot, $attribute['id'],
                    $page, [], ['output' => 'json'], $keyword);
            }
            $additionalParams['system_data'] = array('attribute' => $attribute, 'page' => $page, 'keyword' => $keyword);
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                $conversation         = $this->bot->getStoredConversation();
                $additionalParameters = unserialize($conversation['additionalParameters']);
                $page                 = $additionalParameters['system_data']['page'];
                $keyword              = $additionalParameters['system_data']['keyword'];
                $attribute            = $additionalParameters['system_data']['attribute'];
                if ($answer->isInteractiveMessageReply()) {
                    $actionsProductKeyboard = ['Список вариаций'];
                    $additionalParams       = ['parse_mode' => 'html'];
                    if (preg_match('/^listAttributeValue\_/', $answer->getText())) {
                        $page = 1;
                        $temp = explode('_page_', $answer->getText());
                        if (isset($temp[1])) {
                            $page = intval($temp[1]);
                        }
                        $this->addVariation(reset($this->variationAttributes), $page, $keyword);
                    } elseif (preg_match('/^selectAttributeValue\_/', $answer->getText())) {
                        $variationKey                                                            = $this->getCurrentVariationKey();
                        $temp                                                                    = explode('_',
                            $answer->getText());
                        $attribute_id                                                            = array_pop($temp);
                        $attribute                                                               = reset($this->variationAttributes);
                        $this->data['variations'][$variationKey]['attributes'][$attribute['id']] = $attribute_id;
                        $message                                                                 = $this->getMessageVariation($variationKey);
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
                        ], 'pause');
                        $message = 'Введите "Артикул" вариации '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                        $this->botAsk($message, function (BotManAnswer $answer) {
                            if ($answer->getText()) {
                                $actionsProductKeyboard = ['Список вариаций'];
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
                                ], 'pause');
                                $question = BotManQuestion::create('Выберите статус вариации товара '.iconv('UCS-4LE',
                                        'UTF-8', pack('V', 0x1F447)))->callbackId('product_variation_status');
                                $question->addButtons([
                                    Button::create('Включено')->value('published'),
                                    Button::create('Выключено')->value('unpublished')
                                ]);
                                $this->botAsk($question, function (BotManAnswer $answer) {
                                    if ($answer->getText()) {
                                        $additionalParams       = ['parse_mode' => 'html'];
                                        $actionsProductKeyboard = ['Список вариаций'];
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
                                        ], 'pause');
                                        $this->botAskForImages(iconv('UCS-4LE', 'UTF-8',
                                                pack('V', 0x2199)).' Загрузите сжатые изображения вариации товара',
                                            function ($images) {
                                                if ( ! empty($images)) {
                                                    $this->botController->setCache($this->bot->getMessage()->getPayload()['message_id']);
                                                    $actionsProductKeyboard = ['Список вариаций'];
                                                    $variationKey           = $this->getCurrentVariationKey();
                                                    foreach ($images as $image) {
                                                        $this->data['variations'][$variationKey]['images'][] = $image->getUrl();
                                                    }
                                                    $this->data['variations'][$variationKey]['id'] = Str::uuid();
                                                    $this->variationMenu();
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
                    } elseif ($answer->getText() == 'searchExit') {
                        $this->addVariation(reset($this->variationAttributes), 1, false);
                    }
                } else {
                    if ($attribute['type'] == 'dictionary') {
                        $this->addVariation($attribute, 1, $answer->getText());
                    }
                }
            }, $additionalParams);
        }
    }

    public function editVariation($variationKey)
    {
        $variation = false;
        if (isset($this->data['variations'][$variationKey])) {
            $variation = $this->data['variations'][$variationKey];
        }
        if ( ! empty($variation)) {
            $additionalParams = ['parse_mode' => 'html'];
            $inline_keyboard  = [];
            $btn              = [];
            $message          = '<b>Редактирование товара:</b> '.$this->product->title;
            $this->botController->reply($this->bot, $message, $additionalParams, 'submenu');
            $variationAttributes = [];
            foreach ($variation['attributes'] as $attribute_id => $variationAttribute) {
                $variationAttributes[] = $variationAttribute;
                $btn                   = [];
                $btn[]                 = [
                    "text"          => Attribute::where('id',
                            $attribute_id)->value('title').': '.AttributeValue::where('id',
                            $variationAttribute)->value('title'),
                    "callback_data" => "editVariationAttribute_".$variationKey.'_'.$attribute_id
                ];
                $inline_keyboard[]     = $btn;
            }
            $btn               = [];
            $btn[]             = [
                "text" => "Артикул: ".$variation['sku'], "callback_data" => "editVariationSku_".$variationKey
            ];
            $inline_keyboard[] = $btn;
            $btn               = [];
            $btn[]             = [
                "text"          => "Статус: ".($variation['status'] == 'published' ? 'включено' : 'выключено'),
                "callback_data" => "editVariationStatus_".$variationKey
            ];
            $inline_keyboard[] = $btn;
            $btn               = [];
            $btn[]             = [
                "text"          => "Изображения: ".(isset($variation['images']) && ! empty($variation['images']) ? count($variation['images']).' фото' : 'неустановлено'),
                "callback_data" => "editVariationImages_".$variationKey
            ];
            $inline_keyboard[] = $btn;
            $btn               = [];
            $btn[]             = [
                "text" => "Модификации", "callback_data" => "editVariationModification_".$variationKey
            ];
            $inline_keyboard[] = $btn;
            $titles            = AttributeValue::whereIn('id', $variationAttributes)->pluck('title');
            $breadcrumbs       = [];
            if ( ! empty($titles)) {
                foreach ($titles as $title) {
                    $breadcrumbs[] = $title;
                }
            }
            $actionsProductKeyboard = ['Список вариаций'];
            $message                = '<b>Редактирование вариации:</b> '.$variation['sku'].'/'.implode('/',
                    $breadcrumbs);
            $this->botController->reply($this->bot, $message, [
                'parse_mode'   => 'html',
                'reply_markup' => $this->botController->setMenuKeyboard($this->bot, $actionsProductKeyboard)
            ], 'subsubmenu');
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $this->botAsk('Выберите пункт для редактирования '.iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447)),
                function (BotManAnswer $answer) {
                    if ($this->controllerKeyboard($answer)) {
                        return;
                    }
                    if ($answer->isInteractiveMessageReply()) {
                        $temp = explode('_', $answer->getText());
                        switch ($temp[0]) {
                            case 'editVariationAttribute':
                                if (isset($temp[1]) && isset($temp[2])) {
                                    $attribute                 = Attribute::where('id', $temp[2])->first()->toArray();
                                    $attribute['variationKey'] = $temp[1];
                                    $this->editVariationAttribute($attribute);
                                }
                                break;
                            case 'editVariationSku':
                                $this->editVariationSku($temp[1]);
                                break;
                            case 'editVariationStatus':
                                $this->editVariationStatus($temp[1]);
                                break;
                            case 'editVariationModification':
                                $this->modificationMenu($temp[1]);
                                break;
                        }
                    }
                }, $additionalParams);
        }
    }

    public function deleteVariation($variationKey)
    {
        $variation = false;
        if (isset($this->data['variations'][$variationKey])) {
            $variation = $this->data['variations'][$variationKey];
        }
        if ( ! empty($variation)) {
            $additionalParams = ['parse_mode' => 'html'];
            $btn              = [];
            $message          = '<b>Редактирование товара:</b> '.$this->product->title;
            $this->botController->reply($this->bot, $message, $additionalParams, 'submenu');
            $variationAttributes = [];
            foreach ($variation['attributes'] as $variationAttribute) {
                $variationAttributes[] = $variationAttribute;
            }
            $titles      = AttributeValue::whereIn('id', $variationAttributes)->pluck('title');
            $breadcrumbs = [];
            if ( ! empty($titles)) {
                foreach ($titles as $title) {
                    $breadcrumbs[] = $title;
                }
            }
            $message = '<b>Вариация:</b> '.$variation['sku'].'/'.implode('/', $breadcrumbs);
            $this->botController->reply($this->bot, $message, $additionalParams, 'subsubmenu');
            $btn[]                            = ["text" => "Да", "callback_data" => "yes_".$variationKey];
            $btn[]                            = ["text" => "Нет", "callback_data" => "no_".$variationKey];
            $inline_keyboard[]                = $btn;
            $additionalParams['reply_markup'] = json_encode([
                "inline_keyboard" => $inline_keyboard, "resize_keyboard" => true
            ]);
            $message                          = '';
            if (count($this->data['variations']) == 1) {
                $message .= '<b>Внимание: удаление единственной вариации, приведёт к удалению всего товара!</b>'."\n";
            }
            $message .= 'Вы, действительно хотите удалить вариацию?';
            $this->botAsk($message, function (BotManAnswer $answer) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                if ($answer->getText()) {
                    if ($answer->isInteractiveMessageReply()) {
                        if (preg_match('/^yes\_/', $answer->getText())) {
                            $variationKey = intval(str_replace('yes_', '', $answer->getText()));
                            if (isset($this->data['variations'][$variationKey])) {
                                unset($this->data['variations'][$variationKey]);
                            }
                            if ( ! empty($this->data['variations'])) {
                                $data                     = $this->data['variations'];
                                $this->data['variations'] = [];
                                foreach ($data as $v) {
                                    $this->data['variations'][] = $v;
                                }
                            }
                            $this->variationMenu();
                        } else {
                            $this->variationMenu();
                        }
                    } else {
                        $this->variationMenu();
                    }
                }
            }, $additionalParams);
        }
    }

    public function editProductPrice()
    {
        $price_list   = PriceList::where('user_id', $this->botController->user_id)->active()->orderBy('created_at',
            'DESC')->first('id');
        $marketPlaces = getActiveMarketPlaces();
        foreach ($marketPlaces as $key => $marketPlace) {
            $integration = Integration::where('user_id', $this->botController->user_id)->active()->where([
                'type' => $marketPlace['name']
            ])->first();

            if ( ! $integration) {
                unset($marketPlaces[$key]);
                continue;
            }

            $warehouses = Warehouse::where([
                'user_id' => $this->botController->user_id, 'marketplace' => $marketPlace['name']
            ])
                ->get()->toArray();

            $marketPlaces[$key]['warehouses']  = $warehouses;
            $marketPlaces[$key]['integration'] = $integration;
        }
        Log::info($marketPlaces);
    }

    public function editProductQuantity()
    {
        $price_list   = PriceList::where('user_id', $this->botController->user_id)->active()->orderBy('created_at',
            'DESC')->first('id');
        $marketPlaces = getActiveMarketPlaces();
        foreach ($marketPlaces as $key => $marketPlace) {
            $integration = Integration::where('user_id', $this->botController->user_id)->active()->where([
                'type' => $marketPlace['name']
            ])->first();

            if ( ! $integration) {
                unset($marketPlaces[$key]);
                continue;
            }

            $warehouses = Warehouse::where([
                'user_id' => $this->botController->user_id, 'marketplace' => $marketPlace['name']
            ])
                ->get()->toArray();

            $marketPlaces[$key]['warehouses']  = $warehouses;
            $marketPlaces[$key]['integration'] = $integration;
        }
        Log::info($marketPlaces);
    }

    public function run()
    {
        $this->mainMenu();
    }

    public function saveProduct()
    {
        $this->product->meta_title       = $this->product->title;
        $this->product->meta_description = $this->product->description;
        $this->product->meta_keywords    = $this->product->title;
        if (isset($this->product->description)) {
            $this->data['description'] = $this->product->description;
        }
        foreach ($this->data['attributes'] as $attribute_id => $value) {
            if (is_array($value)) {
                $this->data['dictionaries'][]            = $attribute_id;
                $this->data['attributes'][$attribute_id] = implode(';', $value);
            }
        }
        foreach ($this->data['variations'] as $key => $variation) {
            if (isset($variation['sku'])) {
                if ( ! isset($this->data['variations'][$key]['id'])) {
                    $this->data['variations'][$key]['id'] = Str::uuid();
                }
                if (isset($variation['items'])) {
                    foreach ($variation['items'] as $k => $item) {
                        $this->data['variations'][$key]['items'][$k]['id'] = false;
                    }
                }
            } else {
                unset($this->data['variations'][$key]);
            }
        }
        $this->productService->save($this->data, $this->product);
        $this->botController->listProduct($this->bot);

        return;
    }

    public function init(BotController $botController, $product_id)
    {
        $this->botController = $botController;
        $this->product       = Product::where([
            ['user_id', $this->botController->user_id], ['id', $product_id]
        ])->first();
        if ($this->product) {
            $data = $this->productService->getProduct($this->product);
            if ($data['success']) {
                if (isset($data['product']['attributes']) && ! empty($data['product']['attributes'])) {
                    foreach ($data['product']['attributes'] as $key => $attribute) {
                        if (is_array($attribute)) {
                            foreach ($attribute as $attr) {
                                if ($attr['id']) {
                                    $this->data['attributes'][$key][] = $attr['id'];
                                } else {
                                    $this->data['attributes'][$key][] = $attr['value'];
                                }
                            }
                        }
                    }
                }
                if (isset($data['variationAttributes']) && ! empty($data['variationAttributes'])) {
                    $this->variationAttributes = $data['variationAttributes'];
                }
                if (isset($data['modificationAttributes']) && ! empty($data['modificationAttributes'])) {
                    $this->modificationAttributes = $data['modificationAttributes'];
                }
                if (isset($data['product']['variations']) && ! empty($data['product']['variations'])) {
                    foreach ($data['product']['variations'] as $key => $variation) {
                        $this->data['variations'][$key]['id']     = $variation->id;
                        $this->data['variations'][$key]['sku']    = $variation->vendor_code;
                        $this->data['variations'][$key]['status'] = $variation->status;
                        if (isset($variation->images) && ! empty($variation->images)) {
                            foreach ($variation->images as $image) {
                                $this->data['variations'][$key]['images'][] = $image['url'];
                            }
                        }
                        if (isset($variation->attributes)) {
                            foreach ($variation->attributes as $k => $variationAttribute) {
                                $variationAttributeValue                          = array_shift($variationAttribute);
                                $this->data['variations'][$key]['attributes'][$k] = $variationAttributeValue['id'];
                            }
                        }
                        if (isset($variation->items)) {
                            /**
                             * @var  $k
                             * @var ProductVariationItemDTO  $item
                             */
                            foreach ($variation->items as $k => $item) {
                                $this->data['variations'][$key]['items'][$k]['id']      = $item->id;
                                $this->data['variations'][$key]['items'][$k]['barcode'] = $item->barcode;
                                if ( ! empty($item->settings)) {
                                    foreach ($item->settings as $s_k => $setting) {
                                        $this->data['variations'][$key]['items'][$k][$s_k] = $setting;
                                    }
                                }
                                foreach ($item->attributes as $attribute_id => $attribute) {
                                    $attr = array_shift($attribute);
                                    if ( ! empty($attr)) {
                                        if ( ! empty($attr['id'])) {
                                            $this->data['variations'][$key]['items'][$k]['attributes'][$attribute_id] = $attr['id'];
                                        } else {
                                            $this->data['variations'][$key]['items'][$k]['attributes'][$attribute_id] = $attr['value'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            if (isset($this->product->compositions)) {
                $this->compositions = $this->product->compositions;
            }
        }
    }

    public function getCurrentVariationKey()
    {
        $variationKey = 0;
        if (isset($this->data['variations'])) {
            foreach ($this->data['variations'] as $variation) {
                if (isset($variation['id'])) {
                    $variationKey++;
                }
            }
        }

        return $variationKey;
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
        }

        return $message;
    }

    public function getCategoryAttributes()
    {
        $category              = Category::findOrFail($this->product->category_id);
        $system_category       = $category->system_category;
        $categoryAttributes    = $this->categoryService->getAttributes($category);
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
        $categoryAttributes = [];
        $categoryAttributes = array_merge($requiredAttributes, $variationAttributes, $notRequiredAttributes);
        foreach ($categoryAttributes as $categoryAttribute) {
            $this->categoryAttributes['attribute_'.$categoryAttribute['id']] = $categoryAttribute;
        }
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
        $conversation         = $this->bot->getStoredConversation();
        $additionalParameters = unserialize($conversation['additionalParameters']);
        $this->botController->setCache($answer->getMessage()->getPayload()['message_id'], 'message');
        $trigger = false;
        switch ($answer->getText()) {
            case 'Меню редактирования':
                $trigger = true;
                $this->mainMenu();
                break;
            case 'Список параметров':
                $trigger = true;
                if ($step) {
                    switch ($step) {
                        case 'attributeRequiredMenu':
                            if (isset($this->product->compositions)) {
                                $this->compositions = $this->product->compositions;
                            }
                            $this->attributeRequiredMenu();
                            break;
                        case 'attributeMenu':
                            $this->attributeMenu();
                            break;
                    }
                } else {
                    $this->mainMenu();
                }
                break;
            case 'Список товаров':
                $trigger = true;
                $this->botController->listProduct($this->bot);
                break;
            case 'Выйти без сохранения':
                $trigger = true;
                $this->botController->listProduct($this->bot);
                break;
            case 'Список вариаций':
                $trigger = true;
                $this->variationMenu();
                break;
            case 'Список модификаций':
                $trigger         = true;
                $variationKey    = $additionalParameters['system_data']['variationKey'];
                $modificationKey = $additionalParameters['system_data']['modificationKey'];
                $this->modificationMenu($variationKey, $modificationKey);
                break;
            case 'Очистить параметр':
                $trigger = true;
                if ($step) {
                    switch ($step) {
                        case 'attributeRequiredMenu':
                            $this->product->{$this->currentKey} = null;
                            $this->attributeRequiredMenu();
                            break;
                        case 'attributeMenu':
                            $attribute = $this->categoryAttributes[$this->currentKey];
                            if ($attribute['type'] == 'dictionary') {
                                $this->data['attributes'][$attribute['id']] = [];
                            } else {
                                $this->data['attributes'][$attribute['id']] = null;
                            }
                            $this->attributeMenu();
                            break;
                    }
                } else {
                    $this->mainMenu();
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

