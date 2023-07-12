<?php

namespace App\Http\Controllers\Account;

use App\DataTables\Account\RelatedAccountsDataTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\RelatedAccount;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

use function auth;
use function response;

class RelatedAccountController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(RelatedAccountsDataTable $dataTable)
    {
        return $dataTable->render('pages.account.related-accounts.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Application|Factory|View
     */
    public function create()
    {
        return view('pages.account.related-accounts.login');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param LoginRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(LoginRequest $request)
    {
        $validated = $request->validated();

        $user = User::whereEmail($validated['email'])->first();
        $user = password_verify($validated['password'], $user?->getAuthPassword()) ? $user : false;

        if (empty($user)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        if (auth()->id() !== $user->id) {
            RelatedAccount::firstOrCreate(['user_id' => auth()->id(), 'related_user_id' => $user->id]);
            RelatedAccount::firstOrCreate(['related_user_id' => auth()->id(), 'user_id' => $user->id]);
        }

        return response()->json([
            'success'  => true,
            'redirect' => route('related-accounts.index')
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param RelatedAccount $relatedAccount
     *
     * @return void
     */
    public function show(RelatedAccount $relatedAccount)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param RelatedAccount $relatedAccount
     *
     * @return void
     */
    public function edit(RelatedAccount $relatedAccount)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param RelatedAccount $relatedAccount
     *
     * @return void
     */
    public function update(Request $request, RelatedAccount $relatedAccount)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param RelatedAccount $relatedAccount
     *
     * @return Response
     */
    public function destroy(RelatedAccount $relatedAccount)
    {
        if ($relatedAccount->user_id === auth()->id()) {
            RelatedAccount::whereUserId($relatedAccount->related_user_id)->whereRelatedUserId(auth()->id())->delete();
            $relatedAccount->delete();
        }

        return response()->json([
            'success'  => true,
            'redirect' => route('related-accounts.index')
        ]);
    }

    public function loginAsUser(int $user_id)
    {
        $related = RelatedAccount::whereUserId(auth()->id())->whereRelatedUserId($user_id)->firstOrFail();

        Auth::login($related->related_user);

        return redirect(RouteServiceProvider::HOME);
    }
}
