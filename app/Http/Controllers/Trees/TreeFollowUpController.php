<?php

declare(strict_types=1);

namespace App\Http\Controllers\Trees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Trees\TreeFilterRequest;
use App\Services\Trees\TreeFollowUpReadService;
use Illuminate\View\View;

class TreeFollowUpController extends Controller
{
    public function __invoke(TreeFilterRequest $request, TreeFollowUpReadService $reader): View
    {
        return view('trees.follow-up', $reader->index($request->user(), $request->validated()));
    }
}
