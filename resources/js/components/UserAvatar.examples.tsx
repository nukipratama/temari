import UserAvatar from '@/components/UserAvatar';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'UserAvatar',
    description:
        "The runner's Strava photo in a circle, falling back to their initial on horizon while it loads or when there is none.",
    usage: `<UserAvatar name={user.name} avatarUrl={user.avatar_url} size="sm" />`,
    states: [
        {
            name: 'initial fallback, every size',
            render: () => (
                <div className="flex items-end gap-3">
                    {(['sm', 'md', 'lg'] as const).map((size) => (
                        <UserAvatar
                            key={size}
                            name="Ada Lovelace"
                            avatarUrl={null}
                            size={size}
                        />
                    ))}
                </div>
            ),
        },
    ],
} satisfies CatalogueEntry;
