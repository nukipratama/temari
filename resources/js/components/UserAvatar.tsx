import { cn } from '@/lib/cn';

const SIZE_CLASS = {
    sm: 'h-8 w-8',
    md: 'h-9 w-9',
    lg: 'h-11 w-11',
} as const;

const FONT_CLASS = {
    sm: 'text-[15px]',
    md: 'text-[17px]',
    lg: 'text-xl',
} as const;

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
    const sizeClass = SIZE_CLASS[size];
    const fontClass = FONT_CLASS[size];

    if (avatarUrl) {
        return (
            <img
                src={avatarUrl}
                alt=""
                className={cn(
                    sizeClass,
                    'rounded-full object-cover',
                    className,
                )}
            />
        );
    }

    return (
        <span
            aria-hidden
            className={cn(
                sizeClass,
                fontClass,
                'flex items-center justify-center rounded-full bg-horizon font-serif font-semibold italic text-sky',
                className,
            )}
        >
            {name.charAt(0).toUpperCase()}
        </span>
    );
}
