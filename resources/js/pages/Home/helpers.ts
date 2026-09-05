export function formatSignedForm(form: number): string {
    return form >= 0 ? `+${form.toFixed(1)}` : form.toFixed(1);
}

/**
 * The district-level location only, skipping the specific venue/landmark first
 * part: "Gelora Bung Karno, Jakarta Pusat, DKI Jakarta" -> "Jakarta Pusat".
 * Falls back to the sole part when there's no district segment.
 */
export function districtFromLocation(name: string | null): string | null {
    if (name === null || name === '') return null;
    const parts = name
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    if (parts.length === 0) return null;
    return parts[1] ?? parts[0];
}
