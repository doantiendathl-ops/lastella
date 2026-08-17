// docs/Prompt_1.txt mục V.4 — Auto Text Contrast.
//
// When a hex color becomes the background of a Room Tile / booking card,
// the text/icon color on top of it must be chosen from the background's
// luminance, not hard-coded. WCAG 2.x relative luminance + contrast ratio,
// same formula browsers use for accessibility contrast checks.

/**
 * @param {string} hex e.g. "#1F3864" or "#fff"
 * @returns {[number, number, number]} 0-255 RGB channels
 */
function hexToRgb(hex) {
    let normalized = hex.replace('#', '');
    if (normalized.length === 3) {
        normalized = normalized.split('').map((c) => c + c).join('');
    }
    const int = parseInt(normalized, 16);
    return [(int >> 16) & 255, (int >> 8) & 255, int & 255];
}

function relativeLuminance([r, g, b]) {
    const channel = (c) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/**
 * Contrast ratio between two hex colors, per WCAG 2.x (1:1 to 21:1).
 */
export function contrastRatio(hexA, hexB) {
    const lumA = relativeLuminance(hexToRgb(hexA));
    const lumB = relativeLuminance(hexToRgb(hexB));
    const [lighter, darker] = lumA >= lumB ? [lumA, lumB] : [lumB, lumA];
    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Pick black or white — whichever reads better on top of the given
 * background hex. Used for any text/icon painted directly on a
 * booking-colored background (Room Tile, selected room card...).
 *
 * @param {string} backgroundHex
 * @returns {'#000000' | '#FFFFFF'}
 */
export function readableTextColor(backgroundHex) {
    if (!backgroundHex) return '#000000';
    const contrastWithBlack = contrastRatio(backgroundHex, '#000000');
    const contrastWithWhite = contrastRatio(backgroundHex, '#FFFFFF');
    return contrastWithBlack >= contrastWithWhite ? '#000000' : '#FFFFFF';
}

/**
 * Tailwind utility class equivalent of readableTextColor(), for templates
 * that prefer a class binding over an inline style.
 */
export function readableTextClass(backgroundHex) {
    return readableTextColor(backgroundHex) === '#000000' ? 'text-gray-900' : 'text-white';
}

/**
 * Same contrast decision as readableTextClass(), for small filled shapes
 * (status dots/icons) painted directly on a booking-colored background
 * rather than text.
 */
export function readableSurfaceClass(backgroundHex) {
    return readableTextColor(backgroundHex) === '#000000' ? 'bg-gray-900' : 'bg-white';
}
