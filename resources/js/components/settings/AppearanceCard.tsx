import { Icon } from '@/components/ui/Icon';
import SectionLabel from '@/components/ui/SectionLabel';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTheme, type ThemePreference } from '@/hooks/useTheme';
import { cardVariants } from '@/lib/variants';

const OPTIONS: ReadonlyArray<{
    value: ThemePreference;
    label: string;
    icon: string;
}> = [
    { value: 'light', label: 'Light', icon: 'mdi:white-balance-sunny' },
    { value: 'dark', label: 'Dark', icon: 'mdi:weather-night' },
    { value: 'system', label: 'System', icon: 'mdi:monitor' },
];

// The local ToggleGroup wrapper doesn't propagate base-ui's generic, so
// onValueChange reports plain strings; narrow back to ThemePreference here
// rather than widening the shared primitive's type for one call site.
function isThemePreference(value: string): value is ThemePreference {
    return value === 'light' || value === 'dark' || value === 'system';
}

/**
 * The Light / Dark / System control, ported from the prototype's own
 * AppearanceCard shape (a 3-way ToggleGroup) but wired for real: useTheme
 * owns the localStorage write and applies the resolved ground to the DOM
 * immediately, so a tap switches live with no reload and survives the next
 * one via app.blade.php's blocking inline script.
 */
export default function AppearanceCard() {
    const { preference, setTheme } = useTheme();

    return (
        <div className={cardVariants()}>
            <SectionLabel size="micro">Theme</SectionLabel>
            <ToggleGroup
                value={[preference]}
                onValueChange={(value) => {
                    // Single-select: base-ui's toggle group can report an
                    // empty array when the pressed item is clicked again.
                    // Ignore that so exactly one option always stays chosen.
                    const [next] = value;
                    if (next !== undefined && isThemePreference(next)) {
                        setTheme(next);
                    }
                }}
                variant="outline"
                spacing={0}
                className="w-full [&>*]:flex-1"
            >
                {OPTIONS.map((option) => (
                    <ToggleGroupItem
                        key={option.value}
                        value={option.value}
                        aria-label={option.label}
                        className="gap-1.5 text-xs font-semibold"
                    >
                        <Icon
                            icon={option.icon}
                            width={14}
                            height={14}
                            aria-hidden
                        />
                        {option.label}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
        </div>
    );
}
