import { Head } from '@inertiajs/react';

import CollectionHeader from '@/components/koleksi/CollectionHeader';
import Card from '@/components/ui/Card';
import PageContainer from '@/components/ui/PageContainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { BADGE_ABILITY, badgeEmblem, badgeName } from '@/lib/runcard';

interface BadgeBoardItem {
    key: string;
    unlocked: boolean;
    lifetime_count: number;
    season_count: number;
}

interface BadgesProps {
    items: BadgeBoardItem[];
    seasonStartsAt: string;
    seasonEndsAt: string;
}

// Not a Badge enum case — see GrantSeasonUnlocksAction's docblock for why a
// rest day (no Activity to attach a card to) can't be one. The board renders
// it as a visually-equivalent entry anyway, so it needs its own display text
// since runcard.ts's BADGE_LABELS/BADGE_ABILITY only cover real badges.
const REST_HONORED_KEY = 'season.rest_honored';

function displayFor(key: string): {
    emblem: string;
    name: string;
    ability: string;
} {
    if (key === REST_HONORED_KEY) {
        return {
            emblem: '🌙',
            name: 'Rest, Honored',
            ability:
                'Took a planned rest day exactly as planned, no run logged.',
        };
    }

    return {
        emblem: badgeEmblem(key) || '🏅',
        name: badgeName(key),
        ability: BADGE_ABILITY[key] ?? '',
    };
}

export default function Badges({
    items,
    seasonStartsAt,
    seasonEndsAt,
}: Readonly<BadgesProps>) {
    const unlockedCount = items.filter((i) => i.unlocked).length;
    const eyebrow = `Collection · ${unlockedCount} / ${items.length} badges`;

    return (
        <>
            <Head title="Collection · Badges" />
            <PageContainer>
                <CollectionHeader
                    active="badges"
                    eyebrow={eyebrow}
                    headline1="Every badge,"
                    headline2="earned and still out there."
                    activeCount={`${unlockedCount} / ${items.length}`}
                />

                <SectionLabel className="mt-8">
                    This season · {seasonStartsAt} to {seasonEndsAt}
                </SectionLabel>
                <div className="grid grid-cols-2 gap-3.5 md:grid-cols-3 lg:grid-cols-4">
                    {items.map((item) => (
                        <BadgeCard key={item.key} item={item} />
                    ))}
                </div>
            </PageContainer>
        </>
    );
}

function BadgeCard({ item }: Readonly<{ item: BadgeBoardItem }>) {
    const { emblem, name, ability } = displayFor(item.key);
    const locked = !item.unlocked;

    return (
        <Card
            padding="md"
            className={cn(
                'flex h-full flex-col items-center gap-2 text-center',
                locked &&
                    'border-2 border-dashed border-cream-deep bg-cream/40',
            )}
        >
            <span className={cn('text-3xl', locked && 'grayscale')} aria-hidden>
                {locked ? '🔒' : emblem}
            </span>
            <h3 className="font-display text-lg leading-tight tracking-[-0.01em] text-ink">
                {name}
            </h3>
            {locked ? (
                <p className="mt-auto font-display text-xs italic text-ink-3">
                    {ability}
                </p>
            ) : (
                <>
                    <p className="font-sans text-sm text-ink-2">{ability}</p>
                    <div className="mt-auto flex items-baseline gap-3 font-mono text-xs text-ink-3">
                        <span>Lifetime {item.lifetime_count}</span>
                        <span>This season {item.season_count}</span>
                    </div>
                </>
            )}
        </Card>
    );
}

Badges.layout = appLayout;
