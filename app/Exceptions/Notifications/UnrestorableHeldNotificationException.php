<?php

declare(strict_types=1);

namespace App\Exceptions\Notifications;

use __PHP_Incomplete_Class;
use RuntimeException;
use Throwable;

/**
 * Raised when a held notification's stored payload no longer unserializes into
 * a Notification, so it can never be sent.
 */
class UnrestorableHeldNotificationException extends RuntimeException
{
    public function __construct(public readonly string $storedClass, ?Throwable $previous = null)
    {
        parent::__construct("Held notification restores as {$storedClass}, not a Notification.", 0, $previous);
    }

    public static function from(mixed $restored): self
    {
        if (! $restored instanceof __PHP_Incomplete_Class) {
            return new self(get_debug_type($restored));
        }

        $storedClass = get_object_vars($restored)['__PHP_Incomplete_Class_Name'];
        assert(is_string($storedClass));

        return new self($storedClass);
    }

    public static function unparseable(string $serialized, Throwable $previous): self
    {
        return new self(preg_match('/^O:\d+:"([^"]+)"/', $serialized, $matches) === 1 ? $matches[1] : 'unparseable', $previous);
    }
}
