import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import Card from '@/components/ui/Card';
import SectionLabel from '@/components/ui/SectionLabel';
import Temari from '@/components/temari/Temari';

const ACTIONS = [
    {
        icon: 'mdi:cards-outline',
        title: 'Cek koleksi yang Legendaris',
        desc: 'Liat kartu yang bisa kamu dapetin.',
        href: '/kartu',
    },
    {
        icon: 'mdi:tshirt-crew-outline',
        title: 'Dandanin Temari',
        desc: 'Pilih kombinasi aksesori untuk profilmu.',
        href: '/aksesori',
    },
    {
        icon: 'mdi:chart-line',
        title: 'Lihat rekap lari kamu',
        desc: 'Begitu lari pertama masuk, rekap langsung muncul di sini.',
        href: '/riwayat',
    },
] as const;

export default function EmptyRunsState() {
    return (
        <div className="flex flex-col items-center gap-8 px-5 py-10 sm:px-8 lg:px-14">
            {/* Temari + headline */}
            <div className="flex flex-col items-center gap-5 text-center">
                <Temari pose="reading" size={140} />
                <div>
                    <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                        ★ Lagi nungguin
                    </div>
                    <h2 className="font-display text-display-sm text-ink">
                        Belum ada lari yang masuk.
                    </h2>
                    <p className="mx-auto mt-3 max-w-sm font-display text-quote-sm italic leading-relaxed text-ink-2">
                        &ldquo;Begitu kamu kelar lari pertamamu, aku langsung baca. Kartu
                        pertamamu udah aku siapin di lemari.&rdquo;
                    </p>
                </div>
            </div>

            {/* Sambil nungguin */}
            <Card padding="md" className="w-full max-w-md">
                <SectionLabel>Sambil nungguin</SectionLabel>
                <div className="mt-3 flex flex-col gap-2">
                    {ACTIONS.map(({ icon, title, desc, href }) => (
                        <Link
                            key={title}
                            href={href}
                            className="flex items-center gap-3 rounded-xl bg-surface-card px-4 py-3"
                        >
                            <span
                                aria-hidden
                                className="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-horizon/[0.14] text-horizon-deep"
                            >
                                <Icon icon={icon} width={16} height={16} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[13px] font-semibold text-ink">{title}</div>
                                <div className="mt-0.5 font-mono text-[11px] text-ink-3">{desc}</div>
                            </div>
                            <span aria-hidden className="font-mono text-[14px] text-ink-3">›</span>
                        </Link>
                    ))}
                </div>
            </Card>
        </div>
    );
}
