import { Footprints, Repeat, Trophy } from 'lucide-react';
import { useState } from 'react';

import IconChoiceCard from '@/components/onboarding/IconChoiceCard';
import { type CatalogueEntry } from '@/lib/catalogue';

const OPTIONS = [
    {
        value: 'new',
        icon: Footprints,
        label: 'new to running',
        description: 'a few weeks in, or starting now.',
    },
    {
        value: 'returning',
        icon: Repeat,
        label: 'coming back',
        description: 'ran before, took a break.',
    },
    { value: 'experienced', icon: Trophy, label: 'running for years' },
] as const;

function Demo() {
    const [chosen, setChosen] = useState<string>('returning');

    return (
        <div className="flex max-w-sm flex-col gap-2">
            {OPTIONS.map((option) => (
                <IconChoiceCard
                    key={option.value}
                    icon={option.icon}
                    label={option.label}
                    description={
                        'description' in option ? option.description : undefined
                    }
                    active={chosen === option.value}
                    onClick={() => setChosen(option.value)}
                />
            ))}
        </div>
    );
}

export default {
    name: 'IconChoiceCard',
    description:
        'One tappable option for a preference question: an icon chip, a bold label and an optional description. The chosen one fills with horizon.',
    usage: `<IconChoiceCard icon={Repeat} label="coming back" description="…" active={level === 'returning'} onClick={…} />`,
    states: [{ name: 'one chosen', render: () => <Demo /> }],
} satisfies CatalogueEntry;
