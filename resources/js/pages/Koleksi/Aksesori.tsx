import { Head, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import { useState } from 'react';
import AppShell from '@/layouts/AppShell';
import Chip from '@/components/ui/Chip';
import CollectionHeader from '@/components/koleksi/CollectionHeader';
import HeroPanel from '@/components/ui/HeroPanel';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import TemariProto, { type TemariEquipped } from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';

type Slot = 'headband' | 'medal' | 'pita' | 'aura';

interface AksesoriItem {
    unlock_key: string;
    slot: Slot | null;
    name: string;
    icon: string;
    description: string;
    criteria: string;
    unlocked: boolean;
    equipped: boolean;
}

interface EquippedPayload {
    headband: 'ember' | 'epik' | 'legendaris' | null;
    medal: 'pertama' | 'emas' | null;
    pita: boolean;
    aura: boolean;
}

interface AksesoriProps {
    items: AksesoriItem[];
    equipped: EquippedPayload;
}

const SLOT_LABEL: Record<Slot, string> = {
    headband: 'Headband',
    medal: 'Medali',
    pita: 'Pita',
    aura: 'Aura',
};

const SLOT_ORDER: Slot[] = ['headband', 'medal', 'pita', 'aura'];

export default function KoleksiAksesori({ items, equipped }: Readonly<AksesoriProps>) {
    const unlockedCount = items.filter((i) => i.unlocked).length;
    const eyebrow = `Koleksi · ${unlockedCount} / ${items.length} aksesori`;

    const aksesoriCount = `${unlockedCount} / ${items.length}`;

    const previewEquipped: TemariEquipped = {
        headband: equipped.headband,
        medal: equipped.medal ?? 'none',
        pita: equipped.pita,
        aura: equipped.aura,
    };

    const itemsBySlot: Record<Slot, AksesoriItem[]> = {
        headband: [],
        medal: [],
        pita: [],
        aura: [],
    };
    for (const item of items) {
        if (item.slot) itemsBySlot[item.slot].push(item);
    }

    const equipItem = (key: string) => {
        router.post('/api/aksesori/equip', { unlock_key: key }, { preserveScroll: true });
    };

    return (
        <AppShell>
            <Head title="Koleksi · Aksesori" />
            <motion.div
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-8"
            >
                <CollectionHeader
                    active="aksesori"
                    eyebrow={eyebrow}
                    headline1="Dandanin Temari"
                    headline2="pake yang udah kamu dapet."
                    activeCount={aksesoriCount}
                />

                <HeroPanel className="mt-8 lg:px-14 lg:py-12">
                    <div className="grid items-center gap-10 lg:grid-cols-[260px_1fr]">
                        <div className="flex justify-center">
                            <TemariProto pose="proud" size={260} equipped={previewEquipped} />
                        </div>
                        <div>
                            <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                                ★ Yang lagi dipake
                            </div>
                            <h2 className="mb-5 font-display text-display-md text-cream">
                                <em className="italic text-horizon">Lagi pake yang ini.</em>
                            </h2>
                            <ul className="grid gap-2 sm:grid-cols-2">
                                {SLOT_ORDER.map((slot) => (
                                    <li key={slot} className="flex items-center justify-between rounded-xl bg-cream/[0.06] px-4 py-3">
                                        <span className="font-mono text-[10px] uppercase tracking-[0.14em] text-cream/55">
                                            {SLOT_LABEL[slot]}
                                        </span>
                                        <span className="font-display text-base italic text-cream">
                                            {equippedLabelFor(slot, equipped)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-5 max-w-md font-display text-sm italic leading-relaxed text-cream/75">
                                “Tiap kamu dapet aksesori baru, langsung aku siapin di sini.” 🎀
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
            </motion.div>
        </AppShell>
    );
}

function equippedLabelFor(slot: Slot, equipped: EquippedPayload): string {
    if (slot === 'headband') {
        return equipped.headband ? prettySuffix(equipped.headband) : 'belum dipake';
    }
    if (slot === 'medal') {
        return equipped.medal ? prettyMedal(equipped.medal) : 'belum dipake';
    }
    if (slot === 'pita') {
        return equipped.pita ? 'dipake' : 'belum dipake';
    }
    return equipped.aura ? 'aktif' : 'belum dipake';
}

function prettySuffix(s: string): string {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

function prettyMedal(s: 'pertama' | 'emas'): string {
    return s === 'pertama' ? 'Pertama (Bronze)' : 'Emas';
}

function SlotSection({
    slot,
    items,
    onEquip,
}: Readonly<{ slot: Slot; items: AksesoriItem[]; onEquip: (key: string) => void }>) {
    const [showLocked, setShowLocked] = useState(false);
    const unlocked = items.filter((i) => i.unlocked);
    const locked = items.filter((i) => !i.unlocked);
    const hasHiddenLocked = locked.length > 0;

    return (
        <section className="mt-8">
            <SectionLabel>{SLOT_LABEL[slot]}</SectionLabel>
            <div className="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                {unlocked.map((item) => (
                    <AksesoriCard key={item.unlock_key} item={item} onEquip={onEquip} />
                ))}
                {/* Locked items: visible on sm+ always, collapsible on mobile. */}
                {locked.map((item) => (
                    <div
                        key={item.unlock_key}
                        className={showLocked ? 'contents' : 'hidden sm:contents'}
                    >
                        <AksesoriCard item={item} onEquip={onEquip} />
                    </div>
                ))}
            </div>
            {hasHiddenLocked && (
                <button
                    type="button"
                    onClick={() => setShowLocked((s) => !s)}
                    className="mt-3.5 inline-flex items-center gap-1.5 rounded-full border border-cream-deep bg-cream px-4 py-2 text-xs font-semibold text-ink-2 transition hover:border-ink-3 hover:text-ink sm:hidden"
                >
                    <Icon icon={showLocked ? 'mdi:chevron-up' : 'mdi:chevron-down'} width={14} height={14} aria-hidden />
                    {showLocked
                        ? `Sembunyikan ${locked.length} belum kebuka`
                        : `+${locked.length} belum kebuka`}
                </button>
            )}
        </section>
    );
}

function previewEquippedFor(item: AksesoriItem): TemariEquipped {
    switch (item.unlock_key) {
        case 'accessory.headband_epik':
            return { headband: 'epik', medal: 'none' };
        case 'accessory.headband_legendaris':
            return { headband: 'legendaris', medal: 'none' };
        case 'accessory.medal_first_pr':
            return { medal: 'pertama' };
        case 'accessory.medal_gold':
            return { medal: 'emas' };
        case 'accessory.weekly_streak_4':
            return { pita: true, medal: 'none' };
        default:
            return { medal: 'none' };
    }
}

function AksesoriCard({
    item,
    onEquip,
}: Readonly<{ item: AksesoriItem; onEquip: (key: string) => void }>) {
    const locked = !item.unlocked;
    const preview = previewEquippedFor(item);
    return (
        <article
            className={cn(
                'relative flex flex-col items-center gap-3 rounded-2xl px-5 py-5 text-center transition',
                item.equipped
                    ? 'border-[1.5px] border-horizon bg-horizon/[0.08]'
                    : locked
                        ? 'border-2 border-dashed border-cream-deep bg-cream/40'
                        : 'border border-cream-deep bg-cream',
            )}
        >
            {item.equipped && (
                <Chip tone="horizon" className="absolute right-4 top-4">
                    Lagi dipake
                </Chip>
            )}
            <div className="relative">
                <TemariProto
                    pose="proud"
                    size={96}
                    equipped={locked ? { medal: 'none' } : preview}
                    animate={false}
                    className={cn(locked && 'opacity-50 grayscale')}
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
                <h3 className="font-display text-xl leading-tight tracking-[-0.01em] text-ink">{item.name}</h3>
                <p className="mt-1 font-sans text-sm text-ink-2">{item.description}</p>
            </div>
            {locked ? (
                <p className="mt-auto font-display text-xs italic text-ink-3">{item.criteria}</p>
            ) : item.equipped ? (
                <PillButton tone="ghost" size="sm" disabled className="mt-auto opacity-60">
                    Lagi dipake
                </PillButton>
            ) : (
                <PillButton tone="sky" size="sm" onClick={() => onEquip(item.unlock_key)} className="mt-auto">
                    Pasang
                </PillButton>
            )}
        </article>
    );
}
