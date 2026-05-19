import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatIdDate } from '@/lib/pace';
import AppShell from '@/layouts/AppShell';
import DecorativeBlur from '@/components/DecorativeBlur';
import PageHero from '@/components/PageHero';
import { fadeInUp } from '@/lib/motion';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import type { AnalysisPayload, PersonalRecord } from '@/types/inertia';

interface ExtendedPR extends Omit<PersonalRecord, 'activity'> {
    value_sec: number;
    set_at: string;
    activity?: { detail?: { name?: string | null } | null };
    context_analysis?: AnalysisPayload;
}

interface RekorProps {
    personalRecords: ExtendedPR[];
}

const DISTANCE_CATEGORIES = new Set(['1km', '5km', '10km', '15km', 'half_marathon', 'marathon']);

const PR_CATEGORY_LABELS: Record<string, string> = {
    '1km': '1 KM',
    '5km': '5 KM',
    '10km': '10 KM',
    '15km': '15 KM',
    half_marathon: 'Half Marathon',
    marathon: 'Marathon',
    best_5min: 'Best 5 minutes',
    best_10min: 'Best 10 minutes',
    best_20min: 'Best 20 minutes',
    best_60min: 'Best 60 minutes',
};

type PrTone = 'brand' | 'accent' | 'pop' | 'spinning';

interface PrVariant {
    border: string;
    bg: string;
    topRule: string;
    blob: string;
    label: string;
    value: string;
    iconBg: string;
    icon: string;
    focusRing: string;
}

const PR_VARIANT: Record<PrTone, PrVariant> = {
    brand: {
        border: 'border-brand-300 hover:border-brand-500',
        bg: 'from-brand-50 via-surface-elev to-brand-100/60',
        topRule: 'from-brand-300 via-brand-500 to-brand-300',
        blob: 'bg-brand-200/50',
        label: 'text-brand-700',
        value: 'text-brand-800',
        iconBg: 'bg-brand-500',
        icon: 'mdi:flash',
        focusRing: 'focus-visible:ring-brand-500',
    },
    accent: {
        border: 'border-accent-300 hover:border-accent-500',
        bg: 'from-accent-50 via-surface-elev to-accent-100/60',
        topRule: 'from-accent-300 via-accent-500 to-accent-300',
        blob: 'bg-accent-200/50',
        label: 'text-accent-700',
        value: 'text-accent-800',
        iconBg: 'bg-accent-500',
        icon: 'mdi:medal',
        focusRing: 'focus-visible:ring-accent-500',
    },
    pop: {
        border: 'border-pop-300 hover:border-pop-500',
        bg: 'from-pop-50 via-surface-elev to-pop-100/60',
        topRule: 'from-pop-300 via-pop-500 to-pop-300',
        blob: 'bg-pop-200/50',
        label: 'text-pop-700',
        value: 'text-pop-800',
        iconBg: 'bg-pop-500',
        icon: 'mdi:crown',
        focusRing: 'focus-visible:ring-pop-500',
    },
    spinning: {
        border: 'border-mood-spinning/40 hover:border-mood-spinning',
        bg: 'from-mood-spinning/10 via-surface-elev to-mood-spinning/15',
        topRule: 'from-mood-spinning/40 via-mood-spinning to-mood-spinning/40',
        blob: 'bg-mood-spinning/25',
        label: 'text-mood-spinning',
        value: 'text-mood-spinning',
        iconBg: 'bg-mood-spinning',
        icon: 'mdi:timer-sand',
        focusRing: 'focus-visible:ring-mood-spinning',
    },
};

function toneForCategory(category: string): PrTone {
    switch (category) {
        case '1km':
        case '5km':
            return 'brand';
        case '10km':
        case '15km':
            return 'accent';
        case 'half_marathon':
        case 'marathon':
            return 'pop';
        default:
            return 'spinning';
    }
}

export default function Rekor({ personalRecords }: Readonly<RekorProps>) {
    return (
        <AppShell>
            <Head title="Rekor" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-6 py-10"
            >
                <PageHero
                    icon="mdi:trophy-variant"
                    title="Rekor"
                    subtitle="Catatan terbaik kamu — sentuh kartunya buat lihat run aslinya."
                    tone="pop"
                    className="mb-6"
                />

                {personalRecords.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {personalRecords.map((pr) => (
                            <PrCard key={pr.id} pr={pr} />
                        ))}
                    </div>
                )}
            </motion.main>
        </AppShell>
    );
}

function EmptyState() {
    return (
        <div className="rounded-2xl border border-dashed border-line bg-surface-elev/40 p-10 text-center">
            <Icon icon="mdi:trophy-outline" width={32} height={32} className="mx-auto text-ink-meta" aria-hidden />
            <p className="mt-3 text-sm leading-relaxed text-ink-soft">
                Belum ada PR. Run dengan splits + best-effort paces akan otomatis terkumpul di sini.
            </p>
        </div>
    );
}

function PrCard({ pr }: Readonly<{ pr: ExtendedPR }>) {
    const v = PR_VARIANT[toneForCategory(pr.category)];
    const card = (
        <div className={cn('group relative h-full overflow-hidden rounded-2xl border bg-gradient-to-br p-5 shadow-md transition hover:-translate-y-0.5 hover:shadow-lg', v.border, v.bg)}>
            <span aria-hidden className={cn('absolute inset-x-0 top-0 h-1 bg-gradient-to-r', v.topRule)} />
            <DecorativeBlur className={cn('-right-8 -top-8 h-24 w-24', v.blob)} />
            <div className="relative flex items-start justify-between">
                <div>
                    <div className={cn('text-xs font-semibold uppercase tracking-wider', v.label)}>
                        {PR_CATEGORY_LABELS[pr.category] ?? pr.category}
                    </div>
                    <div className={cn('mt-1 text-3xl font-black tabular-nums', v.value)}>
                        {formatPrValue(pr.category, pr.value_sec)}
                    </div>
                </div>
                <span
                    aria-hidden
                    className={cn('flex h-10 w-10 items-center justify-center rounded-xl text-white shadow-md ring-2 ring-white', v.iconBg)}
                >
                    <Icon icon={v.icon} width={20} height={20} />
                </span>
            </div>
            <div className="relative mt-4 truncate text-sm font-medium text-ink-soft">
                {pr.activity?.detail?.name ?? 'Run'}
            </div>
            <div className="relative mt-0.5 text-xs text-ink-meta">{formatIdDate(pr.set_at, 'long')}</div>
            {pr.context_analysis && (
                <div className="relative mt-3">
                    <AnalysisStatus
                        analysis={pr.context_analysis}
                        inertiaReloadProps={['personalRecords']}
                        size="sm"
                    />
                </div>
            )}
        </div>
    );

    if (pr.activity_id === null) {
        return card;
    }
    return (
        <Link href={`/aktivitas/${pr.activity_id}`} className={cn('block rounded-2xl focus:outline-none focus-visible:ring-2', v.focusRing)}>
            {card}
        </Link>
    );
}

function formatPrValue(category: string, secs: number): string {
    if (DISTANCE_CATEGORIES.has(category)) {
        return formatDurationHMS(secs);
    }
    return `${Math.floor(secs / 60)}:${(secs % 60).toString().padStart(2, '0')}/km`;
}
