import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import TemariProto, {
    TEMARI_EXPRESSIONS,
    type TemariEquipped,
} from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';
import {
    type ContrastRow,
    type SurfaceRow,
    auditContrast,
    auditSurface,
    collectSurfaceGrounds,
    collectTokenNames,
    groupColorFamilies,
    readTokenValues,
    tokensWithPrefix,
} from '@/lib/designTokens';
import { cardVariants } from '@/lib/variants';

const CARD_TONES = ['card', 'onSky', 'empty'] as const;
const CARD_PADDINGS = ['panel', 'card', 'hero'] as const;

const SLOT_SPECIMENS: ReadonlyArray<[string, TemariEquipped]> = [
    ['headband', { headband: 'legendaris' }],
    ['shirt', { kaus: 'hujan' }],
    ['shorts', { celana: 'split' }],
    ['shoes', { sepatu: 'tahan' }],
    ['medal', { medal: 'platina' }],
    ['aura', { aura: 'angin' }],
];

const FULLY_EQUIPPED: TemariEquipped = {
    headband: 'legendaris',
    kaus: 'hujan',
    celana: 'split',
    sepatu: 'tahan',
    medal: 'platina',
    aura: 'angin',
};

const SEASON_PHASES = ['base', 'build', 'peak', 'taper'] as const;

const TYPE_SPECIMENS: ReadonlyArray<[string, string, string]> = [
    ['display-lg', 'font-display italic text-display-lg', 'Eight point two'],
    ['headline-sm', 'font-display text-headline-sm', 'This week so far'],
    ['quote-lg', 'font-display italic text-quote-lg', 'same route, less work'],
    ['prose', 'text-prose', 'Body copy sits in Plus Jakarta Sans at quote-md.'],
    ['stat', 'text-stat', '8.2'],
    ['label-small', 'text-label-small text-ink-3', 'Section label'],
    ['label-micro', 'text-label-micro text-ink-3', 'Tile caption'],
    ['meta', 'text-meta', '12 Aug 2026 · 05:41'],
];

function Section({
    title,
    note,
    children,
}: Readonly<{ title: string; note?: string; children: React.ReactNode }>) {
    return (
        <section className="mt-10">
            <h2 className="text-label-small text-ink-3">{title}</h2>
            {note !== undefined && (
                <p className="mt-2 max-w-[72ch] font-sans text-xs leading-relaxed text-ink-2">
                    {note}
                </p>
            )}
            <div className="mt-3">{children}</div>
        </section>
    );
}

function Swatch({ name, value }: Readonly<{ name: string; value: string }>) {
    return (
        <div className="w-[132px]">
            <div
                className="h-12 rounded-sm border border-line"
                style={{ background: value }}
            />
            <div className="mt-1 font-sans text-[11px] font-semibold text-ink">
                {name.replace('--color-', '')}
            </div>
            <div className="text-meta">{value}</div>
        </div>
    );
}

function Specimen({
    label,
    children,
}: Readonly<{ label: string; children: React.ReactNode }>) {
    return (
        <div className="w-[124px]">
            <div className="flex h-[112px] items-center justify-center">
                {children}
            </div>
            <div className="mt-1 text-center text-meta">{label}</div>
        </div>
    );
}

function Verdict({ pass }: Readonly<{ pass: boolean }>) {
    return (
        <span
            className={cn(
                'text-label-micro',
                pass ? 'text-leaf-deep' : 'text-ember-deep',
            )}
        >
            {pass ? 'pass' : 'fail'}
        </span>
    );
}

function measureSurfaces(
    root: HTMLElement,
    tokens: Record<string, string>,
): SurfaceRow[] {
    const reference = {
        radii: tokensWithPrefix(Object.keys(tokens), '--radius-').map(
            (name) => tokens[name],
        ),
        shadows: Array.from(
            root.querySelectorAll<HTMLElement>('[data-shadow-probe]'),
        ).map((el) => getComputedStyle(el).boxShadow),
    };

    return Array.from(
        root.querySelectorAll<HTMLElement>('[data-surface-probe]'),
    ).map((el) =>
        auditSurface(
            el.dataset.surfaceProbe ?? '',
            getComputedStyle(el),
            reference,
        ),
    );
}

export default function Design() {
    const tokens = useMemo(() => {
        const names = collectTokenNames(document.styleSheets);
        return readTokenValues(names, document.documentElement);
    }, []);
    const grounds = useMemo(
        () =>
            collectSurfaceGrounds(
                document.styleSheets,
                tokens['--color-surface'] ?? '',
            ),
        [tokens],
    );
    const contrast = useMemo<ContrastRow[]>(
        () => auditContrast(tokens, grounds),
        [tokens, grounds],
    );
    const [surfaces, setSurfaces] = useState<SurfaceRow[]>([]);
    const probesRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const root = probesRef.current;
        if (!root) {
            return;
        }
        const frame = requestAnimationFrame(() =>
            setSurfaces(measureSurfaces(root, tokens)),
        );
        return () => cancelAnimationFrame(frame);
    }, [tokens]);

    const names = Object.keys(tokens);
    const radii = tokensWithPrefix(names, '--radius-');
    const shadows = tokensWithPrefix(names, '--shadow-');
    const spacing = tokensWithPrefix(names, '--spacing-');
    const pads = tokensWithPrefix(names, '--pad-');
    const contrastFails = contrast.filter((r) => !r.pass);
    const surfaceFails = surfaces.filter(
        (r) => !r.radiusOnScale || !r.shadowOnScale,
    );

    return (
        <>
            <Head title="Design tokens · Temari" />
            <div className="min-h-screen bg-surface pad-page text-ink">
                <div className="mx-auto max-w-page">
                    <h1 className="font-display italic text-headline-xs text-ink">
                        Design tokens
                    </h1>
                    <p className="mt-2 max-w-[72ch] font-sans text-xs leading-relaxed text-ink-2">
                        Every value below is read out of the live stylesheet at
                        render time with getComputedStyle, never from a list
                        copied into TypeScript. If a token moves in app.css this
                        page moves with it, and if something drifts off the
                        scales the audits at the bottom fail where you can see
                        it.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <span className="text-label-micro rounded-full bg-ink/[0.06] pad-chip text-ink-2">
                            {names.length} tokens live
                        </span>
                        <span
                            className={cn(
                                'text-label-micro rounded-full pad-chip',
                                contrastFails.length === 0
                                    ? 'bg-leaf/[0.18] text-leaf-deep'
                                    : 'bg-ember/[0.18] text-ember-deep',
                            )}
                        >
                            contrast {contrast.length - contrastFails.length}/
                            {contrast.length}
                        </span>
                        <span
                            className={cn(
                                'text-label-micro rounded-full pad-chip',
                                surfaceFails.length === 0
                                    ? 'bg-leaf/[0.18] text-leaf-deep'
                                    : 'bg-ember/[0.18] text-ember-deep',
                            )}
                        >
                            surfaces {surfaces.length - surfaceFails.length}/
                            {surfaces.length}
                        </span>
                    </div>

                    {names.length === 0 && (
                        <p className="mt-6 font-sans text-xs text-ember-deep">
                            No custom properties readable from
                            document.styleSheets.
                        </p>
                    )}

                    {groupColorFamilies(names).map(([family, tokenNames]) => (
                        <Section key={family} title={family}>
                            <div className="flex flex-wrap gap-2.5">
                                {tokenNames.map((name) => (
                                    <Swatch
                                        key={name}
                                        name={name}
                                        value={tokens[name]}
                                    />
                                ))}
                            </div>
                        </Section>
                    ))}

                    <Section
                        title="Radius"
                        note="md is the card and panel corner. Every surface in the app resolves to one of these steps."
                    >
                        <div className="flex flex-wrap items-end gap-4">
                            {radii.map((name) => (
                                <div
                                    key={name}
                                    className="flex h-[76px] w-[104px] items-center justify-center border border-line bg-surface-elev text-meta"
                                    style={{ borderRadius: tokens[name] }}
                                >
                                    {name.replace('--radius-', '')} ·{' '}
                                    {tokens[name]}
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section
                        title="Elevation"
                        note="Warm-tinted rather than neutral: a grey shadow on a cream ground reads dirty. One step per surface role, resting through modal."
                    >
                        <div className="flex flex-wrap gap-5 pb-4">
                            {shadows.map((name) => (
                                <div
                                    key={name}
                                    className="flex h-[88px] w-[132px] items-center justify-center rounded-lg bg-surface-card text-meta"
                                    style={{ boxShadow: tokens[name] }}
                                >
                                    {name.replace('--shadow-', '')}
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section
                        title="Spacing"
                        note="A 4px base, plus named roles so a component asks for its kind of padding instead of picking a number."
                    >
                        <div className="flex flex-wrap gap-2">
                            {spacing.map((name) => (
                                <div key={name} className="w-[104px]">
                                    <div
                                        className="bg-horizon/[0.35]"
                                        style={{
                                            height: 12,
                                            width: tokens[name],
                                        }}
                                    />
                                    <div className="mt-1 text-meta">
                                        {name.replace('--spacing-', '')} ·{' '}
                                        {tokens[name]}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="mt-4 flex flex-wrap gap-3">
                            {pads.map((name) => (
                                <div key={name} className="w-[164px]">
                                    <div
                                        className="rounded-sm border border-line bg-surface-elev"
                                        style={{ padding: tokens[name] }}
                                    >
                                        <div className="h-6 rounded-xs bg-surface-sunken" />
                                    </div>
                                    <div className="mt-1 text-meta">
                                        {name} · {tokens[name]}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section
                        title="Type"
                        note="Three families: Fraunces italic for display and Temari's voice, Plus Jakarta Sans for prose and UI, JetBrains Mono for telemetry."
                    >
                        <div className="flex flex-col gap-4">
                            {TYPE_SPECIMENS.map(([label, classes, sample]) => (
                                <div key={label}>
                                    <div className={classes}>{sample}</div>
                                    <div className="mt-1 text-meta">
                                        {label} · {classes}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section
                        title="Contrast audit"
                        note={`Run client-side against the live values. Text pairs need 4.5:1, a meaningful graphic 3:1, a separator 1.4:1. A fill too light to carry 3:1 itself is drawn with its -ink outline, and the outline is what gets tested. Anything sitting on paper is scored on all ${grounds.length} grounds dawn-shift can render and reported at its worst, named after the pair.`}
                    >
                        <table className="w-full max-w-[760px] border-collapse font-sans text-xs">
                            <thead>
                                <tr>
                                    {['Use', 'Pair', 'Ratio', 'Min', ''].map(
                                        (head) => (
                                            <th
                                                key={head}
                                                className="text-label-micro border-b border-line py-2 pr-3 text-left text-ink-3"
                                            >
                                                {head}
                                            </th>
                                        ),
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {contrast.map((r) => (
                                    <tr
                                        key={`${r.use}-${r.fg}-${r.bg}`}
                                        className={cn(
                                            !r.pass && 'bg-ember/[0.08]',
                                        )}
                                    >
                                        <td className="border-b border-line py-2 pr-3 text-ink">
                                            {r.use}
                                            {r.outlined === true && (
                                                <span className="text-ink-3">
                                                    {' '}
                                                    (outlined)
                                                </span>
                                            )}
                                        </td>
                                        <td className="border-b border-line py-2 pr-3 font-mono text-[11px] text-ink-2">
                                            {r.fg.replace('--color-', '')} on{' '}
                                            {r.bg.replace('--color-', '')}
                                        </td>
                                        <td className="border-b border-line py-2 pr-3 text-right font-mono tabular-nums text-ink">
                                            {r.ratio?.toFixed(2) ?? '—'}
                                        </td>
                                        <td className="border-b border-line py-2 pr-3 text-right font-mono tabular-nums text-ink-3">
                                            {r.min.toFixed(1)}
                                        </td>
                                        <td className="border-b border-line py-2">
                                            <Verdict pass={r.pass} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Section>

                    <Section
                        title="Surface audit"
                        note="The card system as it actually renders. Each probe is measured after paint and checked against the radius and elevation scales, so a fourth card corner shows up here instead of hiding on a page."
                    >
                        <div
                            ref={probesRef}
                            className="flex flex-wrap gap-3 rounded-md bg-sky pad-card"
                        >
                            {shadows.map((name) => (
                                <div
                                    key={name}
                                    data-shadow-probe
                                    aria-hidden
                                    className="h-0 w-0"
                                    style={{ boxShadow: tokens[name] }}
                                />
                            ))}
                            {CARD_TONES.map((tone) =>
                                CARD_PADDINGS.map((padding) => (
                                    <div
                                        key={`${tone}-${padding}`}
                                        data-surface-probe={`${tone} · ${padding}`}
                                        className={cardVariants({
                                            tone,
                                            padding,
                                        })}
                                    >
                                        <span
                                            className={cn(
                                                'text-label-micro',
                                                tone === 'onSky'
                                                    ? 'text-cream'
                                                    : 'text-ink-3',
                                            )}
                                        >
                                            {tone} · {padding}
                                        </span>
                                    </div>
                                )),
                            )}
                        </div>
                        <table className="mt-3 w-full max-w-[760px] border-collapse font-sans text-xs">
                            <tbody>
                                {surfaces.map((r) => (
                                    <tr key={r.name}>
                                        <td className="border-b border-line py-2 pr-3 text-ink">
                                            {r.name}
                                        </td>
                                        <td className="border-b border-line py-2 pr-3 font-mono text-[11px] text-ink-2">
                                            {r.radius}
                                        </td>
                                        <td className="border-b border-line py-2 pr-3">
                                            <Verdict pass={r.radiusOnScale} />
                                        </td>
                                        <td className="border-b border-line py-2 pr-3 font-mono text-[11px] text-ink-2">
                                            {r.shadow === 'none'
                                                ? 'no elevation'
                                                : 'on the elevation scale'}
                                        </td>
                                        <td className="border-b border-line py-2">
                                            <Verdict pass={r.shadowOnScale} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Section>

                    <Section
                        title="Mascot faces"
                        note="The ten drawn states, generated from one geometry so every face shares a skull. The halo carries mood through colour and weight and is always a closed ring, never a fill, so it can't be misread as a progress meter."
                    >
                        <div className="flex flex-wrap gap-2.5">
                            {TEMARI_EXPRESSIONS.map((expression) => (
                                <Specimen key={expression} label={expression}>
                                    <TemariProto
                                        pose={expression}
                                        size={96}
                                        animate={false}
                                    />
                                </Specimen>
                            ))}
                        </div>
                    </Section>

                    <Section
                        title="Mascot on sky"
                        note="The one dark placement. Only the silhouette outline swaps; the face stays indigo because it sits on the cream body either way."
                    >
                        <div className="flex flex-wrap gap-2.5 rounded-md bg-sky pad-card">
                            {(
                                [
                                    'resting',
                                    'challenging',
                                    'celebrating',
                                ] as const
                            ).map((expression) => (
                                <Specimen key={expression} label={expression}>
                                    <TemariProto
                                        pose={expression}
                                        tone="sky"
                                        size={96}
                                        animate={false}
                                    />
                                </Specimen>
                            ))}
                        </div>
                    </Section>

                    <Section
                        title="Wearable slots"
                        note="Six slots, 25 catalogue items. Garments are flat bands clipped to the body circle, so they take the ball's curve for free and can never escape the silhouette. Colour carries rarity, a small detail carries the theme."
                    >
                        <div className="flex flex-wrap gap-2.5">
                            {SLOT_SPECIMENS.map(([slot, equipped]) => (
                                <Specimen key={slot} label={slot}>
                                    <TemariProto
                                        size={96}
                                        equipped={equipped}
                                        animate={false}
                                    />
                                </Specimen>
                            ))}
                            <Specimen label="all six">
                                <TemariProto
                                    pose="challenging"
                                    size={96}
                                    equipped={FULLY_EQUIPPED}
                                    animate={false}
                                />
                            </Specimen>
                            <Specimen label="all six · 28px">
                                <TemariProto
                                    pose="challenging"
                                    size={28}
                                    equipped={FULLY_EQUIPPED}
                                    animate={false}
                                />
                            </Specimen>
                        </div>
                    </Section>

                    <Section
                        title="Season coverage"
                        note="Plan tab only. Discrete rather than procedural: each phase is a fixed band set at rising density, and taper keeps peak's coverage and adds a rested shine instead of unwinding it."
                    >
                        <div className="flex flex-wrap gap-2.5">
                            {SEASON_PHASES.map((phase) => (
                                <Specimen key={phase} label={phase}>
                                    <TemariProto
                                        pose="observational"
                                        size={96}
                                        seasonPhase={phase}
                                        animate={false}
                                    />
                                </Specimen>
                            ))}
                        </div>
                    </Section>

                    <Section
                        title="Cards and screens"
                        note="Mounts here so the card art reads against the same live token set as everything above."
                    >
                        <div className="rounded-md border border-dashed border-line-strong pad-hero text-meta">
                            Reserved for the card art slice
                        </div>
                    </Section>
                </div>
            </div>
        </>
    );
}
