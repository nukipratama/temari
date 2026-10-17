import { router } from '@inertiajs/react';
import { Clock, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';

interface RegenerateButtonProps {
    /** Seconds left before Regenerate may run again, or null when it's free to click. */
    cooldownSeconds: number | null;
}

export default function RegenerateButton({
    cooldownSeconds,
}: Readonly<RegenerateButtonProps>) {
    const [regenerating, setRegenerating] = useState(false);
    const cooldown = useCooldownCountdown(cooldownSeconds);
    const cooling = cooldown > 0;

    const regenerate = () => {
        router.post(
            '/plan/regenerate',
            {},
            {
                preserveScroll: true,
                onStart: () => setRegenerating(true),
                onFinish: () => setRegenerating(false),
            },
        );
    };

    return (
        <PillButton
            tone="muted"
            size="xs"
            className="flex-none"
            onClick={regenerate}
            disabled={regenerating || cooling}
            aria-label={
                regenerating
                    ? 'replanning'
                    : cooling
                      ? `regenerate, next in ${formatDurationHMS(cooldown)}`
                      : 'regenerate'
            }
        >
            <Icon
                icon={cooling ? Clock : RefreshCw}
                className={cn('size-3.5', regenerating && 'animate-spin')}
                aria-hidden
            />
            {cooling && <span aria-hidden>{formatDurationHMS(cooldown)}</span>}
        </PillButton>
    );
}
