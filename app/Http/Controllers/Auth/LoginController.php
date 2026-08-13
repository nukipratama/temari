<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\DataUseStatement;
use App\Support\LocalRedirectPath;
use App\Support\TrainingDisclaimer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'authStravaUrl' => route('auth.strava.redirect'),
            'from' => LocalRedirectPath::fromIntended($request->session()->get('url.intended')),
            'dataUse' => [
                'headline' => DataUseStatement::HEADLINE,
                'points' => DataUseStatement::points(),
            ],
            'trainingDisclaimer' => [
                'headline' => TrainingDisclaimer::HEADLINE,
                'text' => TrainingDisclaimer::TEXT,
            ],
        ]);
    }
}
