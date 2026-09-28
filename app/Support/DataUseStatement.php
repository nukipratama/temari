<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single wording of what Temari does with a runner's data, so the in-app
 * blurb and the (separately owned) terms and privacy pages cannot drift apart.
 */
final class DataUseStatement
{
    public const string HEADLINE = 'Your data';

    /**
     * @return list<string>
     */
    public static function points(): array
    {
        return [
            'Temari reads your Strava activities to build your dashboard, your cards, and the notes it writes about your running. Your activity data is only ever shown back to you: no other account can see it.',
            'To write those notes, your run stats go to a third-party AI service and come back as text. It reads them only to write the note: neither Temari nor that service trains any AI model on your data.',
            'Delete your account from Settings and your runs, cards and notes go with it, and your Strava connection is unlinked. The privacy policy spells out the details.',
        ];
    }
}
