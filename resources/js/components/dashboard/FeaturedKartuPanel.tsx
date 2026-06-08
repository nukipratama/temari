import Kartu from '@/components/card/Kartu';
import FeaturedCardHero from '@/components/card/FeaturedCardHero';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { renderBold } from '@/lib/richText';
import { kartuUrl } from '@/lib/routes';
import type { FeaturedCard } from '@/pages/HariIni/helpers';
import type { AnalysisPayload } from '@/types/inertia';

export default function FeaturedKartuPanel({
    featured,
    featuredKartuVoice,
}: Readonly<{ featured: FeaturedCard; featuredKartuVoice: AnalysisPayload }>) {
    return (
        <FeaturedCardHero
            eyebrow="★ Kartu dari Temari minggu ini"
            name={featured.name}
            rarity={featured.rarity}
            km={featured.km}
            stats={featured.stats}
            durasi={featured.durasi}
            badges={featured.badges}
            ctaHref={kartuUrl({ id: featured.cardId })}
            voice={
                <AnalysisStatus
                    analysis={featuredKartuVoice}
                    inertiaReloadProps={['briefing']}
                    showTimestamp={false}
                    allowReanalyze={false}
                    onSky
                    renderContent={(text) => (
                        <p className="font-display text-base italic leading-relaxed text-cream">
                            &ldquo;{renderBold(text)}&rdquo;
                        </p>
                    )}
                />
            }
            card={
                <Kartu
                    name={featured.name}
                    subtitle={featured.subtitle}
                    km={featured.km}
                    durasi={featured.durasi}
                    trimp={featured.trimp}
                    rarity={featured.rarity}
                    mood={featured.mood}
                    badges={featured.badges}
                    stats={featured.stats}
                    zonePct={featured.zonePct}
                    polyline={featured.polyline}
                    paceShape={featured.paceShape}
                    size="md"
                    className="w-full"
                />
            }
        />
    );
}
