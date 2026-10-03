import { Monitor, Moon, Sun } from 'lucide-react';

import { Icon, IconComponent } from '@/components/ui/Icon';
import SectionLabel from '@/components/ui/SectionLabel';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTheme, type ThemePreference } from '@/hooks/useTheme';

const OPTIONS: ReadonlyArray<{
    value: ThemePreference;
    label: string;
    icon: IconComponent;
}> = [
    { value: 'light', label: 'light', icon: Sun },
    { value: 'dark', label: 'dark', icon: Moon },
    { value: 'system', label: 'system', icon: Monitor },
];

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
        <div>
            <SectionLabel size="micro">Theme</SectionLabel>
            <ToggleGroup
                value={preference}
                onValueChange={setTheme}
                size="md"
                aria-label="theme"
                className="flex-nowrap *:flex-1"
            >
                {OPTIONS.map((option) => (
                    <ToggleGroupItem
                        key={option.value}
                        value={option.value}
                        aria-label={option.label}
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
