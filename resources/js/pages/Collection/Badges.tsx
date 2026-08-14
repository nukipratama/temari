import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';

import CollectionHeader from '@/components/collection/CollectionHeader';
import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import SectionLabel from '@/components/ui/SectionLabel';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp, staggerContainer } from '@/lib/motion';
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
    const unlockedDisplay = Math.round(useCountUp(unlockedCount));
    const eyebrow = `Collection · ${unlockedCount} / ${items.length} badges`;

    return (
        <>
            <Head title="Collection · Badges" />
            <PageContainer>
                <CollectionHeader
                    active="badges"
                    eyebrow={eyebrow}
                    headline1="Every badge"
                    headline2="earned and still out there."
                    activeCount={`${unlockedCount} / ${items.length}`}
                />

                <div className="mt-8 flex flex-wrap items-baseline justify-between gap-4">
                    <SectionLabel className="mb-0">
                        This season · {seasonStartsAt} to {seasonEndsAt}
                    </SectionLabel>
                    <div className="flex items-baseline gap-2">
                        <span className="text-stat-sm">{unlockedDisplay}</span>
                        <Eyebrow token="micro" tone="ink-3">
                            of {items.length} unlocked
                        </Eyebrow>
                    </div>
                </div>
                <motion.div
                    data-coachmark="collection-badges"
                    variants={staggerContainer}
                    initial="hidden"
                    animate="visible"
                    className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4"
                >
                    {items.map((item) => (
                        <motion.div key={item.key} variants={fadeInUp}>
                            <BadgeCard item={item} />
                        </motion.div>
                    ))}
                </motion.div>
            </PageContainer>
        </>
    );
}

function BadgeCard({ item }: Readonly<{ item: BadgeBoardItem }>) {
    const { emblem, name, ability } = displayFor(item.key);
    const locked = !item.unlocked;

    return (
        <Card
            tone={locked ? 'empty' : 'card'}
            padding="card"
            className="flex h-full flex-col items-center gap-2 text-center"
        >
            <span className={cn('text-3xl', locked && 'grayscale')} aria-hidden>
                {locked ? '🔒' : emblem}
            </span>
            <h3 className="font-display text-headline-xs text-ink">{name}</h3>
            <p className="font-sans text-sm text-ink-2">{ability}</p>
            {!locked && (
                <div className="mt-auto flex items-baseline gap-3">
                    <Eyebrow as="span" token="micro" tone="ink-3">
                        Lifetime {item.lifetime_count}
                    </Eyebrow>
                    <Eyebrow as="span" token="micro" tone="ink-3">
                        This season {item.season_count}
                    </Eyebrow>
                </div>
            )}
        </Card>
    );
}

Badges.layout = appLayout;
