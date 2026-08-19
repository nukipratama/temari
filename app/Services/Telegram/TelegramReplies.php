<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Static bot reply copy in Temari's voice (casual, no em-dashes, sparse
 * emoji). Kept in one place so a voice review is a single file. See
 * docs/voice-and-tone.md.
 */
class TelegramReplies
{
    /**
     * Sent on a successful link. Names the account ($name, the Strava display
     * name) so the user confirms which Temari account this Telegram is tied
     * to.
     */
    public static function welcome(string $name): string
    {
        return "Hey {$name}, I'm Temari. Your Telegram is now linked to your Temari account. "
            . "From here on, I'll ping you after every run and with your weekly recap.";
    }

    public static function expired(): string
    {
        return "That link isn't valid anymore (expired, or already used). Open your profile page "
            . 'in Temari and tap "Connect Telegram" again, and I\'ll send you a fresh one.';
    }

    public static function generic(): string
    {
        return 'Hey! I\'m Temari. Open Temari and tap "Connect Telegram" to link up.';
    }

    public static function disconnected(): string
    {
        return "Done, I've disconnected Telegram from your Temari account. Whenever you want to reconnect, I'm here.";
    }

    /** Sent by the "Send test notification" button on the Aku page. */
    public static function test(): string
    {
        return "Test notification from Temari. If you're seeing this, your Telegram connection is working. "
            . "I'll ping you here after every run and with your weekly recap.";
    }
}
