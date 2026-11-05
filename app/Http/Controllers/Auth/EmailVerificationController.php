<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\AccountTokenRequest;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\IdentityPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class EmailVerificationController extends Controller
{
    public function show(Request $request, IdentityPageService $pages): View
    {
        try {
            $data = $pages->verification($request->query('token'), $request->user());
            if (!$request->user() && $data['verificationToken']) {
                $request->session()->put('pending_verification', $data['verificationToken']);
            }

            return view('auth.verify-email', $data);
        } catch (ValidationException) {
            return view('auth.link-invalid');
        }
    }

    public function store(AccountTokenRequest $request, EmailVerificationService $verification): RedirectResponse
    {
        $verification->verify($request->user(), $request->validated('token'));
        $request->session()->forget('pending_verification');

        return redirect()->route('organizations.index')->with('status', 'Correo verificado.');
    }

    public function send(Request $request, EmailVerificationService $verification): RedirectResponse
    {
        $verification->send($request->user());

        return back()->with('status', 'Se solicitó el envío del enlace de verificación.');
    }
}
