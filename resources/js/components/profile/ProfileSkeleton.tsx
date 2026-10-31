import AiOutageBanner from '@/components/AiOutageBanner';
import Eyebrow from '@/components/ui/Eyebrow';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton, {
    SkeletonChart,
    SkeletonStats,
} from '@/components/ui/Skeleton';

export default function ProfileSkeleton() {
    return (
        <>
            <AiOutageBanner />
            <PageContainer reveal={false}>
                <Eyebrow token="hero" tone="ink-2">
                    Profile
                </Eyebrow>
                <div className="mt-2 mb-5 flex items-start justify-between gap-3">
                    <div className="flex-1">
                        <Skeleton className="h-9 w-2/5" />
                        <Skeleton className="mt-2 h-9 w-3/5" />
                    </div>
                    <Skeleton className="mt-1.5 size-16 flex-none rounded-full" />
                </div>
                <LaneStack className="mt-6">
                    <SkeletonStats />
                    <SkeletonChart />
                </LaneStack>
            </PageContainer>
        </>
    );
}
