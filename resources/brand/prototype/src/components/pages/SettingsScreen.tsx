import { motion } from 'framer-motion';
import {
    Bot,
    ChevronDown,
    ChevronRight,
    FileText,
    Flag,
    HeartPulse,
    Layers,
    LogOut,
    Monitor,
    Moon,
    RefreshCw,
    RotateCcw,
    Send,
    Shield,
    Smartphone,
    Sprout,
    Sun,
    Target,
    TriangleAlert,
    Trophy,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';

import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { pressShrink } from '@/lib/motion';
import { cn } from '@/lib/utils';

import { AiReplanPill } from './AiReplanPill';
import {
    DayCell,
    DayRow,
    IconChoiceCard,
    SessionsDial,
} from './PreferenceControls';

const ZONE_BOUNDS = [
    { label: 'Z1 · recovery', value: '118 bpm' },
    { label: 'Z2 · aerobic', value: '138 bpm' },
    { label: 'Z3 · tempo', value: '153 bpm' },
    { label: 'Z4 · threshold', value: '164 bpm' },
    { label: 'Z5 · max', value: '175 bpm' },
] as const;

const DATA_USE_ITEMS = [
    "your runs and vitals train temari's narration — never sold, never shared with advertisers.",
    "ai narration runs through azure openai; raw data isn't used to train anyone else's model.",
    'disconnecting strava or deleting your account stops new data from coming in.',
] as const;

const LEGAL_LINKS = [
    { label: 'terms of use', icon: FileText },
    { label: 'privacy policy', icon: Shield },
    { label: 'how temari uses ai', icon: Bot },
    { label: 'training disclaimer', icon: TriangleAlert },
] as const;

const EXPERIENCE_OPTIONS = [
    {
        key: 'new',
        label: 'new to running',
        description: 'first few months, learning the ropes',
        icon: Sprout,
    },
    {
        key: 'returning',
        label: 'getting back into it',
        description: 'coming back after time off',
        icon: RotateCcw,
    },
    {
        key: 'experienced',
        label: 'experienced',
        description: 'know your paces, chasing more',
        icon: Trophy,
    },
] as const;

const SESSIONS_OPTIONS = [2, 3, 4, 5, 6] as const;

const GOAL_OPTIONS = [
    {
        key: 'consistent',
        label: 'stay consistent',
        description: 'show up steady, week after week',
        icon: Target,
    },
    {
        key: 'race',
        label: 'chase a race time',
        description: 'training toward a real finish time',
        icon: Flag,
    },
    {
        key: 'base',
        label: 'build a base',
        description: 'stack easy miles, no pressure yet',
        icon: Layers,
    },
    {
        key: 'return',
        label: 'ease back in',
        description: 'rebuilding gently after a break',
        icon: Undo2,
    },
] as const;

const DAY_OPTIONS = [
    { key: 'mon', label: 'mon' },
    { key: 'tue', label: 'tue' },
    { key: 'wed', label: 'wed' },
    { key: 'thu', label: 'thu' },
    { key: 'fri', label: 'fri' },
    { key: 'sat', label: 'sat' },
    { key: 'sun', label: 'sun' },
] as const;

function SettingsToggle({
    defaultOn = true,
    label,
}: Readonly<{ defaultOn?: boolean; label: string }>) {
    const [on, setOn] = useState(defaultOn);
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={label}
            onClick={() => setOn((v) => !v)}
            className={cn(
                'relative h-6 w-10 flex-none rounded-full border-none p-0 transition-colors',
                on ? 'bg-horizon' : 'bg-border-strong',
            )}
        >
            <i
                className={cn(
                    'absolute top-0.75 size-4.5 rounded-full bg-white shadow-e1 transition-[left]',
                    on ? 'left-[19px]' : 'left-0.75',
                )}
            />
        </button>
    );
}

function AppearanceCard({
    defaultValue,
}: Readonly<{ defaultValue: 'light' | 'dark' | 'auto' }>) {
    const [value, setValue] = useState<string[]>([defaultValue]);
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-3 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.07em] text-foreground uppercase">
                theme
            </div>
            <ToggleGroup
                value={value}
                onValueChange={setValue}
                variant="outline"
                spacing={0}
                className="w-full [&>*]:flex-1"
            >
                <ToggleGroupItem
                    value="light"
                    className="gap-1.5 text-xs font-semibold"
                >
                    <Sun className="size-3.5" aria-hidden />
                    light
                </ToggleGroupItem>
                <ToggleGroupItem
                    value="dark"
                    className="gap-1.5 text-xs font-semibold"
                >
                    <Moon className="size-3.5" aria-hidden />
                    dark
                </ToggleGroupItem>
                <ToggleGroupItem
                    value="auto"
                    className="gap-1.5 text-xs font-semibold"
                >
                    <Monitor className="size-3.5" aria-hidden />
                    auto
                </ToggleGroupItem>
            </ToggleGroup>
        </div>
    );
}

function NotificationsCard({
    tgState,
}: Readonly<{ tgState: 'unset' | 'connected' }>) {
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-3 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.07em] text-foreground uppercase">
                what gets sent
            </div>
            <div className="flex items-center justify-between gap-3">
                <div>
                    <b className="block text-[13px] leading-[1.2] font-bold text-foreground">
                        keep me posted
                    </b>
                    <span className="mt-0.5 block text-[11px] leading-[1.4] text-foreground">
                        post-run recaps, weekly + monthly summaries, streak
                        nudges
                    </span>
                </div>
                <SettingsToggle label="Toggle notifications" />
            </div>

            <div className="my-4 h-px bg-border-strong" />

            <div className="mb-3 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.07em] text-foreground uppercase">
                where it goes
            </div>

            {tgState === 'unset' ? (
                <a
                    href="#"
                    className="flex items-center gap-2.5 py-2.5 text-foreground no-underline"
                >
                    <Send
                        className="size-[18px] flex-none text-foreground"
                        aria-hidden
                    />
                    <div className="min-w-0 flex-1">
                        <b className="block text-[12.5px] leading-[1.2] font-bold">
                            telegram
                        </b>
                        <span className="mt-px block text-[10.5px] leading-[1.2] text-foreground">
                            not connected
                        </span>
                    </div>
                    <ChevronRight
                        className="size-4 flex-none text-foreground"
                        aria-hidden
                    />
                </a>
            ) : (
                <>
                    <div className="flex items-center gap-2.5 py-2.5">
                        <Send
                            className="size-[18px] flex-none text-foreground"
                            aria-hidden
                        />
                        <div className="min-w-0 flex-1">
                            <b className="block text-[12.5px] leading-[1.2] font-bold text-foreground">
                                telegram
                            </b>
                            <span className="mt-px block text-[10.5px] leading-[1.2] font-semibold text-icon-accent">
                                active · @nukiprtm
                            </span>
                        </div>
                        <SettingsToggle label="Toggle telegram notifications" />
                    </div>
                    <button
                        type="button"
                        className="-mt-0.5 mb-2 ml-[28px] border-none bg-transparent p-0 font-sans text-[11px] leading-[1.2] font-bold text-destructive"
                    >
                        disconnect telegram
                    </button>
                </>
            )}

            <div className="flex items-center gap-2.5 py-2.5">
                <Smartphone
                    className="size-[18px] flex-none text-foreground"
                    aria-hidden
                />
                <div className="min-w-0 flex-1">
                    <b className="block text-[12.5px] leading-[1.2] font-bold text-foreground">
                        push
                    </b>
                    <span className="mt-px block text-[10.5px] leading-[1.2] font-semibold text-icon-accent">
                        active
                    </span>
                </div>
                <SettingsToggle label="Toggle push notifications" />
            </div>

            <button
                type="button"
                className="mt-3.5 w-full rounded-[10px] border border-border-strong bg-transparent py-2.5 font-sans text-xs leading-[1.2] font-bold text-foreground"
            >
                send test notification
            </button>
        </div>
    );
}

function TrainingPreferencesCard({
    aiReplanState,
    onTriggerAiReplan,
}: Readonly<{
    aiReplanState: 'ready' | 'cooldown';
    onTriggerAiReplan: () => void;
}>) {
    const [experience, setExperience] =
        useState<(typeof EXPERIENCE_OPTIONS)[number]['key']>('experienced');
    const [sessions, setSessions] = useState(5);
    const [goal, setGoal] =
        useState<(typeof GOAL_OPTIONS)[number]['key']>('consistent');
    const [days, setDays] = useState<string[]>([
        'mon',
        'tue',
        'thu',
        'sat',
        'sun',
    ]);
    const [longRunDay, setLongRunDay] = useState('sat');

    const toggleDay = (key: string) => {
        setDays((prev) => {
            if (prev.includes(key)) {
                return prev.filter((d) => d !== key);
            }
            if (prev.length >= sessions) {
                return prev;
            }
            return [...prev, key];
        });
    };

    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-3 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.07em] text-foreground uppercase">
                training preferences
            </div>
            <p className="mb-3.5 text-[11px] leading-[1.4] text-foreground">
                set at onboarding — change them anytime.
            </p>

            <div className="mb-3.5">
                <div className="mb-1.5 font-mono text-[8.5px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    experience level
                </div>
                <div className="flex flex-col gap-1.5">
                    {EXPERIENCE_OPTIONS.map((o) => (
                        <IconChoiceCard
                            key={o.key}
                            icon={o.icon}
                            label={o.label}
                            description={o.description}
                            active={experience === o.key}
                            onClick={() => setExperience(o.key)}
                        />
                    ))}
                </div>
            </div>

            <div className="mb-3.5">
                <div className="mb-1.5 font-mono text-[8.5px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    sessions per week
                </div>
                <SessionsDial
                    options={SESSIONS_OPTIONS}
                    value={sessions}
                    onChange={setSessions}
                />
            </div>

            <div className="mb-3.5">
                <div className="mb-1.5 font-mono text-[8.5px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    training goal
                </div>
                <div className="flex flex-col gap-1.5">
                    {GOAL_OPTIONS.map((o) => (
                        <IconChoiceCard
                            key={o.key}
                            icon={o.icon}
                            label={o.label}
                            description={o.description}
                            active={goal === o.key}
                            onClick={() => setGoal(o.key)}
                        />
                    ))}
                </div>
            </div>

            <div className="mb-3.5">
                <div className="mb-1.5 font-mono text-[8.5px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    usual run days
                </div>
                <DayRow>
                    {DAY_OPTIONS.map((d) => (
                        <DayCell
                            key={d.key}
                            label={d.label}
                            active={days.includes(d.key)}
                            disabled={
                                !days.includes(d.key) && days.length >= sessions
                            }
                            onClick={() => toggleDay(d.key)}
                        />
                    ))}
                </DayRow>
            </div>

            <div className="mb-1 rounded-[12px] bg-muted p-2.5">
                <div className="mb-1.5 font-mono text-[8.5px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    which one's the long run?
                </div>
                <DayRow>
                    {DAY_OPTIONS.map((d) =>
                        days.includes(d.key) ? (
                            <DayCell
                                key={d.key}
                                label={d.label}
                                active
                                longRun={longRunDay === d.key}
                                onClick={() => setLongRunDay(d.key)}
                            />
                        ) : (
                            <div key={d.key} className="w-8" aria-hidden />
                        ),
                    )}
                </DayRow>
            </div>

            {aiReplanState === 'cooldown' ? (
                <div className="mt-4 flex justify-center">
                    <AiReplanPill />
                </div>
            ) : (
                <motion.button
                    type="button"
                    onClick={onTriggerAiReplan}
                    whileTap={pressShrink}
                    className="mt-4 w-full rounded-full bg-btn-primary-bg py-3 font-sans text-sm font-bold text-btn-primary-fg outline-none focus-visible:ring-3 focus-visible:ring-ring/30"
                >
                    save changes
                </motion.button>
            )}
        </div>
    );
}

function ZonesDisclosure({
    zoneSource,
}: Readonly<{ zoneSource: 'default' | 'strava' | 'manual' }>) {
    const SOURCE_LABELS = {
        default: 'using default estimates',
        strava: 'synced from strava · last synced 3 days ago',
        manual: "you've set your own zones",
    } as const;
    const sourceLabel = SOURCE_LABELS[zoneSource];

    return (
        <Collapsible className="mb-4 overflow-hidden rounded-[14px] border border-border-strong bg-card shadow-e1">
            <CollapsibleTrigger className="group flex w-full items-center gap-2.5 p-4 text-left">
                <HeartPulse
                    className="size-[19px] flex-none text-icon-accent"
                    aria-hidden
                />
                <div className="min-w-0 flex-1">
                    <b className="block text-[13px] leading-[1.2] font-bold text-foreground">
                        heart-rate zones
                    </b>
                    <span className="mt-0.5 block text-[10.5px] leading-[1.2] text-foreground">
                        {sourceLabel}
                    </span>
                </div>
                <ChevronDown
                    className="size-[18px] flex-none text-foreground transition-transform group-aria-expanded:rotate-180"
                    aria-hidden
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="border-t border-border-strong px-4 pb-4">
                <div className="mt-3.5 grid grid-cols-2 gap-2.5 @min-[900px]:grid-cols-4">
                    <label className="font-mono text-[9px] tracking-[.05em] text-foreground uppercase">
                        max hr
                        <input
                            type="number"
                            defaultValue={188}
                            className="mt-1.25 block w-full rounded-[10px] border border-border-strong bg-muted px-2.5 py-2.25 font-mono text-[13px] font-bold text-foreground"
                        />
                    </label>
                    <label className="font-mono text-[9px] tracking-[.05em] text-foreground uppercase">
                        resting hr
                        <input
                            type="number"
                            defaultValue={52}
                            className="mt-1.25 block w-full rounded-[10px] border border-border-strong bg-muted px-2.5 py-2.25 font-mono text-[13px] font-bold text-foreground"
                        />
                    </label>
                </div>
                <button
                    type="button"
                    className="mt-2.5 rounded-full bg-muted px-3.5 py-2.25 font-sans text-[11.5px] leading-[1.2] font-bold text-foreground"
                >
                    auto-calculate
                </button>

                <div className="mt-3.5 mb-1">
                    {ZONE_BOUNDS.map((z) => (
                        <div
                            key={z.label}
                            className="flex items-center justify-between gap-2.5 py-1.5"
                        >
                            <span className="w-24 flex-none text-xs font-semibold text-foreground">
                                {z.label}
                            </span>
                            <input
                                defaultValue={z.value}
                                className="w-22 rounded-[10px] border border-border-strong bg-muted px-2.5 py-2.25 text-center font-mono text-[13px] font-bold text-foreground"
                            />
                        </div>
                    ))}
                </div>

                <div className="mt-2.5 flex gap-2">
                    <button
                        type="button"
                        className="flex-1 rounded-full bg-btn-primary-bg px-3.5 py-2.25 font-sans text-[11.5px] leading-[1.2] font-bold text-btn-primary-fg"
                    >
                        save zones
                    </button>
                    <button
                        type="button"
                        className="flex-1 rounded-full bg-muted px-3.5 py-2.25 font-sans text-[11.5px] leading-[1.2] font-bold text-foreground"
                    >
                        reset to default
                    </button>
                </div>
                {zoneSource === 'manual' && (
                    <button
                        type="button"
                        className="mt-1 flex w-full items-center justify-center gap-1.5 rounded-full border border-border-strong bg-transparent py-2.25 font-sans text-[11.5px] leading-[1.2] font-bold text-foreground"
                    >
                        <RefreshCw className="size-3.5" aria-hidden />
                        resync from strava
                    </button>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function DataUseCard() {
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <ul className="m-0 list-disc space-y-1.5 pl-4.5 text-xs leading-[1.6] text-foreground">
                {DATA_USE_ITEMS.map((item) => (
                    <li key={item}>{item}</li>
                ))}
            </ul>
        </div>
    );
}

function LegalCard() {
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card px-4 shadow-e1">
            {LEGAL_LINKS.map((link, i) => (
                <a
                    key={link.label}
                    href="#"
                    className={cn(
                        'flex items-center justify-between gap-2 py-3.25 text-[12.5px] font-semibold text-foreground no-underline',
                        i !== LEGAL_LINKS.length - 1 &&
                            'border-b border-border-strong',
                    )}
                >
                    {link.label}
                    <ChevronRight
                        className="size-4 flex-none text-foreground"
                        aria-hidden
                    />
                </a>
            ))}
        </div>
    );
}

function AccountActions() {
    return (
        <div className="mt-1 mb-2 flex flex-col items-center gap-3 @min-[900px]:flex-row @min-[900px]:justify-center">
            <button
                type="button"
                className="flex w-full items-center justify-center gap-2 rounded-[10px] border border-border-strong bg-card py-3 font-sans text-[13px] leading-[1.2] font-bold text-foreground @min-[900px]:w-auto @min-[900px]:px-6"
            >
                <LogOut className="size-4" aria-hidden />
                log out
            </button>
            <button
                type="button"
                className="border-none bg-transparent p-1 font-sans text-[11.5px] leading-[1.2] font-bold text-destructive"
            >
                delete account
            </button>
        </div>
    );
}

export function SettingsScreen({
    tgState,
    zoneSource,
    appearance,
    aiReplanState,
    onTriggerAiReplan,
}: Readonly<{
    tgState: 'unset' | 'connected';
    zoneSource: 'default' | 'strava' | 'manual';
    appearance: 'light' | 'dark' | 'auto';
    aiReplanState: 'ready' | 'cooldown';
    onTriggerAiReplan: () => void;
}>) {
    return (
        <div className="px-4 pt-16 pb-7 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-22">
            <div className="mt-3 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.12em] text-foreground uppercase">
                settings
            </div>
            <h1 className="m-0 mt-2 mb-5 font-serif text-[26px] leading-[1.12] font-semibold text-foreground italic">
                tune it
                <br />
                <em className="text-icon-accent">your way.</em>
            </h1>

            <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                appearance
            </div>
            <AppearanceCard defaultValue={appearance} />

            <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                notifications
            </div>
            <NotificationsCard tgState={tgState} />

            <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                running
            </div>
            <TrainingPreferencesCard
                aiReplanState={aiReplanState}
                onTriggerAiReplan={onTriggerAiReplan}
            />
            <ZonesDisclosure zoneSource={zoneSource} />

            <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                how temari uses your data
            </div>
            <DataUseCard />

            <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                the fine print
            </div>
            <LegalCard />

            <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                account
            </div>
            <AccountActions />
        </div>
    );
}
