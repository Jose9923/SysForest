<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMembershipRequest;
use App\Services\OrganizationMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationMembershipController extends Controller
{
    public function show(Request $request, string $membership, OrganizationMembershipService $service): JsonResponse
    {
        $record = $service->show($request->user(), $membership);

        return response()->json(['active' => $record->active, 'joined_at' => $record->joined_at, 'left_at' => $record->left_at]);
    }

    public function update(UpdateMembershipRequest $request, string $membership, OrganizationMembershipService $service): JsonResponse
    {
        $record = $service->setActive($request->user(), $membership, $request->boolean('active'));

        return response()->json(['active' => $record->active, 'left_at' => $record->left_at]);
    }
}
