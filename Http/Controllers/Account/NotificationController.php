<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function markAllAsRead(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            foreach ($user->unreadNotifications as $notification) {
                $notification->markAsRead();
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
