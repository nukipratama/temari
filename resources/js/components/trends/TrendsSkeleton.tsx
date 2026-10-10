import AiOutageBanner from '@/components/AiOutageBanner';
import TrendsHeading from '@/components/trends/TrendsHeading';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import {
    SkeletonChart,
    SkeletonProse,
    SkeletonStats,
} from '@/components/ui/Skeleton';

export function TrendsSectionSkeleton({
    chart = false,
}: Readonly<{ chart?: boolean }>) {
    return (
        <div>
            <div className="h-4 w-32 rounded-xs bg-muted" />
            {chart ? (
                <SkeletonChart className="mt-2.5 h-[10.5rem]" />
            ) : (
                <SkeletonStats className="mt-2.5" />
            )}
        </div>
    );
}

export default function TrendsSkeleton() {
    return (
        <>
            <AiOutageBanner />
            <PageContainer reveal={false}>
                <TrendsHeading />
                <LaneStack className="mt-6">
                    <SkeletonProse />
                    <TrendsSectionSkeleton />
                    <TrendsSectionSkeleton chart />
                    <TrendsSectionSkeleton />
                </LaneStack>
            </PageContainer>
        </>
    );
}
