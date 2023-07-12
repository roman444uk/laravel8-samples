<?php

namespace App\Http\Controllers\Account;

use App\Contracts\PhoneCaller;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SettingsEmailRequest;
use App\Http\Requests\Account\SettingsInfoRequest;
use App\Http\Requests\Account\SettingsPasswordRequest;
use App\Http\Requests\Account\SettingsTelegramRequest;
use App\Http\Requests\PhoneChangeRequest;
use App\Models\Tax;
use App\Models\TelegramUser;
use App\Models\UserInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function index()
    {
        $info          = auth()->user()->info;
        $telegramUsers = auth()->user()->telegram_users()->get();

        $price_list = getDefaultPriceList(auth()->user());
        $taxes      = Tax::active()->get();

        // get the default inner page
        return view('pages.account.settings.settings', compact('info', 'price_list', 'taxes', 'telegramUsers'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $user
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(SettingsInfoRequest $request)
    {
        // save user name
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
        ]);

        auth()->user()->update($validated);

        $info = $this->getInfo();

        foreach ($request->only(array_keys($request->rules())) as $key => $value) {
            if (is_array($value)) {
                $value = serialize($value);
            }
            $info->$key = $value;
        }

        // include to save avatar
        if ($avatar = $this->upload()) {
            $info->avatar = $avatar;

            if ($info->isDirty('avatar')) {
                Storage::delete($info->getOriginal('avatar'));
            }
        }

        if ($request->boolean('avatar_remove')) {
            Storage::delete($info->avatar);
            $info->avatar = null;
        }

        $info->save();

        // tax
        $price_list = getDefaultPriceList(auth()->user());
        if ( ! empty($price_list)) {
            $validated = $request->validate([
                'tax_id' => 'integer',
            ]);
            $price_list->fill($validated);
            $price_list->save();
        }


        return redirect()->intended('/account/settings');
    }

    /**
     * Function for upload avatar image
     *
     * @param string $folder
     * @param string $key
     * @param string $validation
     *
     * @return false|string|null
     */
    public function upload(
        $folder = 'avatars',
        $key = 'avatar',
        $validation = 'image|mimes:jpeg,png,jpg,gif,svg|max:2048|sometimes'
    ) {
        request()->validate([$key => $validation]);

        $file = null;
        if (request()->hasFile($key)) {
            $file = Storage::disk('public')->putFile(generateUploadPath($folder,
                request()->file($key)->getClientOriginalName()), request()->file($key), 'public');
        }

        return $file;
    }

    /**
     * Function to accept request for change email
     *
     * @param SettingsEmailRequest $request
     */
    public function changeEmail(SettingsEmailRequest $request)
    {
        auth()->user()->forceFill(['email' => $request->input('email'), 'email_verified_at' => null])->save();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->intended('account/settings');
    }

    /**
     * Function to accept request for change password
     *
     * @param SettingsPasswordRequest $request
     */
    public function changePassword(SettingsPasswordRequest $request)
    {
        auth()->user()->update(['password' => Hash::make($request->input('password'))]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->intended('account/settings');
    }

    public function change2fa(Request $request)
    {
        $user = auth()->user();
        if ( ! $user->hasVerifiedPhone()) {
            throw ValidationException::withMessages(
                [
                    'phone'       => __('errors.phone_not_verified'),
                    'verify_link' => __('buttons.verify_phone_link', ['link' => route('phone-verification.index')]),
                ]);
        }
        $info = UserInfo::whereBelongsTo($user)->first();

        if ($info === null) {
            // create new model
            $info = new UserInfo();
        }

        // attach this info to the current user
        $info->user()->associate($user);

        if (isset($info->settings)) {
            $info->settings['enabled2fa'] = $request->input('enable2fa', false);
        } else {
            $info->settings = ['enabled2fa' => $request->input('enable2fa', false)];
        }
        $info->save();

        $user->set2FAValid();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->intended('account/settings');
    }

    public function changePhone(PhoneChangeRequest $request, PhoneCaller $phoneCaller)
    {
        if ($request->get('action') === 'requestCode') {
            try {
                return $request->sendCall($phoneCaller);
            } catch (ValidationException $e) {
                $response = [
                    'message' => __('Error'),
                    'errors'  => $e->errors(),
                ];

                return response($response, $e->status);
            }
        } else {
            try {
                return $request->changePhone();
            } catch (ValidationException $e) {
                $response = [
                    'message' => __('Error'),
                    'errors'  => $e->errors(),
                ];

                return response($response, $e->status);
            }
        }
    }

    /**
     * Настройка отображения полей
     *
     * @param Request $request
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function dataTableColumns(Request $request)
    {
        try {
            $request->validate([
                'table'   => 'required|string',
                'columns' => 'array',
            ]);

            $info = $this->getInfo();

            $info->settings                                             = $info->settings ?? [];
            $info->settings['dataTableColumns']                         = $info->settings['dataTableColumns'] ?? [];
            $info->settings['dataTableColumns'][$request->get('table')] = $request->get('columns');

            $info->save();

            return response()->json(['success' => true, 'redirect' => route('products.index')]);
        } catch (ValidationException $e) {
            $response = [
                'message' => __('Error'),
                'errors'  => $e->errors(),
            ];

            return response($response, $e->status);
        }
    }

    /**
     * @return UserInfo
     */
    private function getInfo()
    {
        // save on user info
        $info = UserInfo::where('user_id', auth()->user()->id)->first();

        if ($info === null) {
            // create new model
            $info = new UserInfo();
        }

        // attach this info to the current user
        $info->user()->associate(auth()->user());

        return $info;
    }

    /**
     * @param SettingsTelegramRequest $request
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function changeTelegramStatus(SettingsTelegramRequest $request)
    {
        $validated    = $request->validated();
        $telegramUser = TelegramUser::where([
            'user_id' => $validated['user_id'], 'telegram_id' => $validated['telegram_id']
        ])->first();

        if ( ! empty($telegramUser)) {
            $telegramUser->update(['status' => $validated['status']]);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->intended('account/settings');
    }
}
