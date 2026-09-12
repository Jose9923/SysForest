<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RoleAssignmentRequest;
use App\Http\Requests\UserStateRequest;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request, UserService $users): View
    {
        return view('users.index', ['users' => $users->index($request->user())]);
    }

    public function show(Request $request, int $user, UserService $users): View
    {
        return view('users.show', $users->show($request->user(), $user));
    }

    public function update(UserStateRequest $request, int $user, UserService $users): RedirectResponse
    {
        $users->setActive($request->user(), $user, $request->boolean('active'));

        return back()->with('status', 'Estado global actualizado.');
    }

    public function roles(RoleAssignmentRequest $request, int $user, UserService $users): RedirectResponse
    {
        $users->assignRole($request->user(), $user, $request->validated());

        return back()->with('status', 'Asignación de rol registrada.');
    }
}
