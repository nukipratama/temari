<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Copy for the four public legal pages. Every claim here has to be one the code
 * actually keeps, so the two statements that already exist in code are pulled in
 * rather than paraphrased: {@see DataUseStatement} for AI data use and
 * {@see TrainingDisclaimer} for the not-medical-advice position.
 *
 * @phpstan-type Section array{heading: string, paragraphs: list<string>}
 * @phpstan-type Document array{slug: string, title: string, updated: string, intro: string, sections: list<Section>}
 */
final class LegalDocuments
{
    public const string UPDATED = '2026-08-13';

    private const string STRAVA_REVOKE_URL = 'https://www.strava.com/settings/apps';

    /**
     * @return Document
     */
    public static function terms(): array
    {
        return [
            'slug' => 'terms',
            'title' => 'Terms of use',
            'updated' => self::UPDATED,
            'intro' => 'Temari is a running companion that reads your Strava activities and writes about them. It is a personal project run by one person on their own hardware, not a company and not a product with a support desk. These terms say what that means for you.',
            'sections' => [
                [
                    'heading' => 'Getting an account',
                    'paragraphs' => [
                        'Signing in happens through Strava and nothing else. Temari has no password of its own, so there is no separate account to create and nothing of yours to leak from a password table here.',
                        'You need to be allowed to hold a Strava account to hold a Temari one, and you have to be the person whose Strava account you connect.',
                    ],
                ],
                [
                    'heading' => 'The demo account',
                    'paragraphs' => [
                        'The "Try the demo" button signs you into a single shared account with synthetic data. Everyone who taps it lands in the same account, so treat anything you do there as public. It cannot be deleted, and the operator can reset it back to its seeded state at any time.',
                    ],
                ],
                [
                    'heading' => 'What it costs',
                    'paragraphs' => [
                        'Nothing. There is no fee, no subscription, no payment path in the app at all, and no advertising.',
                        'The running costs, mostly the AI text, are paid by the person who runs it, out of pocket, against a daily ceiling. When that ceiling is reached, text generation pauses until the next day. Blocks waiting on it say they are pending rather than inventing something to show you.',
                    ],
                ],
                [
                    'heading' => 'What you can expect from it',
                    'paragraphs' => [
                        'Best effort, and no more than that. There is no uptime commitment, no guarantee your data will still be here tomorrow, and no promise the service will keep existing. Strava remains the system of record for your activities; Temari is a reader of it.',
                        'Keep your own backups if the data matters to you. Your activities live in Strava regardless, which is the point of only ever reading from it.',
                    ],
                ],
                [
                    'heading' => 'What is asked of you',
                    'paragraphs' => [
                        'Do not try to reach another account\'s data. Every read is scoped to the signed-in user and a request for someone else\'s run answers as if it does not exist.',
                        'Do not hammer the endpoints. Sign-in, sync and AI actions are rate limited, and the Strava read budget is shared by everyone using the app, so one person burning it slows the rest down.',
                        'Do not use Temari to do anything Strava\'s own terms forbid. Access here depends on Strava\'s API, and abuse of it costs everybody the integration.',
                    ],
                ],
                [
                    'heading' => 'Ending it',
                    'paragraphs' => [
                        'Delete your account from Settings whenever you like. It removes what Temari stored and unlinks your Strava connection in the same step, with one narrow exception set out in the privacy policy.',
                        'You can also cut access from Strava\'s side at '.self::STRAVA_REVOKE_URL.', which stops any further reads immediately.',
                        'Access here can be withdrawn too, for the abuse described above or because the project stops running.',
                    ],
                ],
                [
                    'heading' => TrainingDisclaimer::HEADLINE,
                    'paragraphs' => [
                        TrainingDisclaimer::TEXT,
                        'The full position, including what the plan engine cannot see, is on the training disclaimer page.',
                    ],
                ],
                [
                    'heading' => 'Changes',
                    'paragraphs' => [
                        'When these terms change, the new version is posted here with a new date at the top. There is no mailing list to notify you.',
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
            'title' => 'Privacy policy',
            'updated' => self::UPDATED,
            'intro' => 'Temari holds your Strava data so it can show it back to you. This page says what is held, what leaves the server, and what happens when you delete your account.',
            'sections' => [
                [
                    'heading' => 'What is stored',
                    'paragraphs' => [
                        'From your Strava profile: your name, your avatar image address, your Strava athlete id, and your email address if Strava shares one. There is no password, because sign-in is Strava\'s.',
                        'From your activities: the run itself and the numbers attached to it, including distance, time, pace, elevation, heart rate, cadence, splits and laps, plus the route line and start coordinate when the run has GPS. Alongside those, whatever Temari computes from them: training load, records, weekly and monthly summaries, cards, and the text it writes.',
                        'From your use of the app: your notification preferences and, if you turn them on, a Telegram chat id or a browser push endpoint. Server logs, including client-side errors reported by your browser, carry your user id so a bug can be traced back to a session.',
                    ],
                ],
                [
                    'heading' => 'Who else sees it',
                    'paragraphs' => [
                        'No other Temari account, ever. Reads are scoped to the signed-in user, and that is covered by tests rather than by intention.',
                        'It is not sold, and it is not handed to advertisers or data brokers. There is no advertising and no third-party analytics or tracking script in the app.',
                        'A small number of services are used to make specific features work, and they only receive what that feature needs: Azure OpenAI receives your run numbers so it can write text about them; Open-Meteo receives a coordinate and a timestamp to return the weather for a run; OpenStreetMap\'s Nominatim receives a start coordinate to name the place; Telegram receives your messages only if you connect it; your browser vendor\'s push service receives a notification payload only if you turn push on.',
                    ],
                ],
                [
                    'heading' => DataUseStatement::HEADLINE.' and AI',
                    'paragraphs' => DataUseStatement::points(),
                ],
                [
                    'heading' => 'Deleting your account',
                    'paragraphs' => [
                        'Settings has a delete button. It removes your account and everything hanging off it, including activities, their details and streams, cards, records, weekly and monthly snapshots, unlocks, notification subscriptions and every piece of text Temari wrote about you, and it unlinks your Strava connection.',
                        'One narrow exception, stated plainly because the sentence above would otherwise be untrue: the AI cost ledger is kept. Those rows are spending records rather than running data, and to stay attributable after the account is gone they keep your name and your Strava athlete id alongside the cost. They hold no activity data. If you want that removed too, ask.',
                        'Deleting here does not delete anything in Strava. Your activities are yours and stay there.',
                    ],
                ],
                [
                    'heading' => 'Cutting access from Strava',
                    'paragraphs' => [
                        'You can revoke Temari\'s access at '.self::STRAVA_REVOKE_URL.' at any time, with or without deleting your Temari account. Once revoked, no further activity is read. What was already synced stays until you delete the account.',
                    ],
                ],
                [
                    'heading' => 'Where it lives',
                    'paragraphs' => [
                        'On a server the operator runs and administers personally, not on a managed platform. Traffic reaches it over HTTPS.',
                        'Data is kept for as long as your account exists. There is no scheduled purge, because the value of the app is the history.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return Document
     */
    public static function aiUse(): array
    {
        return [
            'slug' => 'ai-use',
            'title' => 'How Temari uses AI',
            'updated' => self::UPDATED,
            'intro' => 'Most of the words in Temari are written by a language model, from your numbers, at the moment you first see them. This page says exactly what that involves.',
            'sections' => [
                [
                    'heading' => DataUseStatement::HEADLINE,
                    'paragraphs' => DataUseStatement::points(),
                ],
                [
                    'heading' => 'What is sent, and when',
                    'paragraphs' => [
                        'What goes to the model is the run\'s numbers and the context Temari has already computed around them: your recent averages, how this run compares to your own history, your load and streak state. It is sent when a block of text is first generated, not on every page load, and the result is stored so re-reading a page costs nothing.',
                        'Generation is skipped entirely when the daily cost ceiling has been reached. A block waiting on that stays visibly pending rather than falling back to something written by a template and passed off as Temari.',
                    ],
                ],
                [
                    'heading' => 'It can be wrong',
                    'paragraphs' => [
                        'The text is generated, not checked. Nobody reads it before you do. It can misread a run, overstate a pattern, or be confidently wrong about something you know better than it does. The numbers it is describing are the reliable part.',
                        TrainingDisclaimer::TEXT,
                    ],
                ],
                [
                    'heading' => 'Turning it off',
                    'paragraphs' => [
                        'There is no per-account switch for AI text today, and this page would rather say so than imply one exists. If you do not want a model reading your runs, do not connect the account, or delete it, which removes the stored text with everything else.',
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
            'sections' => [
                [
                    'heading' => 'What the plan is built from',
                    'paragraphs' => TrainingDisclaimer::scope(),
                ],
                [
                    'heading' => 'Why it still speaks in numbers',
                    'paragraphs' => [
                        'The plan is deliberately specific: a distance and a session type per day rather than a vague nudge. That is only defensible because the engine clamps itself, capping week-on-week increases, cutting the week when readiness or load says to, and refusing to carry a missed week\'s volume into a cram week.',
                        'Those clamps are why it can be assertive about a number. They are not a substitute for your own judgement about your own body.',
                    ],
                ],
                [
                    'heading' => 'When to ignore it',
                    'paragraphs' => [
                        'Whenever something hurts, whenever you are ill, and whenever a doctor or physiotherapist has told you otherwise. Take the rest day the plan did not schedule. Nothing here is graded, and skipping is not a failure state.',
                    ],
                ],
            ],
        ];
    }
}
