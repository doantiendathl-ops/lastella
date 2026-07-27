/**
 * Single source of truth for RoomStatus (App\Enums\RoomStatus) badge styling.
 * Reused by the Housekeeping board and the Room Availability page's CLEANING badge —
 * do not duplicate these class strings in either page.
 */
export const roomStatusBadges = {
    VACANT_CLEAN: {
        card: 'border-green-200 bg-green-50',
        dot: 'bg-green-400',
        badge: 'bg-green-100 text-green-800',
    },
    VACANT_DIRTY: {
        card: 'border-yellow-300 bg-yellow-50',
        dot: 'bg-yellow-400',
        badge: 'bg-yellow-100 text-yellow-800',
    },
    CLEANING: {
        card: 'border-amber-400 bg-amber-50',
        dot: 'bg-amber-500',
        badge: 'bg-amber-100 text-amber-800',
    },
    INSPECTED: {
        card: 'border-teal-300 bg-teal-50',
        dot: 'bg-teal-400',
        badge: 'bg-teal-100 text-teal-800',
    },
    OCCUPIED: {
        card: 'border-blue-300 bg-blue-50',
        dot: 'bg-blue-400',
        badge: 'bg-blue-100 text-blue-800',
    },
    RESERVED: {
        card: 'border-blue-200 bg-blue-50',
        dot: 'bg-blue-300',
        badge: 'bg-blue-100 text-blue-700',
    },
    OUT_OF_ORDER: {
        card: 'border-red-300 bg-red-50',
        dot: 'bg-red-500',
        badge: 'bg-red-100 text-red-800',
    },
    OUT_OF_SERVICE: {
        card: 'border-gray-300 bg-gray-100',
        dot: 'bg-gray-400',
        badge: 'bg-gray-200 text-gray-600',
    },
};

const fallback = {
    card: 'border-gray-200 bg-white',
    dot: 'bg-gray-300',
    badge: 'bg-gray-100 text-gray-600',
};

export function roomStatusBadge(status) {
    return roomStatusBadges[status] ?? fallback;
}

/**
 * Room Operations Simplification: styling for the standalone SẠCH/BẨN badge
 * (CleaningStatus), independent of the operational roomStatusBadges above.
 */
export const cleaningStatusBadges = {
    CLEAN: {
        card: 'border-green-200 bg-green-50',
        badge: 'bg-green-100 text-green-800',
    },
    DIRTY: {
        card: 'border-yellow-300 bg-yellow-50',
        badge: 'bg-yellow-100 text-yellow-800',
    },
};

export function cleaningStatusBadge(status) {
    return cleaningStatusBadges[status] ?? fallback;
}
