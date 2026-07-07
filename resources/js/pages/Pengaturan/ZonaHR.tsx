import { Head, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useMemo, useState } from 'react';
import AppShell from '@/layouts/AppShell';
import BackLink from '@/components/ui/BackLink';
import Card from '@/components/ui/Card';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';

const ZONE_KEYS = ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as const;
type ZoneKey = (typeof ZONE_KEYS)[number];

/**
 * Karvonen %HRR breakpoints, mirrored from {@link UpdateHrZonesRequest} so the
 * live preview matches the server derivation byte for byte.
 */
const ZONE_BREAKPOINTS = [0.488, 0.664, 0.792, 0.904, 0.968] as const;
const Z5_SENTINEL_HI = 999;

const ZONE_LABEL: Record<ZoneKey, string> = {
    Z1: 'Z1 · Pemulihan',
    Z2: 'Z2 · Ringan',
    Z3: 'Z3 · Aerobik',
    Z4: 'Z4 · Ambang',
    Z5: 'Z5 · Maksimal',
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

interface ZonaHRProps {
    profile: HrProfile;
    hasCustomProfile: boolean;
}

/**
 * Derive Z1-Z5 bands from max/resting HR. Each zone's `lo` is
 * `round(resting + pct * (max - resting))`; its `hi` is the next zone's `lo`,
 * with Z5's `hi` fixed at the open-ended sentinel.
 */
export function deriveZones(maxHr: number, restingHr: number): HrZones {
    const reserve = maxHr - restingHr;
    const los = ZONE_BREAKPOINTS.map((pct) => Math.round(restingHr + pct * reserve));

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

export default function ZonaHR({ profile, hasCustomProfile }: Readonly<ZonaHRProps>) {
    const [maxHr, setMaxHr] = useState<number>(profile.max_hr);
    const [restingHr, setRestingHr] = useState<number>(profile.resting_hr);
    const [zones, setZones] = useState<HrZones>(profile.hr_zones);

    const derived = useMemo(() => deriveZones(maxHr, restingHr), [maxHr, restingHr]);

    const pageProps = usePage<{ errors?: Record<string, string> }>().props;
    const errors = pageProps.errors ?? {};
    const [processing, setProcessing] = useState(false);

    const applyDerived = () => {
        setZones(deriveZones(maxHr, restingHr));
    };

    const editBoundary = (key: ZoneKey, field: keyof Zone, value: number) => {
        setZones((prev) => ({ ...prev, [key]: { ...prev[key], [field]: value } }));
    };

    const submit = () => {
        router.patch(
            '/pengaturan/zona',
            {
                max_hr: maxHr,
                resting_hr: restingHr,
                zones: ZONE_KEYS.map((key) => ({ lo: zones[key].lo, hi: zones[key].hi })),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <AppShell>
            <Head title="Pengaturan · Zona HR" />
            <PageContainer>
                <header>
                    <BackLink href="/profil" className="mb-4">
                        Aku · Pengaturan
                    </BackLink>
                    <SectionLabel dot dotClass="bg-horizon">
                        Pengaturan
                    </SectionLabel>
                    <h1 className="font-display italic text-display-md text-ink">
                        Zona Heart Rate kamu.
                    </h1>
                    <p className="mt-2 max-w-xl font-sans text-sm leading-relaxed text-ink-2">
                        {hasCustomProfile
                            ? 'Kamu udah punya zona custom. Ubah kapan aja di bawah.'
                            : 'Sekarang masih pakai zona standar. Bikin punyamu sendiri di bawah.'}
                    </p>
                </header>

                <Card as="section" padding="lg" className="mt-8">
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
                        <PillButton tone="outline" size="sm" onClick={applyDerived}>
                            <Icon icon="mdi:calculator-variant-outline" width={14} height={14} aria-hidden />
                            Hitung otomatis dari Max & Resting
                        </PillButton>
                    </div>
                    <p className="mt-3 max-w-xl font-sans text-xs leading-relaxed text-ink-3">
                        Aku pakai rumus %HRR (Karvonen) sebagai titik awal: ngitung zona dari detak
                        jantung istirahat sama maksimalmu. Kalau kamu udah punya angka sendiri, tinggal
                        ubah manual di bawah.
                    </p>
                </Card>

                <section className="mt-6">
                    <SectionLabel>Preview zona (otomatis)</SectionLabel>
                    <div className="grid gap-2.5">
                        {ZONE_KEYS.map((key) => (
                            <div
                                key={key}
                                data-testid={`preview-${key}`}
                                className="flex items-center justify-between rounded-xl border border-cream-deep bg-surface-card px-4 py-3"
                            >
                                <span className="font-mono text-[11px] uppercase tracking-[0.12em] text-ink-3">
                                    {ZONE_LABEL[key]}
                                </span>
                                <span className="font-mono text-sm font-semibold tabular-nums text-ink">
                                    {derived[key].lo}
                                    <span className="text-ink-3"> – </span>
                                    {key === 'Z5' ? `${derived[key].lo}+` : derived[key].hi}
                                    <span className="ml-1 text-[11px] font-normal text-ink-3">bpm</span>
                                </span>
                            </div>
                        ))}
                    </div>
                </section>

                <Card as="section" padding="lg" className="mt-6">
                    <SectionLabel>Atur manual (opsional)</SectionLabel>
                    <p className="mb-4 font-sans text-xs text-ink-3">
                        Tiap batas atas harus sama dengan batas bawah zona berikutnya, biar nggak ada celah.
                    </p>
                    <div className="grid gap-3">
                        {ZONE_KEYS.map((key) => (
                            <div key={key} className="grid grid-cols-[1fr_auto_auto] items-center gap-3">
                                <span className="font-mono text-[11px] uppercase tracking-[0.12em] text-ink-2">
                                    {ZONE_LABEL[key]}
                                </span>
                                <BoundaryInput
                                    label={`${key} batas bawah`}
                                    testId={`zone-${key}-lo`}
                                    value={zones[key].lo}
                                    onChange={(v) => editBoundary(key, 'lo', v)}
                                />
                                {key === 'Z5' ? (
                                    <span
                                        data-testid="zone-Z5-hi"
                                        aria-label="Z5 batas atas: tanpa batas"
                                        title="Zona teratas tidak punya batas atas"
                                        className="flex h-[38px] w-20 items-center justify-center rounded-lg border border-cream-deep bg-surface-sunken font-mono text-sm text-ink-3"
                                    >
                                        ∞
                                    </span>
                                ) : (
                                    <BoundaryInput
                                        label={`${key} batas atas`}
                                        testId={`zone-${key}-hi`}
                                        value={zones[key].hi}
                                        onChange={(v) => editBoundary(key, 'hi', v)}
                                    />
                                )}
                            </div>
                        ))}
                    </div>
                    {Object.keys(errors).some((k) => k.startsWith('zones')) && (
                        <p className="mt-3 font-sans text-xs text-ember-deep">
                            Ada zona yang belum nyambung. Cek lagi batas atas dan bawahnya.
                        </p>
                    )}
                </Card>

                <p className="mt-5 font-sans text-sm text-ink-2">
                    Zona ini dipakai ke semua lari berikutnya.
                </p>

                <div className="mt-5">
                    <PillButton tone="sky" onClick={submit} disabled={processing}>
                        <Icon icon="mdi:content-save-outline" width={16} height={16} aria-hidden />
                        Simpan zona
                    </PillButton>
                </div>
            </PageContainer>
        </AppShell>
    );
}

interface NumberFieldProps {
    label: string;
    suffix?: string;
    value: number;
    error?: string;
    onChange: (value: number) => void;
}

function NumberField({ label, suffix, value, error, onChange }: Readonly<NumberFieldProps>) {
    return (
        <label className="block">
            <span className="mb-1.5 block font-mono text-[11px] uppercase tracking-[0.12em] text-ink-3">
                {label}
            </span>
            <span className="flex items-center gap-2 rounded-xl border border-cream-deep bg-cream px-4 py-2.5 focus-within:border-horizon">
                <input
                    type="number"
                    inputMode="numeric"
                    aria-label={label}
                    value={Number.isNaN(value) ? '' : value}
                    onChange={(e) => onChange(Number.parseInt(e.target.value, 10))}
                    className="w-full bg-transparent font-mono text-base font-semibold tabular-nums text-ink outline-none"
                />
                {suffix && <span className="font-mono text-[11px] text-ink-3">{suffix}</span>}
            </span>
            {error && <span className="mt-1 block font-sans text-xs text-ember-deep">{error}</span>}
        </label>
    );
}

interface BoundaryInputProps {
    label: string;
    testId: string;
    value: number;
    onChange: (value: number) => void;
}

function BoundaryInput({ label, testId, value, onChange }: Readonly<BoundaryInputProps>) {
    return (
        <input
            type="number"
            inputMode="numeric"
            aria-label={label}
            data-testid={testId}
            value={Number.isNaN(value) ? '' : value}
            onChange={(e) => onChange(Number.parseInt(e.target.value, 10))}
            className="w-20 rounded-lg border border-cream-deep bg-cream px-3 py-2 text-center font-mono text-sm font-semibold tabular-nums text-ink outline-none focus:border-horizon"
        />
    );
}
