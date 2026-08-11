import { cn } from '@/lib/cn';

interface ToggleProps {
    /** Accessible name. Not rendered — the host row supplies the visible label. */
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    disabled?: boolean;
}

/**
 * The app's switch.
 *
 * Promoted out of `Settings/Index` when the settings page grew past one place
 * that needed a switch. It renders **only the control**, not a label row: the
 * visible label, description and icon belong to `SettingsRow`, so both toggle
 * rows and chevron rows share one layout instead of each inventing its own
 * padding and type scale.
 *
 * `role="switch"` with `aria-checked` rather than a checkbox, because the state
 * change takes effect immediately — there is no form to submit.
 */
export default function Toggle({
    label,
    checked,
    onChange,
    disabled = false,
}: Readonly<ToggleProps>) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'focus-ring pressable relative h-6 w-11 shrink-0 rounded-full motion-safe:transition-colors motion-safe:duration-150 motion-safe:ease-[cubic-bezier(0.34,1.1,0.64,1)]',
                checked ? 'bg-horizon' : 'bg-cream-deep',
                disabled && 'cursor-not-allowed opacity-50',
            )}
        >
            <span
                className={cn(
                    'absolute top-0.5 h-5 w-5 rounded-full bg-white motion-safe:transition-[left] motion-safe:duration-150 motion-safe:ease-[cubic-bezier(0.34,1.1,0.64,1)]',
                    checked ? 'left-[1.375rem]' : 'left-0.5',
                )}
            />
        </button>
    );
}
