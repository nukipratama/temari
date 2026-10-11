import RaceHeading from '@/components/race/RaceHeading';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton from '@/components/ui/Skeleton';

export function RaceBodySkeleton() {
    return (
        <>
            <Skeleton className="mt-4 h-40 w-full rounded-lg" />
            <Skeleton className="mt-3 h-8 w-full" />
        </>
    );
}

export default function RaceSkeleton() {
    return (
        <PageContainer reveal={false}>
            <RaceHeading />
            <RaceBodySkeleton />
        </PageContainer>
    );
}
