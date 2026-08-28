import { MotionConfig, motion } from 'framer-motion';
import { useState } from 'react';
import {
    Activity,
    Bell,
    CalendarCheck,
    Compass,
    Flag,
    History,
    LogIn,
    Settings as SettingsIcon,
    Sun,
    TrendingUp,
    User,
} from 'lucide-react';

import { ActivityDetailScreen } from '@/components/pages/ActivityDetailScreen';
import { HistoryScreen } from '@/components/pages/HistoryScreen';
import { InboxScreen } from '@/components/pages/InboxScreen';
import { LoginScreen } from '@/components/pages/LoginScreen';
import { OnboardingScreen } from '@/components/pages/OnboardingScreen';
import { PlanScreen } from '@/components/pages/PlanScreen';
import { ProfileScreen } from '@/components/pages/ProfileScreen';
import { RaceGoalScreen } from '@/components/pages/RaceGoalScreen';
import { SettingsScreen } from '@/components/pages/SettingsScreen';
import { TodayScreen } from '@/components/pages/TodayScreen';
import { TrendsScreen } from '@/components/pages/TrendsScreen';
import { ActivityTopbar } from '@/components/rack/ActivityTopbar';
import { AppBottomNav } from '@/components/rack/AppBottomNav';
import { AppTopbar } from '@/components/rack/AppTopbar';
import { InboxTopbar } from '@/components/rack/InboxTopbar';
import { ProfileTopbar } from '@/components/rack/ProfileTopbar';
import { Rack } from '@/components/rack/Rack';
import { SettingsTopbar } from '@/components/rack/SettingsTopbar';
import { cn } from '@/lib/utils';

const PAGES = [
    { key: 'login', label: 'Login', icon: LogIn },
    { key: 'onboarding', label: 'Onboarding', icon: Compass },
    { key: 'today', label: 'Today', icon: Sun },
    { key: 'plan', label: 'Plan', icon: CalendarCheck },
    { key: 'race', label: 'Race Goal', icon: Flag },
    { key: 'trends', label: 'Trends', icon: TrendingUp },
    { key: 'history', label: 'History', icon: History },
    { key: 'activity', label: 'Activity', icon: Activity },
    { key: 'inbox', label: 'Inbox', icon: Bell },
    { key: 'profile', label: 'Profile', icon: User },
    { key: 'settings', label: 'Settings', icon: SettingsIcon },
] as const;

type PageKey = (typeof PAGES)[number]['key'];

function NotBuilt({ label }: Readonly<{ label: string }>) {
    return (
        <div className="rounded-4xl border border-dashed border-line bg-card p-10 text-center">
            <p className="display text-lg text-ink">
                {label} isn't ported yet.
            </p>
            <p className="mt-1 text-sm text-ink-3">
                Same layout and copy as resources/brand/{label.toLowerCase()}
                -redesign.html — just needs its shadcn/ui pass.
            </p>
        </div>
    );
}

export default function App() {
    const [page, setPage] = useState<PageKey>('today');
    const [planState, setPlanState] = useState<'has' | 'empty'>('has');
    const [historyState, setHistoryState] = useState<
        'populated' | 'partial' | 'empty'
    >('populated');
    const [raceState, setRaceState] = useState<'unset' | 'set'>('unset');
    const [tgState, setTgState] = useState<'unset' | 'connected'>('unset');
    const [zoneSource, setZoneSource] = useState<
        'default' | 'strava' | 'manual'
    >('strava');
    const [regenState, setRegenState] = useState<'ready' | 'cooldown'>('ready');
    const [aiReplanState, setAiReplanState] = useState<'ready' | 'cooldown'>(
        'ready',
    );
    const triggerAiReplan = () => setAiReplanState('cooldown');
    const [weekDaysVariant, setWeekDaysVariant] = useState<
        'default' | 'showcase'
    >('default');
    const [projectionState, setProjectionState] = useState<'ready' | 'none'>(
        'ready',
    );
    const [awaitingDetail, setAwaitingDetail] = useState<'ready' | 'hydrating'>(
        'ready',
    );
    const [pastYouState, setPastYouState] = useState<'match' | 'none'>('match');
    const [rereadState, setRereadState] = useState<'ready' | 'cooldown'>(
        'ready',
    );
    const [inboxState, setInboxState] = useState<'populated' | 'empty'>(
        'populated',
    );
    const openInbox = () => setPage('inbox');

    return (
        <MotionConfig reducedMotion="user">
            <div className="min-h-dvh bg-background pb-24 sm:pb-0">
                <header className="sticky top-0 z-20 border-b border-border bg-background/85 backdrop-blur">
                    <div className="mx-auto flex max-w-5xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div className="flex items-baseline gap-3">
                            <span className="display text-lg text-ink">
                                Temari
                            </span>
                            <span className="eyebrow text-[11px] text-ink-3">
                                shadcn/ui prototype
                            </span>
                        </div>
                        <nav aria-label="Pages" className="hidden sm:block">
                            <ul className="flex gap-1">
                                {PAGES.map((p) => (
                                    <li key={p.key}>
                                        <button
                                            type="button"
                                            aria-current={
                                                p.key === page
                                                    ? 'page'
                                                    : undefined
                                            }
                                            onClick={() => setPage(p.key)}
                                            className={cn(
                                                'flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors',
                                                p.key === page
                                                    ? 'bg-horizon/25 text-ink'
                                                    : 'text-ink-3 hover:bg-cream-deep',
                                            )}
                                        >
                                            <p.icon
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                            {p.label}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    </div>
                </header>

                <motion.main
                    key={page}
                    initial={{ opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.2 }}
                    className="px-4 py-6 sm:px-6 sm:py-8"
                >
                    {page === 'login' && (
                        <Rack
                            render={() => (
                                <LoginScreen
                                    onConnect={() => setPage('onboarding')}
                                    onTryDemo={() => setPage('today')}
                                />
                            )}
                        />
                    )}

                    {page === 'onboarding' && (
                        <Rack
                            render={() => (
                                <OnboardingScreen
                                    onFinish={() => setPage('today')}
                                />
                            )}
                        />
                    )}

                    {page === 'today' && (
                        <div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(['has', 'empty'] as const).map((s) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setPlanState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            planState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {s === 'has'
                                            ? 'Has plan'
                                            : 'No plan (proposed empty state)'}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={() => (
                                    <TodayScreen planState={planState} />
                                )}
                                topbar={<AppTopbar onOpenInbox={openInbox} />}
                                bottomnav={<AppBottomNav active="today" />}
                            />
                        </div>
                    )}

                    {page === 'plan' && (
                        <div>
                            <div className="mb-2.5 flex flex-wrap gap-2">
                                {(['has', 'empty'] as const).map((s) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setPlanState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            planState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {s === 'has'
                                            ? 'Has plan'
                                            : 'No plan (proposed empty state)'}
                                    </button>
                                ))}
                            </div>
                            <div className="mb-2.5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['unset', 'No race set'],
                                        ['set', 'Race set'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setRaceState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            raceState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['ready', 'AI replan: ready'],
                                        ['cooldown', 'AI replan: cooldown'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setAiReplanState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            aiReplanState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['default', 'Week days: realistic mix'],
                                        [
                                            'showcase',
                                            'Week days: one of every status',
                                        ],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setWeekDaysVariant(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            weekDaysVariant === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={() => (
                                    <PlanScreen
                                        key={weekDaysVariant}
                                        planState={planState}
                                        raceState={raceState}
                                        aiReplanState={aiReplanState}
                                        onTriggerAiReplan={triggerAiReplan}
                                        weekDaysVariant={weekDaysVariant}
                                        onViewActivity={() =>
                                            setPage('activity')
                                        }
                                        onNavigateRace={() => setPage('race')}
                                    />
                                )}
                                topbar={<AppTopbar onOpenInbox={openInbox} />}
                                bottomnav={<AppBottomNav active="plan" />}
                            />
                        </div>
                    )}

                    {page === 'race' && (
                        <div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['unset', 'No race set'],
                                        ['set', 'Race set'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setRaceState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            raceState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            {raceState === 'set' && (
                                <div className="mb-5 flex flex-wrap gap-2">
                                    {(
                                        [
                                            ['ready', 'Projection: ready'],
                                            ['none', 'Projection: no PRs yet'],
                                        ] as const
                                    ).map(([s, label]) => (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() =>
                                                setProjectionState(s)
                                            }
                                            className={cn(
                                                'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                                projectionState === s
                                                    ? 'border-ink bg-ink text-cream'
                                                    : 'border-line-strong bg-white text-ink-2',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['ready', 'AI replan: ready'],
                                        ['cooldown', 'AI replan: cooldown'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setAiReplanState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            aiReplanState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={() => (
                                    <RaceGoalScreen
                                        raceState={raceState}
                                        projectionState={projectionState}
                                        aiReplanState={aiReplanState}
                                        onTriggerAiReplan={triggerAiReplan}
                                        onNavigateSchedule={() =>
                                            setPage('plan')
                                        }
                                    />
                                )}
                                topbar={<AppTopbar onOpenInbox={openInbox} />}
                                bottomnav={<AppBottomNav active="plan" />}
                            />
                        </div>
                    )}

                    {page === 'trends' && (
                        <div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['ready', 'Regenerate: ready'],
                                        ['cooldown', 'Regenerate: cooldown'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setRegenState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            regenState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={(theme) => (
                                    <TrendsScreen
                                        theme={theme}
                                        regenState={regenState}
                                    />
                                )}
                                topbar={<AppTopbar onOpenInbox={openInbox} />}
                                bottomnav={<AppBottomNav active="trends" />}
                            />
                        </div>
                    )}

                    {page === 'history' && (
                        <div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['populated', 'Populated'],
                                        ['partial', 'This week not started'],
                                        ['empty', 'No runs yet'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setHistoryState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            historyState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={() => (
                                    <HistoryScreen
                                        historyState={historyState}
                                    />
                                )}
                                topbar={<AppTopbar onOpenInbox={openInbox} />}
                                bottomnav={<AppBottomNav active="history" />}
                            />
                        </div>
                    )}

                    {page === 'activity' && (
                        <div>
                            <div className="mb-2.5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['ready', 'Detail ready'],
                                        ['hydrating', 'Still hydrating'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setAwaitingDetail(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            awaitingDetail === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <div className="mb-2.5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['match', 'Past You: match'],
                                        ['none', 'Past You: none'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setPastYouState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            pastYouState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['ready', 'Reread: ready'],
                                        ['cooldown', 'Reread: cooldown'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setRereadState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            rereadState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={() => (
                                    <ActivityDetailScreen
                                        awaitingDetail={awaitingDetail}
                                        pastYouState={pastYouState}
                                        rereadState={rereadState}
                                    />
                                )}
                                topbar={<ActivityTopbar />}
                            />
                        </div>
                    )}

                    {page === 'inbox' && (
                        <div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['populated', 'Populated'],
                                        ['empty', 'Nothing here yet'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setInboxState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            inboxState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={() => (
                                    <InboxScreen inboxState={inboxState} />
                                )}
                                topbar={<InboxTopbar />}
                            />
                        </div>
                    )}

                    {page === 'profile' && (
                        <div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['unset', 'No race set'],
                                        ['set', 'Race set'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setRaceState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            raceState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={() => (
                                    <ProfileScreen
                                        raceState={raceState}
                                        planState={planState}
                                        onNavigateRace={() => setPage('race')}
                                    />
                                )}
                                topbar={
                                    <ProfileTopbar onOpenInbox={openInbox} />
                                }
                            />
                        </div>
                    )}

                    {page === 'settings' && (
                        <div>
                            <div className="mb-2.5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['unset', 'Telegram: not connected'],
                                        ['connected', 'Telegram: connected'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setTgState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            tgState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['default', 'Zones: default'],
                                        ['strava', 'Zones: synced from Strava'],
                                        ['manual', 'Zones: manual'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setZoneSource(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            zoneSource === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <div className="mb-5 flex flex-wrap gap-2">
                                {(
                                    [
                                        ['ready', 'AI replan: ready'],
                                        ['cooldown', 'AI replan: cooldown'],
                                    ] as const
                                ).map(([s, label]) => (
                                    <button
                                        key={s}
                                        type="button"
                                        onClick={() => setAiReplanState(s)}
                                        className={cn(
                                            'rounded-full border px-3.5 py-1.5 text-xs font-semibold',
                                            aiReplanState === s
                                                ? 'border-ink bg-ink text-cream'
                                                : 'border-line-strong bg-white text-ink-2',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <Rack
                                render={(theme) => (
                                    <SettingsScreen
                                        tgState={tgState}
                                        zoneSource={zoneSource}
                                        appearance={
                                            theme === 'system' ? 'auto' : theme
                                        }
                                        aiReplanState={aiReplanState}
                                        onTriggerAiReplan={triggerAiReplan}
                                    />
                                )}
                                topbar={
                                    <SettingsTopbar onOpenInbox={openInbox} />
                                }
                            />
                        </div>
                    )}

                    {page !== 'login' &&
                        page !== 'onboarding' &&
                        page !== 'today' &&
                        page !== 'plan' &&
                        page !== 'race' &&
                        page !== 'trends' &&
                        page !== 'history' &&
                        page !== 'activity' &&
                        page !== 'inbox' &&
                        page !== 'profile' &&
                        page !== 'settings' && (
                            <div className="mx-auto max-w-5xl">
                                <NotBuilt
                                    label={
                                        PAGES.find((p) => p.key === page)!.label
                                    }
                                />
                            </div>
                        )}
                </motion.main>

                <nav
                    aria-label="Pages"
                    className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-background/95 px-2 py-1.5 backdrop-blur sm:hidden"
                >
                    <ul className="flex">
                        {PAGES.map((p) => (
                            <li key={p.key} className="flex-1">
                                <button
                                    type="button"
                                    aria-current={
                                        p.key === page ? 'page' : undefined
                                    }
                                    onClick={() => setPage(p.key)}
                                    className={cn(
                                        'flex w-full flex-col items-center gap-0.5 rounded-2xl px-2 py-2 text-[10px] font-semibold transition-colors',
                                        p.key === page
                                            ? 'bg-horizon/25 text-ink'
                                            : 'text-ink-3 hover:bg-cream-deep',
                                    )}
                                >
                                    <p.icon className="size-4" aria-hidden />
                                    {p.label}
                                </button>
                            </li>
                        ))}
                    </ul>
                </nav>
            </div>
        </MotionConfig>
    );
}
