<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateHrZonesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunnerZonesController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Pengaturan/ZonaHR', [
            'profile' => $user->hrProfile(),
            'hasCustomProfile' => $user->runnerProfile !== null,
        ]);
    }

    public function update(UpdateHrZonesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<int, array{lo:int, hi:int}> $rawZones */
        $rawZones = array_values($request->validated('zones'));

        $hrZones = [];
        foreach (['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as $index => $key) {
            $hrZones[$key] = [
                'lo' => (int) $rawZones[$index]['lo'],
                'hi' => (int) $rawZones[$index]['hi'],
            ];
        }

        $user->runnerProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'max_hr' => (int) $request->validated('max_hr'),
                'resting_hr' => (int) $request->validated('resting_hr'),
                'hr_zones' => $hrZones,
                'source' => 'manual',
            ],
        );

        return back()->with('success', 'Zona HR kamu udah kesimpen. Dipakai ke semua lari berikutnya.');
    }
}
