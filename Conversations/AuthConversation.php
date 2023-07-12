<?php

namespace App\Conversations;

use App\Http\Controllers\BotController;
use App\Models\TelegramUser;
use App\Models\User;
use BotMan\BotMan\Messages\Conversations\Conversation;
use BotMan\BotMan\Messages\Incoming\Answer as BotManAnswer;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question as BotManQuestion;

class AuthConversation extends Conversation
{
    protected $email;
    protected $password;
    protected $user_id;
    protected BotController $botController;

    function init(BotController $botController)
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

                    if ( ! empty($user) && strtotime($user->email_verified_at) < time()) {
                        $this->user_id = $user->id;
                        $user          = TelegramUser::where([
                            'user_id' => $this->user_id, 'telegram_id' => $this->botController->user_telegram_id
                        ])->first();

                        if ( ! empty($user)) {
                            if ($user->status) {
                                TelegramUser::where([
                                    'telegram_id' => $this->botController->user_telegram_id, 'status' => 1
                                ])->update(['active' => 0]);

                                TelegramUser::where([
                                    'user_id'     => $this->user_id,
                                    'telegram_id' => $this->botController->user_telegram_id,
                                    'status'      => 1
                                ])->update(['active' => 1]);

                                $this->botController->user_id = $this->user_id;
                                $this->botController->mainMenu($this->bot);

                                return;
                            } else {
                                $this->permissionValidate();
                            }
                        } else {
                            TelegramUser::create([
                                'user_id'     => $this->user_id,
                                'telegram_id' => $this->bot->getUser()->getId(),
                                'username'    => $this->bot->getUser()->getUserName(),
                                'status'      => 0,
                                'active'      => 0
                            ]);

                            return $this->permissionValidate();
                        }
                    } else {
                        $message = 'E-mail: '.$this->email.' не зарегистрирован в системе!'."\n";
                        $message .= 'Пройдите процедуру регистрации';
                        $message .= iconv('UCS-4LE', 'UTF-8', pack('V', 0x1F447));
                        $this->botController->reply($this->bot, $message, [
                            'parse_mode'   => 'html',
                            'reply_markup' => $this->botController->setMenuKeyboard($this->bot,
                                ['Авторизация', 'Регистрация'])
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
                ['Вход в систему', 'Авторизация'])
        ]);
    }

    public function permissionValidate()
    {
        $question = BotManQuestion::create("Подтвердите в личном кабинете разрешение на доступ бота с телеграм аккаунта @".$this->bot->getUser()->getUserName());
        $question->addButtons([
            Button::create('Права подтверждены')->value('permissionValidate')
        ]);
        $this->botController->getAuthRegisterKeyboard($this->bot);
        $this->ask($question, function (BotManAnswer $answer) {
            if ($answer->isInteractiveMessageReply()) {
                switch ($answer->getValue()) {
                    case 'permissionValidate':
                        $telegramUser = TelegramUser::where([
                            'user_id' => $this->user_id, 'telegram_id' => $this->bot->getUser()->getId(), 'status' => 1
                        ])->first();

                        if ( ! empty($telegramUser)) {
                            TelegramUser::where([
                                'telegram_id' => $this->bot->getUser()->getId(), 'status' => 1
                            ])->update(['active' => 0]);

                            TelegramUser::where([
                                'user_id'     => $this->user_id,
                                'telegram_id' => $this->bot->getUser()->getId(),
                                'status'      => 1
                            ])->update(['active' => 1]);
                            
                            $this->botController->mainMenu($this->bot);
                            return;
                        }
                        break;
                }
                $this->permissionValidate();
            }
        }, ['parse_mode' => 'html']);
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
                $this->botController->signup($this->bot);
                break;
            case 'Авторизация':
                $trigger = true;
                $this->askEmail();
                break;
        }

        return $trigger;
    }
}