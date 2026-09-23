<?php

declare(strict_types=1);

namespace App\Enums;

enum StravaReadSource: string
{
    case Webhook = 'webhook';
    case WebhookUpdate = 'webhook_update';
    case Poll = 'poll';
    case Manual = 'manual';
    case Hydration = 'hydration';
    case IngestSweep = 'ingest_sweep';
    case Doctor = 'doctor';
    case Zones = 'zones';
    case Cleanup = 'cleanup';
    case RevocationCheck = 'revocation_check';

    public static function fromSyncSource(StravaSyncSource $source): self
    {
        return match ($source) {
            StravaSyncSource::Poll => self::Poll,
            StravaSyncSource::Webhook => self::Webhook,
            StravaSyncSource::Manual => self::Manual,
        };
    }
}
