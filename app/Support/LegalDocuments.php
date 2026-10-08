<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Copy for the three public legal pages. Every claim here has to be one the code
 * actually keeps, so the two statements that already exist in code are pulled in
 * rather than paraphrased: {@see DataUseStatement} for data use and
 * {@see TrainingDisclaimer} for the not-medical-advice position.
 *
 * @phpstan-type Section array{id?: string, heading: string, paragraphs: list<string>}
 * @phpstan-type Document array{slug: string, title: string, updated: string, intro: string, summary: list<string>, sections: list<Section>}
 */
final class LegalDocuments
{
    public const string UPDATED = '2026-10-08';

    public const string NOTES_SECTION_ID = 'notes';

    private const string STRAVA_REVOKE_URL = 'https://www.strava.com/settings/apps';

    /**
     * @return Document
     */
    public static function terms(): array
    {
        return [
            'slug' => 'terms',
            'title' => 'terms of use',
            'updated' => self::UPDATED,
            'intro' => 'Temari is a running companion that reads your Strava activities and writes about them. Temari is run by one person, not a company, and these terms say what that means for you.',
            'summary' => [
                'It\'s free: no fee, no subscription, no ads.',
                'You sign in with Strava, and Temari only ever reads from it.',
                'It\'s run with care, but with no uptime promise. Strava always keeps your originals.',
                'Delete your account from Settings whenever you like.',
                'The plan is training guidance, not medical advice.',
            ],
            'sections' => [
                [
                    'heading' => 'getting an account',
                    'paragraphs' => [
                        'Signing in happens through Strava and nothing else. Temari has no password of its own, so there is no separate account to create and no password of yours to leak from here.',
                        'You need to be allowed to hold a Strava account to hold a Temari one, and you have to be the person whose Strava account you connect.',
                    ],
                ],
                [
                    'heading' => 'the demo account',
                    'paragraphs' => [
                        'The "try the demo" button signs you into one shared account with made-up data. Everyone who taps it lands in the same account, so anything you do there can be seen by the next visitor. It can\'t be deleted, and we can reset it to its starting state at any time.',
                    ],
                ],
                [
                    'heading' => 'what it costs',
                    'paragraphs' => [
                        'Nothing. There is no fee, no subscription, no payment path in the app at all, and no advertising. We cover the running costs.',
                    ],
                ],
                [
                    'heading' => 'what you can expect from it',
                    'paragraphs' => [
                        'Temari is a one-person project, run with care. That also means there is no uptime promise, no guarantee what it stores will be here tomorrow, and no promise it keeps running for good.',
                        'Strava always keeps your originals. Temari only ever reads from it, so your activities stay safe there whatever happens here.',
                    ],
                ],
                [
                    'heading' => 'what we ask of you',
                    'paragraphs' => [
                        'Don\'t try to reach another account\'s data. Every read is scoped to the signed-in runner, and a request for someone else\'s run answers as if it doesn\'t exist.',
                        'Don\'t hammer the endpoints. Sign-in, sync and note requests are rate limited, and the Strava read budget is shared by everyone using the app, so one person burning it slows the rest down.',
                        'Don\'t use Temari to do anything Strava\'s own terms forbid. Access here depends on Strava\'s API, and abusing it costs everybody the integration.',
                    ],
                ],
                [
                    'heading' => 'ending it',
                    'paragraphs' => [
                        'Delete your account from Settings whenever you like. It removes what Temari stored and unlinks your Strava connection in the same step. The privacy policy lists the little that stays.',
                        'You can also cut access from Strava\'s side at '.self::STRAVA_REVOKE_URL.', which stops any further reads immediately.',
                        'We can withdraw access too, for the abuse described above or because the project stops running.',
                    ],
                ],
                [
                    'heading' => TrainingDisclaimer::HEADLINE,
                    'paragraphs' => [
                        TrainingDisclaimer::TEXT,
                        'The full position, including what the plan can\'t see, is on the training disclaimer page.',
                    ],
                ],
                [
                    'heading' => 'changes',
                    'paragraphs' => [
                        'When these terms change, the new version is posted here with a new date at the top. There is no mailing list to tell you.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return Document
     */
    public static function privacy(): array
    {
        return [
            'slug' => 'privacy',
            'title' => 'privacy policy',
            'updated' => self::UPDATED,
            'intro' => 'Temari holds your Strava data so it can show it back to you. This page says what we keep, what leaves our server, and what happens when you delete your account.',
            'summary' => [
                'Temari only reads from Strava, and only you see your runs.',
                'No ads, no trackers, and your data is never sold.',
                'Your notes are written by a third-party AI service that doesn\'t train on your data.',
                'Delete your account from Settings anytime. The one small thing that stays is listed below.',
            ],
            'sections' => [
                [
                    'heading' => 'what we store',
                    'paragraphs' => [
                        'From your Strava profile: your name, your avatar image address, your Strava athlete id, and your email address if Strava shares one. There is no password, because sign-in is Strava\'s.',
                        'From your activities: the run itself and the numbers attached to it, including distance, time, pace, elevation, heart rate, cadence, splits and laps, plus the route line and start coordinate when the run has GPS. Alongside those, whatever Temari works out from them: training load, records, weekly and monthly summaries, cards, and the notes it writes.',
                        'From your use of the app: your notification preferences and, if you turn them on, a Telegram chat id or a browser push endpoint. Error logs, including errors your browser reports, carry your user id so we can trace a bug back to it.',
                    ],
                ],
                [
                    'heading' => 'who else sees it',
                    'paragraphs' => [
                        'No other Temari account, ever. Every read is scoped to the signed-in runner, and tests check that it stays that way.',
                        'It isn\'t sold, and it isn\'t handed to advertisers or data brokers. There is no advertising, and nothing tracks you across other sites.',
                        'A few services make specific features work, and each only receives what its feature needs: a third-party AI service receives your run numbers so it can write your notes, and does not train on them; Open-Meteo receives a coordinate and a timestamp to return the weather for a run; OpenStreetMap\'s Nominatim receives a start coordinate to name the place; Telegram receives your messages only if you connect it; your browser vendor\'s push service receives a notification only if you turn push on. Cloudflare, which serves the site, counts page views with its cookieless Web Analytics: the page, the referring site, and the browser, device and country, seen only as totals.',
                    ],
                ],
                [
                    'heading' => DataUseStatement::HEADLINE,
                    'paragraphs' => DataUseStatement::points(pointToPrivacyPolicy: false),
                ],
                [
                    'id' => self::NOTES_SECTION_ID,
                    'heading' => 'how temari writes your notes',
                    'paragraphs' => [
                        'Most of the words in Temari, the notes on your runs, weeks and plan, are written by an AI service from your numbers. Each note is written once and stored, so re-reading a page sends nothing.',
                        'What goes out is the run\'s numbers and the context Temari has already worked out around them: your recent averages, how this run compares to your own history, your load and streak.',
                        'Writing fresh notes has a daily limit. Past it, notes come from a fixed set of lines built from the same numbers, and the page does not mark which is which.',
                        'The notes are not checked before you read them, so they can misread a run or overstate a pattern. The numbers they describe are the reliable part.',
                        'There is no per-account switch for AI text today, and we would rather say so than imply one exists. If you don\'t want your runs read this way, don\'t connect the account, or delete it, which removes the stored notes with everything else.',
                    ],
                ],
                [
                    'heading' => 'deleting your account',
                    'paragraphs' => [
                        'Deleting is self-serve: the delete button in Settings does it on the spot. It removes your account and everything hanging off it, including activities, their details and streams, cards, records, weekly and monthly snapshots, notification subscriptions and every note Temari wrote about you, and it unlinks your Strava connection.',
                        'One thing stays: a record of what your notes cost to write, which keeps your name and your Strava athlete id but no activity data.',
                        'Your Strava sign-in key is kept, encrypted, only until Strava confirms it has been released, and then it goes too.',
                        'Deleting here does not delete anything in Strava. Your activities are yours and stay there.',
                    ],
                ],
                [
                    'heading' => 'cutting access from strava',
                    'paragraphs' => [
                        'You can revoke Temari\'s access at '.self::STRAVA_REVOKE_URL.' at any time, with or without deleting your Temari account. Once revoked, no further activity is read. What was already synced stays until you delete the account.',
                    ],
                ],
                [
                    'heading' => 'where it lives',
                    'paragraphs' => [
                        'On a server we run and look after ourselves, not on a managed platform. Traffic reaches it over HTTPS.',
                        'Data is kept for as long as your account exists. There is no scheduled purge, because the value of the app is the history.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return Document
     */
    public static function trainingDisclaimer(): array
    {
        return [
            'slug' => 'training-disclaimer',
            'title' => TrainingDisclaimer::HEADLINE,
            'updated' => self::UPDATED,
            'intro' => TrainingDisclaimer::TEXT,
            'summary' => [
                'The plan is built from your runs, not from a medical check-up.',
                'It can\'t see an injury, an illness, or training that never reached Strava.',
                'If something hurts, rest and see a pro. Skipping a day is never a failure.',
            ],
            'sections' => [
                [
                    'heading' => 'what the plan is built from',
                    'paragraphs' => TrainingDisclaimer::scope(),
                ],
                [
                    'heading' => 'why it still speaks in numbers',
                    'paragraphs' => [
                        'The plan is deliberately specific: a distance and a session type per day rather than a vague nudge. That only holds up because the plan holds itself back, capping week-on-week increases, cutting the week when readiness or load says to, and refusing to carry a missed week\'s volume into a cram week.',
                        'Those limits are why it can be firm about a number. They are not a substitute for your own judgement about your own body.',
                    ],
                ],
                [
                    'heading' => 'when to ignore it',
                    'paragraphs' => [
                        'Whenever something hurts, whenever you are ill, and whenever a doctor or physiotherapist has told you otherwise. Take the rest day the plan did not schedule. Nothing here is graded, and skipping is not a failure.',
                    ],
                ],
            ],
        ];
    }
}
