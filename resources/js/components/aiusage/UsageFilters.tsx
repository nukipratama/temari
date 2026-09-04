import { Link } from '@inertiajs/react';
import { useState } from 'react';

import type { KindOption, RangeToken } from '@/pages/AiUsage/types';

import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import PillButton from '@/components/ui/PillButton';
import { cn } from '@/lib/cn';
import { toggleButtonVariants } from '@/lib/variants';
import { navigate, presetHref, PRESETS } from '@/pages/AiUsage/helpers';

interface UsageFiltersProps {
    range: RangeToken;
    from: string;
    to: string;
    kind: string | null;
    origin: string | null;
    availableKinds: KindOption[];
    availableOrigins: KindOption[];
}

export default function UsageFilters({
    range,
    from,
    to,
    kind,
    origin,
    availableKinds,
    availableOrigins,
}: Readonly<UsageFiltersProps>) {
    const [fromInput, setFromInput] = useState<string>(from);
    const [toInput, setToInput] = useState<string>(to);
    const [kindInput, setKindInput] = useState<string>(kind ?? '');
    const [originInput, setOriginInput] = useState<string>(origin ?? '');

    function handleSubmit(e: React.FormEvent): void {
        e.preventDefault();
        // Editing the date fields is a custom window.
        navigate({
            range: 'custom',
            from: fromInput,
            to: toInput,
            kind: kindInput || null,
            origin: originInput || null,
        });
    }

    // Changing a filter applies immediately, keeping the current range window.
    function handleKindChange(value: string): void {
        setKindInput(value);
        navigate({
            range,
            from,
            to,
            kind: value || null,
            origin: originInput || null,
        });
    }

    function handleOriginChange(value: string): void {
        setOriginInput(value);
        navigate({
            range,
            from,
            to,
            kind: kindInput || null,
            origin: value || null,
        });
    }

    return (
        <>
            <Card
                as="section"
                tone="card"
                padding="panel"
                className="bg-popover"
            >
                <form
                    onSubmit={handleSubmit}
                    className="flex flex-wrap items-end gap-3"
                >
                    <DateField
                        id="from"
                        label="From"
                        value={fromInput}
                        onChange={setFromInput}
                    />
                    <DateField
                        id="to"
                        label="To"
                        value={toInput}
                        onChange={setToInput}
                    />

                    {availableKinds.length > 0 && (
                        <SelectFilter
                            id="kind-filter"
                            label="Kind"
                            options={availableKinds}
                            value={kindInput}
                            onChange={handleKindChange}
                        />
                    )}

                    {availableOrigins.length > 0 && (
                        <SelectFilter
                            id="origin-filter"
                            label="Origin"
                            options={availableOrigins}
                            value={originInput}
                            onChange={handleOriginChange}
                        />
                    )}

                    <PillButton type="submit" tone="sky" size="sm">
                        <Icon icon="mdi:filter-variant" aria-hidden />
                        <span>Apply</span>
                    </PillButton>

                    <div className="ml-auto flex flex-wrap gap-2">
                        {PRESETS.map((preset) => (
                            <PresetButton
                                key={preset.token}
                                label={preset.label}
                                href={presetHref(preset.token, kind, origin)}
                                active={range === preset.token}
                            />
                        ))}
                    </div>
                </form>
            </Card>

            <p className="mt-3 text-xs text-text-3">
                Active range:{' '}
                <span className="font-semibold text-foreground">{from}</span> to{' '}
                <span className="font-semibold text-foreground">{to}</span>
                {kind && (
                    <>
                        {' '}
                        <span className="text-text-2">|</span> Kind:{' '}
                        <span className="font-semibold text-foreground">
                            {kind}
                        </span>
                    </>
                )}
                {origin && (
                    <>
                        {' '}
                        <span className="text-text-2">|</span> Origin:{' '}
                        <span className="font-semibold text-foreground">
                            {origin}
                        </span>
                    </>
                )}
            </p>
        </>
    );
}

function SelectFilter({
    id,
    label,
    options,
    value,
    onChange,
}: Readonly<{
    id: string;
    label: string;
    options: KindOption[];
    value: string;
    onChange: (v: string) => void;
}>) {
    return (
        <label
            htmlFor={id}
            className="flex flex-col gap-1 font-mono text-xs font-bold uppercase tracking-wider text-text-2"
        >
            {label}
            <select
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="rounded-xl border border-border bg-muted px-3 py-2 text-sm font-medium text-foreground focus:border-leaf focus:outline-none"
            >
                <option value="">All</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function DateField({
    id,
    label,
    value,
    onChange,
}: Readonly<{
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
}>) {
    return (
        <label
            htmlFor={id}
            className="flex flex-col gap-1 font-mono text-xs font-bold uppercase tracking-wider text-text-2"
        >
            {label}
            <input
                id={id}
                type="date"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="rounded-xl border border-border bg-muted px-3 py-2 text-sm font-medium text-foreground focus:border-leaf focus:outline-none"
            />
        </label>
    );
}

function PresetButton({
    label,
    href,
    active,
}: Readonly<{ label: string; href: string; active: boolean }>) {
    return (
        <Link
            href={href}
            preserveScroll
            className={cn(
                toggleButtonVariants({ size: 'sm', selected: active }),
            )}
        >
            {label}
        </Link>
    );
}
