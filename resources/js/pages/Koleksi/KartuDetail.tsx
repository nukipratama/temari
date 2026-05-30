import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useState } from 'react';
import AppShell from '@/layouts/AppShell';
import Card from '@/components/ui/Card';
import Chip from '@/components/ui/Chip';
import HeroPanel from '@/components/ui/HeroPanel';
import KartuComponent from '@/components/card/Kartu';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import Temari from '@/components/temari/Temari';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import ShareIgModal from '@/components/card/ShareIgModal';
import type { ShareKartuData } from '@/components/card/ShareIgModal';
import { cn } from '@/lib/cn';
import { fadeInUp } from '@/lib/motion';
import { formatDuration, formatIdDate, formatKm } from '@/lib/pace';
import { RARITY_BORDER, RARITY_LABELS, prettyBadge } from '@/lib/runcard';
import { renderBold } from '@/lib/richText';
import type { ActivityDetail, AnalysisPayload, Rarity } from '@/types/inertia';

// Short Indonesian descriptions for each badge key
const BADGE_DESCS: Record<string, string> = {
    negative_split: 'Paruh kedua lebih kenceng dari paruh pertama.',
    hari_panas: 'Tetap lari dan kontrol HR walau suhu tinggi.',
    pejuang_hujan: 'Nggak berhenti meski hujan, komitmen penuh.',
    anak_pagi: 'Mulai sebelum matahari tinggi, sebelum orang lain bangun.',
    long_slow_distance: 'Jarak panjang di pace santai, fondasi aerobik yang rapi.',
    tahan_diri: 'Pace terkontrol, HR rapi dari awal sampai akhir.',
};

interface CardPayload {
    id: number;
    activity_id: number;
    rarity: Rarity;
    special_move: string;
    badges: string[] | null;
    detail: ActivityDetail | null;
    flavor_analysis: AnalysisPayload;
}

interface RelatedCard {
    id: number;
    activity_id: number;
    rarity: Rarity;
    special_move: string;
    badges: string[] | null;
    detail: ActivityDetail | null;
}

interface KartuDetailProps {
    card: CardPayload;
    relatedCards: RelatedCard[];
    totalForRarity: number;
}

export default function KartuDetail({
    card,
    relatedCards,
    totalForRarity,
}: Readonly<KartuDetailProps>) {
    const detail = card.detail;
    const km = formatKm(detail?.distance);
    const durasi = detail?.moving_time == null ? '—' : formatDuration(detail.moving_time);
    const trimp =
        detail?.trimp_edwards == null ? '—' : String(Math.round(detail.trimp_edwards));
    const subtitle = detail
        ? `${detail.name ?? 'Lari'} · ${formatIdDate(detail.start_date_local, 'short')}`
        : null;
    const badges = (card.badges ?? []).slice(0, 3);
    const rarityLabel = RARITY_LABELS[card.rarity];

    const [shareOpen, setShareOpen] = useState(false);

    const shareDate = detail?.start_date_local
        ? (() => {
              const d = new Date(detail.start_date_local);
              const datePart = d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
              const timePart = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
              return `${datePart}\n${timePart}`;
          })()
        : null;

    const shareData: ShareKartuData = {
        id: card.id,
        name: card.special_move,
        rarity: card.rarity,
        subtitle,
        date: shareDate,
        km,
        durasi,
        trimp,
        hr: detail?.average_heartrate != null ? `${Math.round(detail.average_heartrate)} bpm` : null,
        location: detail?.location_name ?? null,
        weather: detail?.weather_temp_c != null ? `${Math.round(detail.weather_temp_c)}°C` : null,
        tags: badges.map((b) => prettyBadge(b)),
        quote: card.flavor_analysis.content ?? null,
    };

    return (
        <AppShell>
            <Head title={`${card.special_move} · Kartu`} />
            <motion.div
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-5 py-6 sm:px-8 lg:px-14 lg:py-8"
            >
                {/* Breadcrumb */}
                <Link
                    href="/kartu"
                    className="mb-6 inline-block font-mono text-[11px] uppercase tracking-[0.14em] text-ink-3 hover:text-ink"
                >
                    ← Koleksi · Kartu
                </Link>

                <div className="grid gap-10 lg:grid-cols-[1fr_1.1fr] lg:items-start">
                    {/* ── LEFT: sky hero with card ── */}
                    <HeroPanel
                        gradient={false}
                        className="lg:py-14 lg:px-12"
                        style={{ background: 'linear-gradient(165deg, var(--color-sky-deep), var(--color-sky-2))' }}
                    >
                        <div className="relative flex flex-col items-center gap-6 text-center">
                            {/* Glow */}
                            <span
                                aria-hidden
                                className="pointer-events-none absolute inset-x-0 bottom-1/4 mx-auto h-64 w-64 rounded-full"
                                style={{
                                    background:
                                        'radial-gradient(circle, oklch(82% 0.14 55 / 0.45), transparent 60%)',
                                    filter: 'blur(12px)',
                                }}
                            />

                            <Temari pose="excited" size={140} className="relative" />

                            <div className="relative w-full max-w-xs rotate-[-2deg] drop-shadow-2xl">
                                <KartuComponent
                                    name={card.special_move}
                                    subtitle={subtitle ?? undefined}
                                    km={km}
                                    durasi={durasi}
                                    trimp={trimp}
                                    rarity={card.rarity}
                                    tags={badges.map((b) => prettyBadge(b))}
                                    size="lg"
                                    onSky
                                    className="w-full"
                                />
                            </div>

                            <div className="relative flex flex-wrap justify-center gap-2">
                                <PillButton
                                    tone="horizon"
                                    size="sm"
                                    className="text-white"
                                    onClick={() => setShareOpen(true)}
                                >
                                    Bagikan
                                </PillButton>
                                <Link href={`/aktivitas/${card.activity_id}`}>
                                    <PillButton tone="ghost" onSky size="sm">
                                        Lihat detail lari
                                    </PillButton>
                                </Link>
                            </div>
                        </div>
                    </HeroPanel>

                    {/* ── RIGHT: lore ── */}
                    <div className="flex flex-col gap-6">
                        {/* Title block */}
                        <div>
                            <div className="mb-3 font-mono text-[10px] font-bold uppercase tracking-[0.18em] text-horizon-deep">
                                ★ {rarityLabel} · {totalForRarity} dari koleksimu
                            </div>
                            <h1 className="font-display text-display-lg leading-[0.92] tracking-[-0.025em] text-ink">
                                {card.special_move}.
                            </h1>
                            <div className="mt-3">
                                <AnalysisStatus
                                    analysis={card.flavor_analysis}
                                    inertiaReloadProps={['card']}
                                    allowReanalyze={false}
                                    showTimestamp={false}
                                    renderContent={(text) => (
                                        <p className="font-display text-quote-md italic leading-relaxed text-ink-2">
                                            &ldquo;{renderBold(text)}&rdquo;
                                        </p>
                                    )}
                                />
                            </div>
                        </div>

                        {/* Kenapa [rarity] — badge lore */}
                        {badges.length > 0 && (
                            <Card tone="cream-deep" padding="lg" className="flex flex-col gap-4">
                                <SectionLabel>Kenapa {rarityLabel}</SectionLabel>
                                <div className="flex flex-col gap-3">
                                    {badges.map((b, i) => (
                                        <div
                                            key={b}
                                            className={cn(
                                                'flex items-start gap-3 pb-3',
                                                i < badges.length - 1
                                                    ? 'border-b border-dashed border-cream-deep'
                                                    : '',
                                            )}
                                        >
                                            <Chip tone="horizon">{prettyBadge(b)}</Chip>
                                            <p className="flex-1 text-sm text-ink-2">
                                                {BADGE_DESCS[b] ??
                                                    'Kondisi spesial yang bikin lari ini istimewa.'}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </Card>
                        )}

                        {/* Linked run */}
                        {detail && (
                            <Link
                                href={`/aktivitas/${card.activity_id}`}
                                className="block"
                            >
                                <Card padding="md" className="flex items-center gap-4">
                                    <Temari pose="proud" size={48} animate={false} />
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-0.5 font-mono text-[9px] font-bold uppercase tracking-[0.14em] text-ink-3">
                                            Dari lari
                                        </div>
                                        <div className="font-display text-xl leading-tight tracking-[-0.005em] text-ink">
                                            {detail.name ?? 'Lari'}
                                        </div>
                                        <div className="mt-1 font-mono text-[10px] uppercase tracking-[0.1em] text-ink-3">
                                            {formatIdDate(detail.start_date_local, 'long')}
                                        </div>
                                    </div>
                                    <span className="font-mono text-[11px] font-semibold uppercase tracking-[0.12em] text-horizon-deep">
                                        Lihat detail →
                                    </span>
                                </Card>
                            </Link>
                        )}

                        {/* Related cards */}
                        {relatedCards.length > 0 && (
                            <div>
                                <SectionLabel className="mb-3">
                                    Kartu mirip di koleksimu
                                </SectionLabel>
                                <div className="grid grid-cols-3 gap-2.5">
                                    {relatedCards.map((c) => (
                                        <Link
                                            key={c.id}
                                            href={`/kartu/${c.id}`}
                                            className="block"
                                        >
                                            <div
                                                className={cn(
                                                    'rounded-xl border-[1.5px] bg-cream px-4 py-3',
                                                    RARITY_BORDER[c.rarity],
                                                )}
                                            >
                                                <div className="font-display text-[17px] leading-tight text-ink">
                                                    {c.special_move}
                                                </div>
                                                <div className="mt-1.5 font-mono text-[9px] uppercase tracking-[0.1em] text-ink-3">
                                                    {rarityLabel} ·{' '}
                                                    {formatIdDate(
                                                        c.detail?.start_date_local ?? null,
                                                        'short',
                                                    )}
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </motion.div>
            <ShareIgModal
                kartu={shareOpen ? shareData : null}
                onClose={() => setShareOpen(false)}
            />
        </AppShell>
    );
}
