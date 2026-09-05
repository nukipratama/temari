<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Http\Request;
use Inertia\Response;

class RootController extends Controller
{
    public function __invoke(Request $request, LoginController $login, DashboardController $dashboard): Response
    {
        if ($request->user() === null) {
            return $login->show($request);
        }

        /** @var Response $response */
        $response = app()->call($dashboard);

        return $response;
    }
}
