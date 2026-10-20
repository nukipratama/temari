import { Link } from '@inertiajs/react';

import UserAvatar from '@/components/UserAvatar';

interface UserAvatarLinkProps {
    name: string;
    avatarUrl: string | null;
}

/**
 * Avatar that links straight to Profile, the Me destination's only entry point.
 * Shared by both the desktop TopNav and the mobile MobileTopBar.
 */
export default function UserAvatarLink({
    name,
    avatarUrl,
}: Readonly<UserAvatarLinkProps>) {
    return (
        <Link
            href="/profile"
            aria-label={`${name}'s profile`}
            className="flex h-11 w-11 items-center justify-center rounded-full ring-2 ring-cream-deep transition hover:ring-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 focus-visible:ring-offset-cream"
        >
            <UserAvatar
                name={name}
                avatarUrl={avatarUrl}
                size="md"
                className="h-11 w-11"
            />
        </Link>
    );
}
