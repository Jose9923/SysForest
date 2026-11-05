<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicPortal;

use App\Services\PublicPortal\PublicPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PublicPortalController extends Controller
{
    public function __construct(private PublicPortalService $portal)
    {
    }

    public function home(): View
    {
        return view('public.home', $this->portal->home());
    }

    public function map(Request $request): View
    {
        return view('public.map', $this->portal->map($request->query()));
    }

    public function index(Request $request): View
    {
        return view('public.trees.index', $this->portal->listing($request->query()));
    }

    public function show(Request $request, string $organization, string $tree): View
    {
        return view('public.trees.show', $this->portal->detail($organization, $tree, $request->query()));
    }
}
