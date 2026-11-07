/** Hard minutes as the budget shows them: whole numbers bare, otherwise one decimal. */
export function formatHardMinutes(minutes: number): string {
    return Number.isInteger(minutes) ? `${minutes}` : minutes.toFixed(1);
}
