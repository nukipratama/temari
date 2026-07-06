import Kartu from '@/components/card/Kartu';
import FeaturedCardHero from '@/components/card/FeaturedCardHero';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import ExpandableQuote from '@/components/dashboard/ExpandableQuote';
import { aktivitasUrl } from '@/lib/routes';
import type { FeaturedCard } from '@/pages/HariIni/helpers';
import type { AnalysisPayload } from '@/types/inertia';

export default function FeaturedKartuPanel({
    featured,
    featuredKartuVoice,
}: Readonly<{ featured: FeaturedCard; featuredKartuVoice: AnalysisPayload }>) {
    return (
        <FeaturedCardHero
            eyebrow="★ Kartu andalan dari Temari"
            name={featured.name}
            rarity={featured.rarity}
            km={featured.km}
            stats={featured.stats}
            durasi={featured.durasi}
            badges={featured.badges}
            ctaHref={aktivitasUrl({ activity_id: featured.activityId })}
            voice={
                <AnalysisStatus
                    analysis={featuredKartuVoice}
                    inertiaReloadProps={['briefing']}
                    showTimestamp={false}
                    allowReanalyze={false}
                    onSky
                    renderContent={(text) => <ExpandableQuote text={text} onSky />}
                />
            }
            card={
                <Kartu
                    name={featured.name}
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
