import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';

interface SegmentedControlProps<T extends string> {
    label: string;
    value: T;
    options: ReadonlyArray<{ key: T; label: string }>;
    onChange: (value: T) => void;
    className?: string;
}

/**
 * Base UI's ToggleGroup is array-valued even when only one item can be pressed,
 * and its pressed tint is `bg-muted`, which is nearly invisible on Pewter's card.
 * Both are handled once here rather than at each of the three call sites.
 */
export function SegmentedControl<T extends string>({
    label,
    value,
    options,
    onChange,
    className,
}: Readonly<SegmentedControlProps<T>>) {
    return (
        <ToggleGroup
            aria-label={label}
            value={[value]}
            onValueChange={(next) => {
                const picked = next.find((v) => v !== value) ?? value;
                onChange(picked as T);
            }}
            variant="outline"
            size="sm"
            className={cn('flex-wrap', className)}
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option.key}
                    value={option.key}
                    className="aria-pressed:border-horizon-ink/40 aria-pressed:bg-horizon/30 aria-pressed:text-ink"
                >
                    {option.label}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
