import { X } from 'lucide-react';
import { type ReactNode } from 'react';

import { Icon, type IconComponent } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

export type BannerTone = 'neutral' | 'success' | 'error';

const TONE: Record<BannerTone, { frame: string; glyph: string }> = {
    neutral: { frame: 'border-border bg-muted', glyph: 'text-text-3' },
    success: { frame: 'border-leaf/30 bg-leaf/[0.08]', glyph: 'text-leaf-ink' },
    error: {
        frame: 'border-ember/30 bg-ember/[0.08]',
        glyph: 'text-ember-ink',
    },
};

interface BannerProps {
    icon: IconComponent;
    /** Default 'neutral'; 'error' is announced as an alert. */
    tone?: BannerTone;
    /** For a non-error banner that should be announced, e.g. 'status'. */
    role?: 'status';
    /** A control drawn after the message, e.g. a reconnect link. */
    action?: ReactNode;
    /** Draws the close button when given. */
    onDismiss?: () => void;
    children: ReactNode;
}

/** The app-shell banner: one message under the top bar, in the shell's column. */
export default function Banner({
    icon,
    tone = 'neutral',
    role,
    action,
    onDismiss,
    children,
}: Readonly<BannerProps>) {
    return (
        <div className="px-4 pt-4 min-[900px]:px-6">
            <div
                role={tone === 'error' ? 'alert' : role}
                className={cn(
                    'mx-auto flex items-start gap-3 rounded-lg border px-4 py-3 min-[900px]:max-w-column min-[1280px]:max-w-column-wide',
                    TONE[tone].frame,
                )}
            >
                <Icon
                    icon={icon}
                    width={20}
                    height={20}
                    className={cn('mt-0.5 shrink-0', TONE[tone].glyph)}
                    aria-hidden
                />
                <p className="flex-1 font-sans text-sm leading-relaxed text-foreground">
                    {children}
                </p>
                {action}
                {onDismiss && (
                    <button
                        type="button"
                        onClick={onDismiss}
                        aria-label="Close"
                        className="focus-ring -m-1 shrink-0 rounded-xs p-1 text-text-3 transition hover:text-foreground"
                    >
                        <Icon icon={X} width={16} height={16} />
                    </button>
                )}
            </div>
        </div>
    );
}
