import { Head, router } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useState } from 'react';
import AppShell from '@/layouts/AppShell';
import Chip from '@/components/ui/Chip';
import CollectionHeader from '@/components/koleksi/CollectionHeader';
import HeroPanel from '@/components/ui/HeroPanel';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import TemariProto, { type TemariEquipped } from '@/components/temari/TemariProto';
import { cn } from '@/lib/cn';
import PageContainer from '@/components/ui/PageContainer';
import {
    mapHeadband,
    mapMedal,
    mapKaus,
    mapCelana,
    mapSepatu,
    mapAura,
    keyToPreviewEquipped,
} from '@/lib/equippedAccessories';
import { RARITY_TEXT } from '@/lib/runcard';
import type { EquippedSlot, Rarity } from '@/types/inertia';

type Slot = EquippedSlot;

interface AksesoriItem {
    unlock_key: string;
    slot: Slot | null;
    rarity: Rarity;
    name: string;
    icon: string;
    description: string;
    criteria: string;
    unlocked: boolean;
    equipped: boolean;
}

interface EquippedPayload {
    medal: string | null;
    ikat_kepala: string | null;
    kaus: string | null;
    celana: string | null;
    sepatu: string | null;
    aura: string | null;
}

interface AksesoriProps {
    items: AksesoriItem[];
    equipped: EquippedPayload;
}

const SLOT_LABEL: Record<Slot, string> = {
    medal: 'Medali',
    ikat_kepala: 'Ikat Kepala',
    kaus: 'Kaus',
    celana: 'Celana',
    sepatu: 'Sepatu',
    aura: 'Aura',
};

const SLOT_ORDER: Slot[] = ['medal', 'ikat_kepala', 'kaus', 'celana', 'sepatu', 'aura'];

export default function KoleksiAksesori({ items, equipped }: Readonly<AksesoriProps>) {
    const unlockedCount = items.filter((i) => i.unlocked).length;
    const eyebrow = `Koleksi · ${unlockedCount} / ${items.length} aksesori`;

    const aksesoriCount = `${unlockedCount} / ${items.length}`;

    const previewEquipped: TemariEquipped = {
        headband: equipped.ikat_kepala ? mapHeadband(equipped.ikat_kepala) : null,
        medal: mapMedal(equipped.medal),
        kaus: mapKaus(equipped.kaus),
        celana: mapCelana(equipped.celana),
        sepatu: mapSepatu(equipped.sepatu),
        aura: mapAura(equipped.aura),
    };

    const itemsBySlot: Record<string, AksesoriItem[]> = Object.fromEntries(
        SLOT_ORDER.map((s) => [s, []]),
    );
    for (const item of items) {
        if (item.slot) itemsBySlot[item.slot].push(item);
    }

    const equipItem = (key: string) => {
        router.post('/api/aksesori/equip', { unlock_key: key }, { preserveScroll: true });
    };

    return (
        <AppShell>
            <Head title="Koleksi · Aksesori" />
            <PageContainer>
                <CollectionHeader
                    active="aksesori"
                    eyebrow={eyebrow}
                    headline1="Dandanin Temari"
                    headline2="pake yang udah kamu dapet."
                    activeCount={aksesoriCount}
                />

                <HeroPanel className="mt-8 lg:px-14 lg:py-12">
                    <div className="grid items-center gap-8 lg:gap-10 lg:grid-cols-[220px_1fr]">
                        <div className="flex justify-center">
                            <TemariProto pose="proud" size={220} equipped={previewEquipped} animate />
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
                                        <span className="font-mono text-[11px] uppercase tracking-[0.14em] text-ink-on-sky">
                                            {SLOT_LABEL[slot]}
                                        </span>
                                        <span className="font-display text-base italic text-cream">
                                            {equippedLabelFor(slot, equipped, items)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-5 max-w-md font-display text-sm italic leading-relaxed text-cream/75">
                                &ldquo;Tiap kamu dapet aksesori baru, langsung aku siapin di sini.&rdquo; 🎀
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
        </AppShell>
    );
}

function equippedLabelFor(slot: Slot, equipped: EquippedPayload, items: AksesoriItem[]): string {
    const key = equipped[slot];
    if (!key) return 'belum dipake';
    const item = items.find((i) => i.unlock_key === key);
    return item ? item.name : 'dipake';
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
            <div className="grid grid-cols-2 gap-3.5 md:grid-cols-3 lg:grid-cols-4">
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
                <PillButton
                    tone="outline"
                    size="sm"
                    onClick={() => setShowLocked((s) => !s)}
                    className="mt-3.5 gap-1.5 px-4 py-2 text-xs font-semibold sm:hidden"
                >
                    <Icon icon={showLocked ? 'mdi:chevron-up' : 'mdi:chevron-down'} width={14} height={14} aria-hidden />
                    {showLocked
                        ? `Sembunyikan ${locked.length} belum kebuka`
                        : `+${locked.length} belum kebuka`}
                </PillButton>
            )}
        </section>
    );
}

function AksesoriCard({
    item,
    onEquip,
}: Readonly<{ item: AksesoriItem; onEquip: (key: string) => void }>) {
    const locked = !item.unlocked;
    const preview = keyToPreviewEquipped(item.unlock_key);
    let cardBorder: string;
    if (item.equipped) {
        cardBorder = 'border-[1.5px] border-horizon bg-horizon/[0.08]';
    } else if (locked) {
        cardBorder = 'border-2 border-dashed border-cream-deep bg-cream/40';
    } else {
        cardBorder = 'border border-cream-deep bg-cream';
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
                    Lagi dipake
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
                <h3 className={cn('font-display text-xl leading-tight tracking-[-0.01em]', RARITY_TEXT[item.rarity], 'text-ink')}>
                    {item.name}
                </h3>
                <p className="mt-1 font-sans text-sm text-ink-2">{item.description}</p>
            </div>
            {locked && (
                <p className="mt-auto font-display text-xs italic text-ink-3">{item.criteria}</p>
            )}
            {!locked && item.equipped && (
                <PillButton tone="horizon" size="sm" disabled className="mt-auto gap-1.5">
                    <Icon icon="mdi:check-circle" width={15} height={15} aria-hidden />
                    Terpasang
                </PillButton>
            )}
            {!locked && !item.equipped && (
                <PillButton tone="sky" size="sm" onClick={() => onEquip(item.unlock_key)} className="mt-auto gap-1.5">
                    <Icon icon="mdi:hanger" width={15} height={15} aria-hidden />
                    Pasang
                </PillButton>
            )}
        </article>
    );
}
