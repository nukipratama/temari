import RaceHeading from '@/components/race/RaceHeading';
import PageContainer from '@/components/ui/PageContainer';
import Skeleton from '@/components/ui/Skeleton';

export function RaceBodySkeleton() {
    return (
        <>
            <Skeleton className="mt-4 h-[9.5rem] w-full rounded-lg max-[374px]:h-[11.5rem] sm:h-[8.25rem]" />
            <Skeleton className="mt-3 h-[2.8125rem] w-full" />
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
