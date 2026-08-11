import { Icon } from '@iconify/react';
import { Head, router, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useId, useRef, useState } from 'react';

import StravaAction from '@/components/StravaAction';
import BackLink from '@/components/ui/BackLink';
import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import { usePendingPost } from '@/hooks/usePendingPost';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

const SAVED_FLASH_MS = 2000;

const ZONE_KEYS = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const;
type ZoneKey = (typeof ZONE_KEYS)[number];

/**
 * Karvonen %HRR breakpoints, mirrored from {@link UpdateHrZonesRequest} so the
 * "auto-calculate" result matches the server derivation byte for byte.
 */
const ZONE_BREAKPOINTS = [0.488, 0.664, 0.792, 0.904, 0.968] as const;
const Z5_SENTINEL_HI = 999;

const ZONE_LABEL: Record<ZoneKey, string> = {
    Z1: 'Z1 · Recovery',
    Z2: 'Z2 · Easy',
    Z3: 'Z3 · Aerobic',
    Z4: 'Z4 · Threshold',
    Z5: 'Z5 · Max',
};

type ZoneSource = 'default' | 'strava' | 'manual';

const SOURCE_INFO: Record<
    ZoneSource,
    { icon: string; iconClass: string; label: string; description: string }
> = {
    default: {
        icon: 'mdi:tune-variant',
        iconClass: 'text-ink-3',
        label: 'Default zones',
        description: "You're still on the default zones. Make your own below.",
    },
    strava: {
        icon: 'mdi:cloud-check-variant-outline',
        iconClass: 'text-leaf-deep',
        label: 'Synced from Strava',
        description:
            'These zones sync automatically from Strava. Edit them manually below if you want to set your own.',
    },
    manual: {
        icon: 'mdi:pencil-outline',
        iconClass: 'text-ink-2',
        label: 'Set manually',
        description: "You've set your own zones. Change them anytime below.",
    },
};

interface Zone {
    lo: number;
    hi: number;
}

type HrZones = Record<ZoneKey, Zone>;

interface HrProfile {
    max_hr: number;
    resting_hr: number;
    hr_zones: HrZones;
    optimal_cadence_spm: number;
}

interface HrZonesProps {
    profile: HrProfile;
    hasCustomProfile: boolean;
    source?: ZoneSource;
    stravaSyncedLabel?: string | null;
    canSyncFromStrava?: boolean;
}

/**
 * Derive Z1-Z5 bands from max/resting HR. Each zone's `lo` is
 * `round(resting + pct * (max - resting))`; its `hi` is the next zone's `lo`,
 * with Z5's `hi` fixed at the open-ended sentinel.
 */
export function deriveZones(maxHr: number, restingHr: number): HrZones {
    const reserve = maxHr - restingHr;
    const los = ZONE_BREAKPOINTS.map((pct) =>
        Math.round(restingHr + pct * reserve),
    );

    const zones = {} as HrZones;
    ZONE_KEYS.forEach((key, index) => {
        const isLast = index === ZONE_KEYS.length - 1;
        zones[key] = {
            lo: los[index],
            hi: isLast ? Z5_SENTINEL_HI : los[index + 1],
        };
    });

    return zones;
}

export default function HrZones({
    profile,
    source = 'default',
    stravaSyncedLabel = null,
    canSyncFromStrava = false,
}: Readonly<HrZonesProps>) {
    const [maxHr, setMaxHr] = useState<number>(profile.max_hr);
    const [restingHr, setRestingHr] = useState<number>(profile.resting_hr);
    const [zones, setZones] = useState<HrZones>(profile.hr_zones);

    const isDirty =
        maxHr !== profile.max_hr ||
        restingHr !== profile.resting_hr ||
        ZONE_KEYS.some(
            (key) =>
                zones[key].lo !== profile.hr_zones[key].lo ||
                zones[key].hi !== profile.hr_zones[key].hi,
        );

    const pageProps = usePage<{ errors?: Record<string, string> }>().props;
    const errors = pageProps.errors ?? {};
    const hasZoneError = Object.keys(errors).some((k) => k.startsWith('zones'));
    const zonesErrorId = useId();
    const [processing, setProcessing] = useState(false);
    const [justSaved, setJustSaved] = useState(false);
    const savedFlashTimeoutRef = useRef<number | null>(null);

    useEffect(() => {
        return () => {
            if (savedFlashTimeoutRef.current !== null) {
                window.clearTimeout(savedFlashTimeoutRef.current);
            }
        };
    }, []);

    const applyDerived = () => {
        setZones(deriveZones(maxHr, restingHr));
    };

    const editBoundary = (key: ZoneKey, field: keyof Zone, value: number) => {
        setZones((prev) => ({
            ...prev,
            [key]: { ...prev[key], [field]: value },
        }));
    };

    // Reset and resync change the zones server-side, so hard-refresh afterwards
    // to re-seed the whole form from the new profile — simpler and more robust
    // than reconciling this page's local state against the incoming props.
    const resetToDefault = () => {
        router.delete('/settings/zones', {
            onSuccess: () => window.location.reload(),
        });
    };

    const [resyncing, resyncFromStrava] = usePendingPost(
        '/settings/zones/resync-strava',
        {
            onSuccess: () => window.location.reload(),
        },
    );

    const canShowResync = canSyncFromStrava && source === 'manual';

    const submit = () => {
        router.patch(
            '/settings/zones',
            {
                max_hr: maxHr,
                resting_hr: restingHr,
                zones: ZONE_KEYS.map((key) => ({
                    lo: zones[key].lo,
                    hi: zones[key].hi,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setJustSaved(true);
                    savedFlashTimeoutRef.current = window.setTimeout(
                        () => setJustSaved(false),
                        SAVED_FLASH_MS,
                    );
                },
            },
        );
    };

    return (
        <>
            <Head title="Settings · HR Zones" />
            <PageContainer>
                <header>
                    {/* Now points at the real parent. It used to read as a trail
                        ("Aku · Pengaturan") while hrefing straight to /profil,
                        skipping the page it came from. */}
                    <PageHero
                        size="md"
                        italic
                        eyebrow={
                            <BackLink
                                href="/settings"
                                className="mb-4 hidden lg:inline-flex"
                            >
                                Settings
                            </BackLink>
                        }
                    >
                        Your heart rate zones.
                    </PageHero>
                    <p className="mt-2 max-w-xl font-sans text-sm leading-relaxed text-ink-2">
                        {SOURCE_INFO[source].description}
                    </p>
                    <div className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="inline-flex items-center gap-2 rounded-full bg-sky/[0.06] px-3.5 py-2 font-mono text-[11px] font-bold uppercase tracking-[0.1em] text-ink-2">
                            <Icon
                                icon={SOURCE_INFO[source].icon}
                                width={13}
                                height={13}
                                aria-hidden
                                className={cn(
                                    'shrink-0',
                                    SOURCE_INFO[source].iconClass,
                                )}
                            />
                            {SOURCE_INFO[source].label}
                        </span>
                        {source === 'strava' && stravaSyncedLabel && (
                            <span className="text-meta">
                                · last synced {stravaSyncedLabel}
                            </span>
                        )}
                    </div>
                    {source !== 'default' && (
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            {canShowResync && (
                                <StravaAction>
                                    <PillButton
                                        tone="outline"
                                        size="sm"
                                        onClick={resyncFromStrava}
                                        disabled={resyncing}
                                    >
                                        <Icon
                                            icon={
                                                resyncing
                                                    ? 'mdi:loading'
                                                    : 'mdi:sync'
                                            }
                                            width={14}
                                            height={14}
                                            className={
                                                resyncing
                                                    ? 'animate-spin'
                                                    : undefined
                                            }
                                            aria-hidden
                                        />
                                        {resyncing
                                            ? 'Syncing…'
                                            : 'Resync from Strava'}
                                    </PillButton>
                                </StravaAction>
                            )}
                            <PillButton
                                tone="outline"
                                size="sm"
                                onClick={resetToDefault}
                            >
                                <Icon
                                    icon="mdi:backup-restore"
                                    width={14}
                                    height={14}
                                    aria-hidden
                                />
                                Reset to default zones
                            </PillButton>
                        </div>
                    )}
                </header>

                <Card as="section" padding="lg" className="mt-8 shadow-sm">
                    <SectionLabel>Max & Resting HR</SectionLabel>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <NumberField
                            label="Max HR"
                            suffix="bpm"
                            value={maxHr}
                            error={errors.max_hr}
                            onChange={setMaxHr}
                        />
                        <NumberField
                            label="Resting HR"
                            suffix="bpm"
                            value={restingHr}
                            error={errors.resting_hr}
                            onChange={setRestingHr}
                        />
                    </div>

                    <div className="mt-5 flex flex-wrap items-center gap-3">
                        <PillButton
                            tone="outline"
                            size="sm"
                            onClick={applyDerived}
                        >
                            <Icon
                                icon="mdi:calculator-variant-outline"
                                width={14}
                                height={14}
                                aria-hidden
                            />
                            Auto-calculate from Max & Resting
                        </PillButton>
                    </div>
                    <p className="mt-3 max-w-xl font-sans text-xs leading-relaxed text-ink-3">
                        I use the %HRR (Karvonen) formula as a starting point:
                        it works out your zones from your resting and max heart
                        rate. If you've already got your own numbers, just edit
                        them manually below.
                    </p>
                </Card>

                <div data-coachmark="hrzones-editor" className="mt-6">
                    <Card as="section" padding="lg" className="shadow-sm">
                        <SectionLabel>Your zones</SectionLabel>
                        <p className="mb-4 font-sans text-xs text-ink-3">
                            Each upper bound should match the next zone's lower
                            bound, so there are no gaps.
                        </p>
                        <div className="grid gap-3">
                            {ZONE_KEYS.map((key) => (
                                <div
                                    key={key}
                                    className="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-3"
                                >
                                    <Eyebrow
                                        as="span"
                                        token="micro"
                                        tone="ink-2"
                                        className="truncate"
                                    >
                                        {ZONE_LABEL[key]}
                                    </Eyebrow>
                                    <BoundaryInput
                                        label={`${key} lower bound`}
                                        testId={`zone-${key}-lo`}
                                        value={zones[key].lo}
                                        invalid={hasZoneError}
                                        describedBy={
                                            hasZoneError
                                                ? zonesErrorId
                                                : undefined
                                        }
                                        onChange={(v) =>
                                            editBoundary(key, 'lo', v)
                                        }
                                    />
                                    {key === 'Z5' ? (
                                        <span
                                            data-testid="zone-Z5-hi"
                                            aria-label="Z5 upper bound: unbounded"
                                            title="The top zone has no upper bound"
                                            className="flex h-[38px] w-20 items-center justify-center rounded-lg border border-cream-deep bg-surface-sunken font-mono text-sm text-ink-3"
                                        >
                                            ∞
                                        </span>
                                    ) : (
                                        <BoundaryInput
                                            label={`${key} upper bound`}
                                            testId={`zone-${key}-hi`}
                                            value={zones[key].hi}
                                            invalid={hasZoneError}
                                            describedBy={
                                                hasZoneError
                                                    ? zonesErrorId
                                                    : undefined
                                            }
                                            onChange={(v) =>
                                                editBoundary(key, 'hi', v)
                                            }
                                        />
                                    )}
                                </div>
                            ))}
                        </div>
                        <AnimatePresence>
                            {hasZoneError && (
                                <motion.p
                                    id={zonesErrorId}
                                    role="alert"
                                    variants={fadeInUp}
                                    initial="hidden"
                                    animate="visible"
                                    exit="hidden"
                                    className="mt-3 font-sans text-xs text-ember-deep"
                                >
                                    Some zones don't line up. Double-check the
                                    upper and lower bounds.
                                </motion.p>
                            )}
                        </AnimatePresence>
                    </Card>
                </div>

                <p className="mt-5 font-sans text-sm text-ink-2">
                    These zones apply to every run from now on.
                </p>

                <div
                    className="mt-5 flex items-center gap-3"
                    data-coachmark="hrzones-save"
                >
                    <PillButton
                        tone="sky"
                        onClick={submit}
                        disabled={processing || !isDirty}
                    >
                        <Icon
                            icon="mdi:content-save-outline"
                            width={16}
                            height={16}
                            aria-hidden
                        />
                        Save zones
                    </PillButton>
                    <AnimatePresence>
                        {justSaved && (
                            <motion.span
                                variants={fadeInUp}
                                initial="hidden"
                                animate="visible"
                                exit="hidden"
                                role="status"
                                className="inline-flex items-center gap-1.5 text-sm font-semibold text-leaf-deep"
                            >
                                <Icon
                                    icon="mdi:check-circle-outline"
                                    width={16}
                                    height={16}
                                    aria-hidden
                                />
                                Saved
                            </motion.span>
                        )}
                    </AnimatePresence>
                </div>
            </PageContainer>
        </>
    );
}

interface NumberFieldProps {
    label: string;
    suffix?: string;
    value: number;
    error?: string;
    onChange: (value: number) => void;
}

function NumberField({
    label,
    suffix,
    value,
    error,
    onChange,
}: Readonly<NumberFieldProps>) {
    const errorId = useId();
    return (
        <label className="block">
            <Eyebrow
                as="span"
                token="micro"
                tone="ink-3"
                className="mb-1.5 block"
            >
                {label}
            </Eyebrow>
            <span
                className={cn(
                    'flex items-center gap-2 rounded-xl border bg-cream px-4 py-2.5 motion-safe:transition-colors motion-safe:duration-150 focus-within:border-horizon',
                    error ? 'border-ember-deep' : 'border-cream-deep',
                )}
            >
                <input
                    type="number"
                    inputMode="numeric"
                    aria-label={label}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? errorId : undefined}
                    value={Number.isNaN(value) ? '' : value}
                    onChange={(e) =>
                        onChange(Number.parseInt(e.target.value, 10))
                    }
                    className="w-full bg-transparent font-mono text-base font-semibold tabular-nums text-ink outline-none"
                />
                {suffix && (
                    <span className="font-mono text-[11px] text-ink-3">
                        {suffix}
                    </span>
                )}
            </span>
            {error && (
                <span
                    id={errorId}
                    role="alert"
                    className="mt-1 block font-sans text-xs text-ember-deep"
                >
                    {error}
                </span>
            )}
        </label>
    );
}

interface BoundaryInputProps {
    label: string;
    testId: string;
    value: number;
    invalid?: boolean;
    describedBy?: string;
    onChange: (value: number) => void;
}

function BoundaryInput({
    label,
    testId,
    value,
    invalid,
    describedBy,
    onChange,
}: Readonly<BoundaryInputProps>) {
    return (
        <input
            type="number"
            inputMode="numeric"
            aria-label={label}
            aria-invalid={invalid ? true : undefined}
            aria-describedby={describedBy}
            data-testid={testId}
            value={Number.isNaN(value) ? '' : value}
            onChange={(e) => onChange(Number.parseInt(e.target.value, 10))}
            className={cn(
                'focus-ring w-20 rounded-lg border bg-cream px-3 py-2 text-center font-mono text-sm font-semibold tabular-nums text-ink motion-safe:transition-colors motion-safe:duration-150 focus:border-horizon',
                invalid ? 'border-ember-deep' : 'border-cream-deep',
            )}
        />
    );
}

HrZones.layout = appLayout;
