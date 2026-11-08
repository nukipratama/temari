import { Head } from '@inertiajs/react';

import AccountActions from '@/components/settings/AccountActions';
import AppearanceCard from '@/components/settings/AppearanceCard';
import DataUseList from '@/components/settings/DataUseList';
import HrZonesDisclosure, {
    type HrZonesPayload,
} from '@/components/settings/HrZonesDisclosure';
import InstallAppSection from '@/components/settings/InstallAppSection';
import IntervalsIcuPanel, {
    type IntervalsIcuPayload,
} from '@/components/settings/IntervalsIcuPanel';
import LegalLinks from '@/components/settings/LegalLinks';
import NotificationPrefsPanel from '@/components/settings/NotificationPrefsPanel';
import { type TelegramPayload } from '@/components/settings/TelegramPanel';
import TrainingPreferencesCard, {
    type TrainingPreferencesPayload,
} from '@/components/settings/TrainingPreferencesCard';
import Eyebrow from '@/components/ui/Eyebrow';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import { appLayout } from '@/layouts/appLayout';

import { type NotificationPrefs } from './useNotificationPrefs';

interface DataUsePayload {
    headline: string;
    points: string[];
}

interface SettingsProps {
    dataUse?: DataUsePayload;
    telegram?: TelegramPayload;
    intervalsIcu?: IntervalsIcuPayload;
    notificationPrefs?: NotificationPrefs;
    /** Seconds left on the test-send cooldown, or null when it is not cooling. */
    testCooldownSeconds?: number | null;
    hrZones?: HrZonesPayload;
    trainingPreferences?: TrainingPreferencesPayload;
}

const TELEGRAM_DEFAULT: TelegramPayload = {
    connected: false,
    username: null,
    connect_url: null,
};

const INTERVALS_ICU_DEFAULT: IntervalsIcuPayload = {
    connected: false,
    athlete_id: null,
    broken: false,
};

const PREFS_DEFAULT: NotificationPrefs = {
    notifications_enabled: true,
    briefing_push_enabled: true,
    briefing_push_minute: null,
    telegram_enabled: true,
    push_enabled: true,
};

// Mirrors config('runner.php')'s single-user defaults, the same fallback
// SettingsController hands back for a user with no RunnerProfile row.
const HR_ZONES_DEFAULT: HrZonesPayload = {
    profile: {
        max_hr: 180,
        resting_hr: 55,
        hr_zones: {
            Z1: { lo: 116, hi: 138 },
            Z2: { lo: 138, hi: 154 },
            Z3: { lo: 154, hi: 168 },
            Z4: { lo: 168, hi: 176 },
            Z5: { lo: 176, hi: 999 },
        },
        optimal_cadence_spm: 170,
    },
    source: 'default',
    stravaSyncedLabel: null,
    canSyncFromStrava: false,
};

const TRAINING_PREFERENCES_DEFAULT: TrainingPreferencesPayload = {
    experience_level: null,
    sessions_per_week: null,
    goal_type: null,
    run_days: null,
    long_run_day: null,
};

export default function Settings({
    dataUse,
    telegram = TELEGRAM_DEFAULT,
    intervalsIcu = INTERVALS_ICU_DEFAULT,
    notificationPrefs = PREFS_DEFAULT,
    testCooldownSeconds = null,
    hrZones = HR_ZONES_DEFAULT,
    trainingPreferences = TRAINING_PREFERENCES_DEFAULT,
}: Readonly<SettingsProps>) {
    return (
        <>
            <Head title="Settings" />
            <PageContainer>
                <header className="mb-8 flex flex-col gap-5">
                    <PageHero eyebrow="Settings" size="quote-lg" italic>
                        tune it <br />
                        <em className="italic text-horizon-ink">your way.</em>
                    </PageHero>
                </header>

                <LaneStack>
                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Appearance
                        </Eyebrow>
                        <div className="mt-3">
                            <AppearanceCard />
                        </div>
                    </section>

                    <InstallAppSection />

                    {/* One notification section, not three. The user holds a single
                        model with two questions — what gets sent, and where it goes —
                        and splitting those across "Notifications", "Push" and
                        "Telegram" made them look unrelated. */}
                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Notifications
                        </Eyebrow>
                        <div className="mt-3">
                            <NotificationPrefsPanel
                                prefs={notificationPrefs}
                                telegram={telegram}
                                testCooldownSeconds={testCooldownSeconds}
                            />
                        </div>
                    </section>

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Integrations
                        </Eyebrow>
                        <div className="mt-3 flex flex-col">
                            <IntervalsIcuPanel intervalsIcu={intervalsIcu} />
                        </div>
                    </section>

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Running
                        </Eyebrow>
                        {/* Preferences before the zones disclosure, as the
                            prototype orders them: the open card first, the
                            collapsed one under it. */}
                        <div className="mt-3 flex flex-col divide-y divide-dashed divide-border [&>*]:py-4 [&>*:first-child]:pt-0 [&>*:last-child]:pb-0">
                            <TrainingPreferencesCard
                                trainingPreferences={trainingPreferences}
                            />
                            <HrZonesDisclosure hrZones={hrZones} />
                        </div>
                    </section>

                    {dataUse ? (
                        <section>
                            <Eyebrow as="h2" token="small" tone="ink-2">
                                {dataUse.headline}
                            </Eyebrow>
                            <DataUseList points={dataUse.points} />
                        </section>
                    ) : null}

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            The fine print
                        </Eyebrow>
                        <div className="mt-3">
                            <LegalLinks />
                        </div>
                    </section>

                    <section>
                        <Eyebrow as="h2" token="small" tone="ink-2">
                            Account
                        </Eyebrow>
                        <AccountActions />
                    </section>
                </LaneStack>
            </PageContainer>
        </>
    );
}

Settings.layout = appLayout;
