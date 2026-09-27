import { Unlink } from 'lucide-react';
import { type MouseEventHandler } from 'react';

import { Icon } from '@/components/ui/Icon';

export default function SettingsDisconnectLink({
    onClick,
    disabled = false,
}: Readonly<{
    onClick: MouseEventHandler<HTMLButtonElement>;
    disabled?: boolean;
}>) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="focus-ring inline-flex shrink-0 items-center gap-1 rounded text-label-small text-text-3 transition hover:text-ember-ink disabled:pointer-events-none disabled:opacity-60"
        >
            <Icon icon={Unlink} width={13} height={13} aria-hidden />
            Disconnect
        </button>
    );
}
