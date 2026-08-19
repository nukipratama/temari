<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationReadController extends Controller
{
    /**
     * Mark one inbox row read. Scoped through the user's own relation, so
     * another user's id is a 404 rather than a forbidden hint that it exists.
     * Idempotent: an already-read row keeps its original timestamp.
     */
    public function __invoke(Request $request, int $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $row = $user->inboxNotifications()->findOrFail($notification);
        $row->markRead();

        return response()->json(['unread' => $user->inboxNotifications()->unread()->count()]);
    }
}
