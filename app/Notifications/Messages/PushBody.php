<?php

declare(strict_types=1);

namespace App\Notifications\Messages;

use Minishlink\WebPush\Encryption;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A narration cut down to a lock-screen push body: whole sentences up to
 * {@see self::MAX_CHARS}, else the words that fit plus an ellipsis. The tap
 * opens the full narration.
 */
final class PushBody
{
    public const int MAX_CHARS = 180;

    private const string ELLIPSIS = '…';

    private const int SHRINK_STEP = 20;

    /** Sets the excerpt as the body, shortening it until the encoded payload fits the push service's limit. */
    public static function attach(WebPushMessage $message, string $narration): WebPushMessage
    {
        $maxChars = self::MAX_CHARS;
        $message->body(self::excerpt($narration, $maxChars));

        while ($maxChars > 0 && self::payloadBytes($message) > Encryption::MAX_PAYLOAD_LENGTH) {
            $maxChars = max(0, $maxChars - self::SHRINK_STEP);
            $message->body(self::excerpt($narration, $maxChars));
        }

        return $message;
    }

    public static function excerpt(string $narration, int $maxChars = self::MAX_CHARS): string
    {
        $text = trim($narration);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        if ($maxChars <= mb_strlen(self::ELLIPSIS)) {
            return '';
        }

        return self::wholeSentences($text, $maxChars) ?? self::wholeWords($text, $maxChars);
    }

    private static function wholeSentences(string $text, int $maxChars): ?string
    {
        preg_match_all('/[.!?…]["\')\]]*(?=\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE);

        $cut = null;
        foreach ($matches[0] as [$mark, $offset]) {
            $end = mb_strlen(substr($text, 0, $offset + strlen($mark)));
            if ($end > $maxChars) {
                break;
            }
            $cut = $end;
        }

        return $cut === null ? null : mb_substr($text, 0, $cut);
    }

    private static function wholeWords(string $text, int $maxChars): string
    {
        $slice = mb_substr($text, 0, $maxChars - mb_strlen(self::ELLIPSIS) + 1);
        $words = preg_match('/^(.*\S)\s/us', $slice, $match) === 1
            ? $match[1]
            : mb_substr($slice, 0, -1);

        return preg_replace('/[\s,;:\-–—]+$/u', '', $words) . self::ELLIPSIS;
    }

    private static function payloadBytes(WebPushMessage $message): int
    {
        return strlen(json_encode($message->toArray(), JSON_THROW_ON_ERROR));
    }
}
