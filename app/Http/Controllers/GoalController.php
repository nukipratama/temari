<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Gamification\GoalResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function __construct(private readonly GoalResolver $goals)
    {
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $goals = $this->goals->forUser($user);
        $completed = $this->goals->completedCount($goals);

        return Inertia::render('Goals', [
            'goals' => $goals,
            'completedCount' => $completed,
            'totalCount' => count($goals),
        ]);
    }
}
