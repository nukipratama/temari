<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationReadAllController extends Controller
{
    /**
     * Mark every unread inbox row for the authenticated user read in one query.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        InboxNotification::markAllReadFor($user);

        return response()->json(['unread' => $user->inboxNotifications()->unread()->count()]);
    }
}
