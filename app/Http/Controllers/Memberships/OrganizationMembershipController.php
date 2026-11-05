<?php

declare(strict_types=1);

namespace App\Http\Controllers\Memberships;

use App\Http\Requests\Memberships\MembershipEditRequest;
use App\Services\Memberships\MembershipPageService;
use App\Services\Memberships\OrganizationMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class OrganizationMembershipController extends Controller
{
    public function index(Request $request, MembershipPageService $pages): \Illuminate\View\View
    {
        return view('memberships.index', $pages->index($request->user()));
    }

    public function show(Request $request, string $membership, OrganizationMembershipService $service): JsonResponse
    {
        $record = $service->show($request->user(), $membership);

        return response()->json(['active' => $record->active, 'joined_at' => $record->joined_at, 'left_at' => $record->left_at]);
    }

    public function update(MembershipEditRequest $request, string $membership, OrganizationMembershipService $service): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $record = $service->edit($request->user(), $membership, $request->validated());
        if (!$request->expectsJson()) {
            return back()->with('status', 'Membresía actualizada.');
        }

        return response()->json(['active' => $record->active, 'left_at' => $record->left_at]);
    }
}
