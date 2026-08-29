<?php

namespace App\Http\Controllers;

use App\Actions\DeleteUserAccount;
use App\Http\Requests\DeleteAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class AccountDeletionController extends Controller
{
    public function destroy(
        DeleteAccountRequest $request,
        DeleteUserAccount $deleteUserAccount,
    ): RedirectResponse {
        $user = $request->user();
        $deleteUserAccount->handle($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', __('Your account and application data were deleted.'));
    }
}
