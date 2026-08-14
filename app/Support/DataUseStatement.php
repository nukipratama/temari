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
            'To write those notes, your run stats go to Azure OpenAI and come back as text. That is inference, and only inference. Neither Temari nor the model provider trains or fine-tunes any AI model on your data.',
            'Delete your account and everything Temari stored about you goes with it, and your Strava connection is unlinked. One thing stays: the AI cost ledger keeps your name and your Strava athlete id next to what was spent. It holds no activity data. If you want that removed too, ask.',
        ];
    }
}
