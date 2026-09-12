<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\InvitationAcceptRequest;
use App\Http\Requests\InvitationCreateRequest;
use App\Services\IdentityPageService;
use App\Services\InvitationService;
use App\Services\MembershipPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function create(Request $request, MembershipPageService $pages): View
    {
        return view('invitations.create', $pages->invitationForm($request->user()));
    }

    public function store(InvitationCreateRequest $request, InvitationService $invitations): RedirectResponse
    {
        $invitations->create($request->user(), $request->validated());

        return redirect()->route('memberships.index')->with('status', 'Invitación enviada al correo indicado.');
    }

    public function show(Request $request, IdentityPageService $pages): View
    {
        try {
            $data = $pages->invitation($request->query('token'), $request->user());
            if (!$data['authenticatedInvitationUser']) {
                $request->session()->put('pending_invitation', $data['token']);
            }

            return view('invitations.accept', $data);
        } catch (ValidationException) {
            return view('auth.link-invalid');
        }
    }

    public function accept(InvitationAcceptRequest $request, InvitationService $invitations): RedirectResponse
    {
        $invitations->accept($request->validated('token'), $request->validated(), $request->user());
        $request->session()->forget('pending_invitation');

        return redirect()->route($request->user() ? 'organizations.index' : 'login')->with('status', 'Invitación aceptada. Tu membresía ya está disponible.');
    }
}
