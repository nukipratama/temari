import { Link } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

import type { KindOption, RangeToken } from '@/pages/Narration/types';

import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { navigate, presetHref, PRESETS } from '@/pages/Narration/helpers';

interface UsageFiltersProps {
    range: RangeToken;
    from: string;
    to: string;
    kind: string | null;
    origin: string | null;
    athlete: number | null;
    availableKinds: KindOption[];
    availableOrigins: KindOption[];
}

export default function UsageFilters({
    range,
    from,
    to,
    kind,
    origin,
    athlete,
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
            athlete,
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
            athlete,
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
            athlete,
        });
    }

    return (
        <>
            <Card render={<section />} padding="panel" className="bg-popover">
                <form
                    onSubmit={handleSubmit}
                    className="flex flex-wrap items-end gap-3"
                >
                    <DateField
                        id="from"
                        label="from"
                        value={fromInput}
                        onChange={setFromInput}
                    />
                    <DateField
                        id="to"
                        label="to"
                        value={toInput}
                        onChange={setToInput}
                    />

                    {availableKinds.length > 0 && (
                        <SelectFilter
                            id="kind-filter"
                            label="kind"
                            options={availableKinds}
                            value={kindInput}
                            onChange={handleKindChange}
                        />
                    )}

                    {availableOrigins.length > 0 && (
                        <SelectFilter
                            id="origin-filter"
                            label="origin"
                            options={availableOrigins}
                            value={originInput}
                            onChange={handleOriginChange}
                        />
                    )}

                    <PillButton type="submit" tone="sky" size="sm">
                        <Icon icon={SlidersHorizontal} aria-hidden />
                        <span>apply</span>
                    </PillButton>

                    <ToggleGroup
                        value={range}
                        aria-label="range preset"
                        className="ml-auto"
                    >
                        {PRESETS.map((preset) => (
                            <ToggleGroupItem
                                key={preset.token}
                                value={preset.token}
                                nativeButton={false}
                                render={
                                    <Link
                                        href={presetHref(
                                            preset.token,
                                            kind,
                                            origin,
                                            athlete,
                                        )}
                                        preserveScroll
                                    />
                                }
                            >
                                {preset.label}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </form>
            </Card>

            <p className="mt-3 text-xs text-text-3">
                active range:{' '}
                <span className="font-semibold text-foreground">{from}</span> to{' '}
                <span className="font-semibold text-foreground">{to}</span>
                {kind && (
                    <>
                        {' '}
                        <span className="text-text-2">|</span> kind:{' '}
                        <span className="font-semibold text-foreground">
                            {kind}
                        </span>
                    </>
                )}
                {origin && (
                    <>
                        {' '}
                        <span className="text-text-2">|</span> origin:{' '}
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
                className="focus-ring rounded-xl border border-border bg-muted px-3 py-2 text-sm font-medium text-foreground focus:border-leaf"
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
                className="focus-ring rounded-xl border border-border bg-muted px-3 py-2 text-sm font-medium text-foreground focus:border-leaf"
            />
        </label>
    );
}
