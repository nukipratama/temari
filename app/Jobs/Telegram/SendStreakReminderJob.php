<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the "streak at risk" nudge dispatched by {@see \App\Console\Commands\Gamification\StreakRemindCommand}.
 * Re-checks the demo flag, the connection, and the opt-in at send time (the
 * command already checked, but this can run later off a queue) rather than
 * trusting the state at dispatch time.
 */
class SendStreakReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $userId, public readonly int $streakWeeks)
    {
    }

    public function handle(TelegramClient $client): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null || $user->is_demo) {
            return;
        }

        $connection = $user->telegramConnection;
        if ($connection === null || $connection->isRevoked() || ! $connection->notify_weekly_recap) {
            return;
        }

        $client->sendMessage($connection->chat_id, $this->message());
    }

    private function message(): string
    {
        $url = route('dashboard');

        return "🔥 Streak lari {$this->streakWeeks} minggu kamu belum ada progres minggu ini. Sempatkan lari sebelum minggu ini berakhir, biar streak-nya nggak putus.\n\nBuka Temari: {$url}";
    }
}
