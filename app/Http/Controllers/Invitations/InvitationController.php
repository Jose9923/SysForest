<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Http\Requests\Invitations\InvitationAcceptRequest;
use App\Http\Requests\Invitations\InvitationCreateRequest;
use App\Services\Auth\IdentityPageService;
use App\Services\Invitations\InvitationService;
use App\Services\Memberships\MembershipPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

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
