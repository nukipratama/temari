import { Icon } from '@iconify/react';
import { Head, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useState } from 'react';

import type { EquippedSlot, Rarity } from '@/types/inertia';

import CollectionHeader from '@/components/koleksi/CollectionHeader';
import TemariProto, {
    type TemariEquipped,
} from '@/components/temari/TemariProto';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import HeroPanel from '@/components/ui/HeroPanel';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import ProgressBar from '@/components/ui/ProgressBar';
import SectionLabel from '@/components/ui/SectionLabel';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import {
    mapHeadband,
    mapMedal,
    mapKaus,
    mapCelana,
    mapSepatu,
    mapAura,
    keyToPreviewEquipped,
} from '@/lib/equippedAccessories';
import { formatGoalNumber, goalProgressRatio } from '@/lib/goalProgress';
import { fadeInUp } from '@/lib/motion';
import { RARITY_TEXT } from '@/lib/runcard';

type Slot = EquippedSlot;

interface AccessoriesItem {
    unlock_key: string;
    slot: Slot | null;
    rarity: Rarity;
    name: string;
    icon: string;
    description: string;
    criteria: string;
    unlocked: boolean;
    equipped: boolean;
    current: number;
    target: number;
    unit: string;
}

interface EquippedPayload {
    medal: string | null;
    headband: string | null;
    shirt: string | null;
    shorts: string | null;
    shoes: string | null;
    aura: string | null;
}

interface AccessoriesProps {
    items: AccessoriesItem[];
    equipped: EquippedPayload;
}

const SLOT_LABEL: Record<Slot, string> = {
    medal: 'Medal',
    headband: 'Headband',
    shirt: 'Shirt',
    shorts: 'Shorts',
    shoes: 'Shoes',
    aura: 'Aura',
};

const SLOT_ORDER: Slot[] = [
    'medal',
    'headband',
    'shirt',
    'shorts',
    'shoes',
    'aura',
];

export default function Accessories({
    items,
    equipped,
}: Readonly<AccessoriesProps>) {
    const unlockedCount = items.filter((i) => i.unlocked).length;
    const eyebrow = `Collection · ${unlockedCount} / ${items.length} accessories`;

    const aksesoriCount = `${unlockedCount} / ${items.length}`;

    const previewEquipped: TemariEquipped = {
        headband: equipped.headband ? mapHeadband(equipped.headband) : null,
        medal: mapMedal(equipped.medal),
        kaus: mapKaus(equipped.shirt),
        celana: mapCelana(equipped.shorts),
        sepatu: mapSepatu(equipped.shoes),
        aura: mapAura(equipped.aura),
    };

    const itemsBySlot: Record<string, AccessoriesItem[]> = Object.fromEntries(
        SLOT_ORDER.map((s) => [s, []]),
    );
    for (const item of items) {
        if (item.slot) itemsBySlot[item.slot].push(item);
    }

    const equipItem = (key: string) => {
        router.post(
            '/api/accessories/equip',
            { unlock_key: key },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Collection · Accessories" />
            <PageContainer>
                <CollectionHeader
                    active="aksesori"
                    eyebrow={eyebrow}
                    headline1="Dress up Temari"
                    headline2="with what you've unlocked."
                    activeCount={aksesoriCount}
                />

                <HeroPanel className="mt-8 lg:px-14 lg:py-12">
                    <div
                        data-coachmark="collection-equip"
                        className="grid grid-cols-1 items-center gap-8 lg:gap-10 lg:grid-cols-[220px_1fr]"
                    >
                        <div className="flex justify-center">
                            <TemariProto
                                pose="proud"
                                size={220}
                                equipped={previewEquipped}
                                animate
                            />
                        </div>
                        <div>
                            <Eyebrow
                                token="hero"
                                tone="horizon"
                                className="mb-3"
                            >
                                ★ Currently equipped
                            </Eyebrow>
                            <h2 className="mb-5 font-display text-display-md text-cream">
                                <em className="italic text-horizon">
                                    Wearing this right now.
                                </em>
                            </h2>
                            <ul className="grid gap-2 sm:grid-cols-2">
                                {SLOT_ORDER.map((slot) => (
                                    <li
                                        key={slot}
                                        className="flex items-center justify-between rounded-xl bg-cream/[0.06] px-4 py-3"
                                    >
                                        <Eyebrow
                                            as="span"
                                            token="micro"
                                            tone="ink-on-sky"
                                        >
                                            {SLOT_LABEL[slot]}
                                        </Eyebrow>
                                        <span className="font-display text-base italic text-cream">
                                            {equippedLabelFor(
                                                slot,
                                                equipped,
                                                items,
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-5 max-w-md font-display text-sm italic leading-relaxed text-cream/75">
                                &ldquo;Every time you unlock something new, I'll
                                have it ready right here.&rdquo; 🎀
                            </p>
                        </div>
                    </div>
                </HeroPanel>

                {SLOT_ORDER.map((slot) =>
                    itemsBySlot[slot].length > 0 ? (
                        <SlotSection
                            key={slot}
                            slot={slot}
                            items={itemsBySlot[slot]}
                            onEquip={equipItem}
                        />
                    ) : null,
                )}
            </PageContainer>
        </>
    );
}

function equippedLabelFor(
    slot: Slot,
    equipped: EquippedPayload,
    items: AccessoriesItem[],
): string {
    const key = equipped[slot];
    if (!key) return 'not equipped';
    const item = items.find((i) => i.unlock_key === key);
    return item ? item.name : 'equipped';
}

function SlotSection({
    slot,
    items,
    onEquip,
}: Readonly<{
    slot: Slot;
    items: AccessoriesItem[];
    onEquip: (key: string) => void;
}>) {
    const [showLocked, setShowLocked] = useState(false);
    const unlocked = items.filter((i) => i.unlocked);
    const locked = items.filter((i) => !i.unlocked);
    const hasHiddenLocked = locked.length > 0;

    return (
        <motion.section
            variants={fadeInUp}
            initial="hidden"
            animate="visible"
            className="mt-8"
        >
            <SectionLabel>{SLOT_LABEL[slot]}</SectionLabel>
            <div
                data-coachmark="collection-accessories-grid"
                className="grid grid-cols-2 gap-3.5 md:grid-cols-3 lg:grid-cols-4"
            >
                {unlocked.map((item) => (
                    <AksesoriCard
                        key={item.unlock_key}
                        item={item}
                        onEquip={onEquip}
                    />
                ))}
                {/* Locked items: visible on sm+ always, collapsible on mobile. */}
                {locked.map((item) => (
                    <div
                        key={item.unlock_key}
                        className={
                            showLocked ? 'contents' : 'hidden sm:contents'
                        }
                    >
                        <AksesoriCard item={item} onEquip={onEquip} />
                    </div>
                ))}
            </div>
            {hasHiddenLocked && (
                <PillButton
                    tone="outline"
                    size="sm"
                    onClick={() => setShowLocked((s) => !s)}
                    className="mt-3.5 gap-1.5 px-4 py-2 text-xs font-semibold sm:hidden"
                >
                    <Icon
                        icon={
                            showLocked ? 'mdi:chevron-up' : 'mdi:chevron-down'
                        }
                        width={14}
                        height={14}
                        aria-hidden
                    />
                    {showLocked
                        ? `Hide ${locked.length} locked`
                        : `+${locked.length} locked`}
                </PillButton>
            )}
        </motion.section>
    );
}

function AksesoriCard({
    item,
    onEquip,
}: Readonly<{ item: AccessoriesItem; onEquip: (key: string) => void }>) {
    const locked = !item.unlocked;
    const preview = keyToPreviewEquipped(item.unlock_key);
    const currentCount = useCountUp(item.current);
    let cardBorder: string;
    if (item.equipped) {
        cardBorder =
            'border-[1.5px] border-horizon bg-horizon/[0.08] shadow-sm';
    } else if (locked) {
        cardBorder = 'border-2 border-dashed border-cream-deep bg-cream/40';
    } else {
        cardBorder = 'border border-cream-deep bg-cream shadow-sm';
    }
    return (
        <article
            className={cn(
                'relative flex flex-col items-center gap-3 rounded-2xl px-5 py-5 text-center transition',
                cardBorder,
            )}
        >
            {item.equipped && (
                <Chip tone="horizon" className="absolute right-4 top-4 z-10">
                    Equipped
                </Chip>
            )}
            <div className="relative">
                <TemariProto
                    pose="proud"
                    size={96}
                    equipped={locked ? { medal: 'none' } : preview}
                    animate={false}
                    className={cn(locked && 'opacity-60 grayscale')}
                />
                {locked && (
                    <span
                        aria-hidden
                        className="absolute -right-1 bottom-1 flex h-7 w-7 items-center justify-center rounded-full bg-ink-3 text-cream shadow-sm"
                    >
                        <Icon icon="mdi:lock-outline" width={14} height={14} />
                    </span>
                )}
            </div>
            <div>
                <h3
                    className={cn(
                        'font-display text-xl leading-tight tracking-[-0.01em]',
                        RARITY_TEXT[item.rarity],
                        'text-ink',
                    )}
                >
                    {item.name}
                </h3>
                <p className="mt-1 font-sans text-sm text-ink-2">
                    {item.description}
                </p>
            </div>
            {locked && (
                <div className="mt-auto w-full">
                    <p className="font-display text-xs italic text-ink-3">
                        {item.criteria}
                    </p>
                    {item.target > 0 && (
                        <div className="mt-2">
                            <div className="mb-1 flex items-baseline justify-between font-mono text-[11px] tabular-nums text-ink-3">
                                <span>
                                    {formatGoalNumber(currentCount)}
                                    <span className="text-ink-3">/</span>
                                    {formatGoalNumber(item.target)}
                                </span>
                                <span>{item.unit}</span>
                            </div>
                            <ProgressBar
                                value={goalProgressRatio(
                                    item.current,
                                    item.target,
                                )}
                                tone="sky"
                                ariaLabel={`${item.name}: ${formatGoalNumber(item.current)}/${formatGoalNumber(item.target)} ${item.unit}`}
                            />
                        </div>
                    )}
                </div>
            )}
            {!locked && item.equipped && (
                <PillButton
                    tone="sky"
                    size="sm"
                    disabled
                    className="mt-auto gap-1.5"
                >
                    <Icon
                        icon="mdi:check-circle"
                        width={15}
                        height={15}
                        aria-hidden
                    />
                    Equipped
                </PillButton>
            )}
            {!locked && !item.equipped && (
                <PillButton
                    tone="sky"
                    size="sm"
                    onClick={() => onEquip(item.unlock_key)}
                    className="mt-auto gap-1.5"
                >
                    <Icon
                        icon="mdi:hanger"
                        width={15}
                        height={15}
                        aria-hidden
                    />
                    Equip
                </PillButton>
            )}
        </article>
    );
}

Accessories.layout = appLayout;
