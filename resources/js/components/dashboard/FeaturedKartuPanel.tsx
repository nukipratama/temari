import type { FeaturedCard } from '@/pages/Home/helpers';
import type { AnalysisPayload } from '@/types/inertia';

import FeaturedCardHero from '@/components/card/FeaturedCardHero';
import Kartu from '@/components/card/Kartu';
import ExpandableQuote from '@/components/dashboard/ExpandableQuote';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { activityUrl } from '@/lib/routes';

export default function FeaturedKartuPanel({
    featured,
    featuredKartuVoice,
}: Readonly<{ featured: FeaturedCard; featuredKartuVoice: AnalysisPayload }>) {
    return (
        <FeaturedCardHero
            eyebrow="★ Temari's top pick"
            name={featured.name}
            rarity={featured.rarity}
            km={featured.km}
            stats={featured.stats}
            duration={featured.duration}
            badges={featured.badges}
            polyline={featured.polyline}
            ctaHref={activityUrl({ activity_id: featured.activityId })}
            voice={
                featuredKartuVoice.status !== 'pending' && (
                    <AnalysisStatus
                        analysis={featuredKartuVoice}
                        inertiaReloadProps={['briefing']}
                        showTimestamp={false}
                        allowReanalyze={false}
                        onSky
                        renderContent={(text) => (
                            <ExpandableQuote text={text} onSky />
                        )}
                    />
                )
            }
            card={
                <Kartu
                    name={featured.name}
                    km={featured.km}
                    duration={featured.duration}
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
