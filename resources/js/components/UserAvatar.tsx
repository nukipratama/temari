import { cn } from '@/lib/cn';

interface UserAvatarProps {
    name: string;
    avatarUrl: string | null;
    /** `sm` is the mobile top bar (h-8); `md` is the desktop TopNav (h-9). */
    size?: 'sm' | 'md';
    className?: string;
}

export default function UserAvatar({ name, avatarUrl, size = 'md', className }: Readonly<UserAvatarProps>) {
    const sizeClass = size === 'sm' ? 'h-8 w-8' : 'h-9 w-9';
    const fontClass = size === 'sm' ? 'text-[15px]' : 'text-[17px]';

    if (avatarUrl) {
        return (
            <img
                src={avatarUrl}
                alt=""
                className={cn(sizeClass, 'rounded-full object-cover', className)}
            />
        );
    }

    return (
        <span
            aria-hidden
            className={cn(
                sizeClass,
                fontClass,
                'flex items-center justify-center rounded-full bg-horizon font-display font-semibold italic text-sky',
                className,
            )}
        >
            {name.charAt(0).toUpperCase()}
        </span>
    );
}
