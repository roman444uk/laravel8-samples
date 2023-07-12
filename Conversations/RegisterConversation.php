<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use App\Models\User;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;

class RegisterConversation extends Conversation
{
    protected $email;
    protected $password;
    protected $user_id;
    protected $first_name;
    protected BotController $botController;

    public function init(BotController $botController)
    {
        $this->botController = $botController;
    }

    public function askEmail()
    {
        $question = BotManQuestion::create("Введите e-mail:");
        $this->botAsk($question, function (BotManAnswer $answer) {
            if ($answer->getText()) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                if (filter_var($answer->getText(), FILTER_VALIDATE_EMAIL)) {
                    $this->email = $answer->getText();
                    $user        = User::where('email', $this->email)->first();
                    if (empty($user)) {
                        $this->askPhone();
                    } else {
                        $message = 'E-mail: '.$this->email.' уже зарегистрирован в системе!'."\n";
                        $message .= 'Пройдите процедуру авторизации';
                        $message .= iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                ['Авторизация', 'Регистрация'], [], 'menu')
                        ]);
                    }

                    return;
                }
                $this->botController->reply($this->bot, '<b>Некорректный e-mail:</b> '.$answer->getText(),
                    ['parse_mode' => 'html']);
                sleep(2);
                $this->askEmail();
            }
        }, [
            'parse_mode' => 'html', $this->botController->setMenuKeyboard($this->bot, ['Авторизация', 'Регистрация'],
                ['Вход в систему', 'Регистрация'])
        ]);
    }

    public function askPhone()
    {
        $question = BotManQuestion::create("Введите номер телефона:");
        $this->botAsk($question, function (BotManAnswer $answer) {
            if ($answer->getText()) {
                if ($this->controllerKeyboard($answer)) {
                    return;
                }
                $phone      = str_replace(array('(', ')', ' ', '-', '+'), '', trim($answer->getText()));
                $phoneValid = false;
                if (preg_match('/^[0-9]+/', $phone)) {
                    $phoneValid = true;
                }
                if ($phoneValid) {
                    $this->phone = $phone;
                    $user        = User::where('phone', $this->phone)->orWhere('phone',
                        preg_replace('/^7/', '8', $this->phone))->orWhere('phone',
                        preg_replace('/^8/', '7', $this->phone))->orWhere('phone',
                        preg_replace('/^7/', '+7', $this->phone))->first();

                    if (empty($user)) {
                        if ($this->bot->getUser()->getFirstName()) {
                            $this->first_name = $this->bot->getUser()->getFirstName().(! empty($this->bot->getUser()->getLastName()) ? $this->bot->getUser()->getLastName() : '');
                        }
                        if ( ! $this->first_name) {
                            $this->first_name = $this->bot->getUser()->getUsername();
                        }
                        $this->password = $this->bot->getUser()->getId();

                        $user = User::create([
                            'first_name' => $this->first_name,
                            'email'      => $this->email,
                            'phone'      => $this->phone,
                            'password'   => Hash::make($this->password)
                        ]);

                        event(new Registered($user));

                        $this->botController->reply($this->bot,
                            "Вы успешно зарегистрированы!\nНа ваш e-mail направлена ссылка для подтверждения регистрации\nПодтвердите регистрацию и пройдите процедуру авторизации!",
                            [
                                'parse_mode'   => 'html',
                                'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                    ['Авторизация', 'Регистрация'])
                            ]);
                    } else {
                        $message = 'Номер телефона: '.$answer->getText().' уже зарегистрирован в системе!'."\n";
                        $message .= 'Пройдите процедуру авторизации';
                        $message .= iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                ['Авторизация', 'Регистрация'])
                        ]);
                    }

                    return;
                }
                $this->botController->reply($this->bot, '<b>Некорректный номер телефона:</b> '.$answer->getText(),
                    ['parse_mode' => 'html']);
                sleep(2);
                $this->askEmail();
            }
        }, [
            'parse_mode' => 'html', $this->botController->setMenuKeyboard($this->bot, ['Авторизация', 'Регистрация'],
                ['Вход в систему', 'Регистрация'])
        ]);
    }

    public function permissionValidate()
    {
    }

    public function askPassword()
    {
        $question = BotManQuestion::create("Введите пароль:");
        $this->ask($question, function (BotManAnswer $answer) {
            $this->password = $answer->getText();
            $checkUser      = true; //проверить наличие пользователя по логину и паролю
            if ($checkUser) {
                $chat_id      = $this->bot->getMessage()->getPayload()['chat']['id'];
                $btn[]        = ["text" => "Ваши категории", "callback_data" => '/categories'];
                $btn[]        = ["text" => "Ваши товары", "callback_data" => '/products'];
                $reply_markup = json_encode(["keyboard" => [$btn], "resize_keyboard" => true]);
                $this->bot->sendRequest('sendMessage', [
                    'chat_id'      => $chat_id, 'parse_mode' => 'html', 'text' => 'Выберите пункт меню',
                    'reply_markup' => $reply_markup
                ]);
            } else {
                $question = BotManQuestion::create("Пользователь не найден, продолжим?");
                $question->addButtons([
                    Button::create('Авторизация')->value('login'),
                    Button::create('Регистрация')->value('signup')
                ]);
                $this->ask($question, function (BotManAnswer $answer) {
                    if ($answer->isInteractiveMessageReply()) {
                        switch ($answer->getValue()) {
                            case 'login':
                                $this->askEmail();
                                break;
                            case 'signup':
                                $this->say('Для регистрации перейдите по ссылке https://'.$_SERVER['SERVER_NAME'].'/register');
                                $question = BotManQuestion::create("После регистрации, авторизуйтесь в боте с логином и паролем указанным при регистрации на сайте");
                                $question->addButtons([
                                    Button::create('Авторизация')->value('login')
                                ]);
                                $this->ask($question, function (BotManAnswer $answer) {
                                    if ($answer->isInteractiveMessageReply()) {
                                        $this->askEmail();
                                    }
                                });
                                break;
                        }
                    }
                });
            }
        });
    }


    public function run()
    {
        $this->askEmail();
    }

    public function botAsk($question, $next, $additionalParameters = [], $type = 'message')
    {
        $this->botController->reply($this->bot, $question, $additionalParameters);
        $this->bot->storeConversation($this, $next, $question, $additionalParameters);
        $this->botController->setCache($this->bot->getMessage()->getPayload()['message_id'], $type);

        return $this;
    }

    public function controllerKeyboard($answer, $step = false)
    {
        $conversation         = $this->bot->getStoredConversation();
        $additionalParameters = unserialize($conversation['additionalParameters']);
        $this->botController->setCache($answer->getMessage()->getPayload()['message_id'], 'message');
        $trigger = false;
        switch ($answer->getText()) {
            case 'Регистрация':
                $trigger = true;
                $this->askEmail();
                break;
            case 'Авторизация':
                $trigger = true;
                $this->botController->login($this->bot);
                break;
        }

        return $trigger;
    }
}