import { Link } from '@inertiajs/react';

import UserAvatar from '@/components/UserAvatar';

interface UserAvatarLinkProps {
    name: string;
    avatarUrl: string | null;
}

/**
 * Avatar that links straight to Profile — the Me destination's only entry
 * point now that the bottom nav carries no Me tab of its own. Shared by both
 * the desktop TopNav and the mobile MobileTopBar. Settings and Log out, which
 * used to live in a dropdown here, are reachable from Profile's MeTabs and
 * the bottom of the Settings page, respectively.
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
