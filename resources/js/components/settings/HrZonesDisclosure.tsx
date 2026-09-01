import { router, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useId, useRef, useState } from 'react';

import StravaAction from '@/components/StravaAction';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { usePendingPost } from '@/hooks/usePendingPost';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { cardVariants } from '@/lib/variants';

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

export interface HrZonesPayload {
    profile: HrProfile;
    source: ZoneSource;
    stravaSyncedLabel: string | null;
    canSyncFromStrava: boolean;
}

type ZoneBounds = Record<ZoneKey, number>;

/**
 * Derive Z1-Z5 lower bounds from max/resting HR. Each is
 * `round(resting + pct * (max - resting))`.
 */
export function deriveBounds(maxHr: number, restingHr: number): ZoneBounds {
    const reserve = maxHr - restingHr;
    const bounds = {} as ZoneBounds;
    ZONE_KEYS.forEach((key, index) => {
        bounds[key] = Math.round(restingHr + ZONE_BREAKPOINTS[index] * reserve);
    });
    return bounds;
}

/**
 * Widen the five lower bounds back into the `{lo, hi}` pairs the API takes.
 * Each zone's upper bound is the next zone's lower bound by definition — the
 * server enforces exactly that — and Z5 gets the open-ended sentinel.
 */
export function toZonePairs(bounds: ZoneBounds): Array<Record<string, number>> {
    return ZONE_KEYS.map((key, index) => ({
        lo: bounds[key],
        hi:
            index === ZONE_KEYS.length - 1
                ? Z5_SENTINEL_HI
                : bounds[ZONE_KEYS[index + 1]],
    }));
}

function boundsFromProfile(zones: HrZones): ZoneBounds {
    const bounds = {} as ZoneBounds;
    ZONE_KEYS.forEach((key) => {
        bounds[key] = zones[key].lo;
    });
    return bounds;
}

function collapsedCopy(hrZones: HrZonesPayload): string {
    if (hrZones.source === 'strava') {
        return hrZones.stravaSyncedLabel
            ? `Synced from Strava · last synced ${hrZones.stravaSyncedLabel}`
            : 'Synced from Strava';
    }
    if (hrZones.source === 'manual') {
        return "You've set your own zones";
    }
    return 'Using default estimates';
}

/**
 * A zone error the server reports on `zones.N.hi` is really a complaint about
 * the *next* zone's lower bound now that `hi` is derived rather than entered,
 * so it highlights zone N+1's field.
 */
function invalidBoundKeys(errors: Record<string, string>): Set<ZoneKey> {
    const invalid = new Set<ZoneKey>();
    Object.keys(errors).forEach((field) => {
        const match = /^zones\.(\d+)\.(lo|hi)$/.exec(field);
        if (!match) {
            return;
        }
        const index = Number(match[1]) + (match[2] === 'hi' ? 1 : 0);
        const key = ZONE_KEYS[index];
        if (key !== undefined) {
            invalid.add(key);
        }
    });
    return invalid;
}

/**
 * Inline HR-zone editing, on the prototype's shape: a collapsed-by-default
 * disclosure holding max/resting HR, an auto-calculate, and one bound per
 * zone.
 */
export default function HrZonesDisclosure({
    hrZones,
}: Readonly<{ hrZones: HrZonesPayload }>) {
    const [open, setOpen] = useState(false);
    const { profile, source, canSyncFromStrava } = hrZones;

    const [maxHr, setMaxHr] = useState<number>(profile.max_hr);
    const [restingHr, setRestingHr] = useState<number>(profile.resting_hr);
    const [bounds, setBounds] = useState<ZoneBounds>(() =>
        boundsFromProfile(profile.hr_zones),
    );

    const savedBounds = boundsFromProfile(profile.hr_zones);
    const isDirty =
        maxHr !== profile.max_hr ||
        restingHr !== profile.resting_hr ||
        ZONE_KEYS.some((key) => bounds[key] !== savedBounds[key]);

    const pageProps = usePage<{ errors?: Record<string, string> }>().props;
    const errors = pageProps.errors ?? {};
    const invalidBounds = invalidBoundKeys(errors);
    const hasZoneError = invalidBounds.size > 0;
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
        setBounds(deriveBounds(maxHr, restingHr));
    };

    const editBound = (key: ZoneKey, value: number) => {
        setBounds((prev) => ({ ...prev, [key]: value }));
    };

    // Reset and resync change the zones server-side; a scoped reload of just
    // this prop re-seeds the form from the new profile without disturbing
    // whatever else is live on the rest of the Settings page.
    const resetToDefault = () => {
        router.delete('/settings/zones', {
            onSuccess: () => router.reload({ only: ['hrZones'] }),
        });
    };

    const [resyncing, resyncFromStrava] = usePendingPost(
        '/settings/zones/resync-strava',
        {
            onSuccess: () => router.reload({ only: ['hrZones'] }),
        },
    );

    const canShowResync = canSyncFromStrava && source === 'manual';
    const canShowReset = source !== 'default';

    const submit = () => {
        router.patch(
            '/settings/zones',
            {
                max_hr: maxHr,
                resting_hr: restingHr,
                zones: toZonePairs(bounds),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setJustSaved(true);
                    if (savedFlashTimeoutRef.current !== null) {
                        window.clearTimeout(savedFlashTimeoutRef.current);
                    }
                    savedFlashTimeoutRef.current = window.setTimeout(
                        () => setJustSaved(false),
                        SAVED_FLASH_MS,
                    );
                },
            },
        );
    };

    return (
        <div
            className={cn(cardVariants({ padding: 'none' }), 'overflow-hidden')}
        >
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                className="pressable focus-ring flex w-full items-center gap-2.5 p-4 text-left transition hover:bg-cream-deep/40"
            >
                <Icon
                    icon="mdi:heart-pulse"
                    width={19}
                    height={19}
                    className="shrink-0 text-icon-accent"
                    aria-hidden
                />
                <span className="min-w-0 flex-1">
                    <span className="block font-sans text-sm font-bold text-foreground">
                        Heart-rate zones
                    </span>
                    <span className="mt-0.5 block font-sans text-xs text-text-3">
                        {collapsedCopy(hrZones)}
                    </span>
                </span>
                <Icon
                    icon="mdi:chevron-down"
                    width={18}
                    height={18}
                    className={cn(
                        'shrink-0 text-text-3 transition-transform',
                        open && 'rotate-180',
                    )}
                    aria-hidden
                />
            </button>

            {open && (
                <div className="border-t border-border-strong px-4 pb-4">
                    <div className="mt-3.5 grid grid-cols-2 gap-2.5 min-[900px]:grid-cols-4">
                        <NumberField
                            label="Max HR"
                            value={maxHr}
                            error={errors.max_hr}
                            onChange={setMaxHr}
                        />
                        <NumberField
                            label="Resting HR"
                            value={restingHr}
                            error={errors.resting_hr}
                            onChange={setRestingHr}
                        />
                    </div>

                    <div className="mt-2.5">
                        <PillButton
                            tone="ghost"
                            size="sm"
                            onClick={applyDerived}
                        >
                            Auto-calculate
                        </PillButton>
                    </div>

                    <div className="mt-3.5 mb-1">
                        {ZONE_KEYS.map((key) => (
                            <div
                                key={key}
                                className="flex items-center justify-between gap-2.5 py-1.5"
                            >
                                <Eyebrow
                                    as="span"
                                    token="micro"
                                    tone="ink-2"
                                    className="w-24 flex-none truncate"
                                >
                                    {ZONE_LABEL[key]}
                                </Eyebrow>
                                <BoundInput
                                    label={`${ZONE_LABEL[key]} lower bound`}
                                    testId={`zone-${key}-lo`}
                                    value={bounds[key]}
                                    invalid={invalidBounds.has(key)}
                                    describedBy={
                                        hasZoneError ? zonesErrorId : undefined
                                    }
                                    onChange={(v) => editBound(key, v)}
                                />
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
                                className="mb-2.5 rounded-lg border border-ember/30 bg-ember/[0.08] px-3 py-2 font-sans text-xs text-ember-ink"
                            >
                                Each zone has to start above the one before it,
                                and Z1 no lower than your resting HR.
                            </motion.p>
                        )}
                    </AnimatePresence>

                    <div className="mt-2.5 flex gap-2">
                        <PillButton
                            tone="horizon"
                            size="sm"
                            className="flex-1 justify-center"
                            onClick={submit}
                            disabled={processing || !isDirty}
                        >
                            Save zones
                        </PillButton>
                        {canShowReset && (
                            <PillButton
                                tone="ghost"
                                size="sm"
                                className="flex-1 justify-center"
                                onClick={resetToDefault}
                            >
                                Reset to default
                            </PillButton>
                        )}
                    </div>

                    {canShowResync && (
                        <div className="mt-1">
                            <StravaAction>
                                <PillButton
                                    tone="outline"
                                    size="sm"
                                    className="w-full justify-center"
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
                        </div>
                    )}

                    <AnimatePresence>
                        {justSaved && (
                            <motion.span
                                variants={fadeInUp}
                                initial="hidden"
                                animate="visible"
                                exit="hidden"
                                role="status"
                                className="mt-2.5 inline-flex items-center gap-1.5 text-sm font-semibold text-leaf-ink"
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
            )}
        </div>
    );
}

interface NumberFieldProps {
    label: string;
    value: number;
    error?: string;
    onChange: (value: number) => void;
}

function NumberField({
    label,
    value,
    error,
    onChange,
}: Readonly<NumberFieldProps>) {
    const errorId = useId();
    return (
        <label className="block">
            <Eyebrow as="span" token="micro" tone="ink-3" className="block">
                {label}
            </Eyebrow>
            <input
                type="number"
                inputMode="numeric"
                aria-label={label}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
                value={Number.isNaN(value) ? '' : value}
                onChange={(e) => onChange(Number.parseInt(e.target.value, 10))}
                className={cn(
                    'focus-ring mt-1 block w-full rounded-lg border bg-muted px-2.5 py-2 font-mono text-sm font-bold tabular-nums text-foreground motion-safe:transition-colors motion-safe:duration-150 focus:border-horizon',
                    error ? 'border-ember-deep' : 'border-border-strong',
                )}
            />
            {error && (
                <span
                    id={errorId}
                    role="alert"
                    className="mt-1 block font-sans text-xs text-ember-ink"
                >
                    {error}
                </span>
            )}
        </label>
    );
}

interface BoundInputProps {
    label: string;
    testId: string;
    value: number;
    invalid?: boolean;
    describedBy?: string;
    onChange: (value: number) => void;
}

function BoundInput({
    label,
    testId,
    value,
    invalid,
    describedBy,
    onChange,
}: Readonly<BoundInputProps>) {
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
                'focus-ring w-22 rounded-lg border bg-muted px-2.5 py-2 text-center font-mono text-sm font-bold tabular-nums text-foreground motion-safe:transition-colors motion-safe:duration-150 focus:border-horizon',
                invalid ? 'border-ember-deep' : 'border-border-strong',
            )}
        />
    );
}
