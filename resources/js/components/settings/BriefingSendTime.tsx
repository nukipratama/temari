import { Clock } from 'lucide-react';
import { useState } from 'react';

import SettingsRow from '@/components/ui/SettingsRow';
import { cn } from '@/lib/cn';
import { inputVariants } from '@/lib/variants';

const QUIET_HOURS_START_MINUTE = 22 * 60;

function minuteToClock(minute: number | null): string {
    if (minute === null) {
        return '';
    }

    const pad = (part: number) => part.toString().padStart(2, '0');

    return `${pad(Math.floor(minute / 60))}:${pad(minute % 60)}`;
}

function clockToMinute(clock: string): number | null {
    if (clock === '') {
        return null;
    }

    return Number(clock.slice(0, 2)) * 60 + Number(clock.slice(3, 5));
}

/**
 * Saved when the field is left rather than on every change, since a native
 * time field reports each completed segment as it is typed. A time inside the
 * evening quiet hours is put back, as the server refuses it.
 */
export default function BriefingSendTime({
    minute,
    disabled,
    onChange,
}: Readonly<{
    minute: number | null;
    disabled: boolean;
    onChange: (minute: number | null) => void;
}>) {
    const [draft, setDraft] = useState(() => minuteToClock(minute));
    const [reverted, setReverted] = useState(false);

    return (
        <SettingsRow
            icon={Clock}
            label="send at"
            description="empty = follows your runs"
            control={
                <span className="flex shrink-0 items-center gap-2">
                    {draft !== '' && (
                        <button
                            type="button"
                            disabled={disabled}
                            onClick={() => {
                                setDraft('');
                                setReverted(false);
                                onChange(null);
                            }}
                            className="focus-ring rounded-sm font-sans text-xs text-text-2 underline-offset-2 hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            clear
                        </button>
                    )}
                    <input
                        type="time"
                        step={900}
                        aria-label="send the morning briefing at"
                        value={draft}
                        disabled={disabled}
                        onChange={(event) => {
                            setDraft(event.target.value);
                            setReverted(false);
                        }}
                        onBlur={() => {
                            const next = clockToMinute(draft);
                            if (
                                next !== null &&
                                next >= QUIET_HOURS_START_MINUTE
                            ) {
                                setDraft(minuteToClock(minute));
                                setReverted(true);
                            } else if (next !== minute) {
                                onChange(next);
                            }
                        }}
                        className={cn(
                            inputVariants({ size: 'sm' }),
                            'w-auto font-mono tabular-nums disabled:cursor-not-allowed disabled:opacity-50',
                        )}
                    />
                </span>
            }
        >
            {reverted && (
                <p className="text-right font-sans text-xs text-text-2">
                    before 22:00 only
                </p>
            )}
        </SettingsRow>
    );
}
