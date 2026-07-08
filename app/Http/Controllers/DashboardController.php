<?php

namespace App\Http\Controllers;

use App\Services\TokenBudget;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, TokenBudget $budget): Response
    {
        return Inertia::render('Dashboard', [
            'usage' => $budget->snapshot($request->user()),
            'stats' => [
                'conversations' => $request->user()->conversations()->count(),
                'projects' => $request->user()->projects()->count(),
                'skills' => $request->user()->skills()->count(),
            ],
        ]);
    }
}
