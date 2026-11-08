export default function DataUseList({
    points,
}: Readonly<{ points: string[] }>) {
    return (
        <ul className="mt-3 flex list-disc flex-col gap-1.5 pl-4.5">
            {points.map((point) => (
                <li key={point} className="text-xs leading-relaxed text-text-2">
                    {point}
                </li>
            ))}
        </ul>
    );
}
