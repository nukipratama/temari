import { Avatar } from '@base-ui/react/avatar';

import { cn } from '@/lib/cn';

const SIZE_CLASS = {
    sm: 'h-8 w-8',
    md: 'h-9 w-9',
    lg: 'h-11 w-11',
} as const;

const FONT_CLASS = {
    sm: 'text-[0.9375rem]',
    md: 'text-[1.0625rem]',
    lg: 'text-xl',
} as const;

const FALLBACK_DELAY_MS = 600;

interface UserAvatarProps {
    name: string;
    avatarUrl: string | null;
    /** `sm` is the mobile top bar (h-8), `md` the default (h-9), `lg` Profile's own header circle (h-11). */
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}

export default function UserAvatar({
    name,
    avatarUrl,
    size = 'md',
    className,
}: Readonly<UserAvatarProps>) {
    return (
        <Avatar.Root
            className={cn(
                SIZE_CLASS[size],
                FONT_CLASS[size],
                'relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-horizon font-serif font-semibold italic text-sky',
                className,
            )}
        >
            {avatarUrl && (
                <Avatar.Image
                    src={avatarUrl}
                    alt=""
                    className="absolute inset-0 h-full w-full object-cover"
                />
            )}
            <Avatar.Fallback
                aria-hidden
                delay={avatarUrl ? FALLBACK_DELAY_MS : undefined}
            >
                {name.charAt(0).toUpperCase()}
            </Avatar.Fallback>
        </Avatar.Root>
    );
}
