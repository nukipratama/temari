import Card from '@/components/ui/Card';
import SectionLabel from '@/components/ui/SectionLabel';
import Temari from '@/components/temari/Temari';
import { type TemariPose } from '@/components/temari/TemariProto';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import ExpandableQuote from '@/components/dashboard/ExpandableQuote';
import type { BriefingResult } from '@/types/inertia';

export default function KataTemariCompact({ briefing, pose }: Readonly<{ briefing: BriefingResult; pose: TemariPose }>) {
    return (
        <Card padding="lg" className="flex items-start gap-3.5">
            <Temari pose={pose} size={48} animate={false} />
            <div className="min-w-0 flex-1">
                <SectionLabel dot className="mb-1.5">Kata Temari hari ini</SectionLabel>
                <AnalysisStatus
                    analysis={briefing.mascotVoice}
                    inertiaReloadProps={['briefing']}
                    size="sm"
                    renderContent={(text) => (
                        <ExpandableQuote text={text} />
                    )}
                />
            </div>
        </Card>
    );
}
