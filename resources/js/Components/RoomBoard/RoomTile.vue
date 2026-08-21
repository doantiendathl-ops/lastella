<script setup>
// docs/yeucaumoi.txt mục 6 — shared Room Tile shell, used by all 4 Room Map
// screens (Sơ đồ thao tác, Sơ đồ chọn phòng, Kiểm tra phòng, Kiểm đồ trả
// phòng) so they render with the SAME visual identity, not just the same
// color principle applied independently per screen. This component owns
// ONLY the shell: background (booking_color when occupied, per mục 7),
// auto-contrast text (mục 7), selected/conflict border (mục 9, never
// background), and the conflict banner. Everything business-specific
// (checkbox, occupant info, status icons, footer notes, actions) is a slot
// — mục 6's own closing line ("không ép mọi màn hình giống nghiệp vụ") is
// why this stays a shell instead of a rigid one-size-fits-all card.
//
// Click/keyboard handling is deliberately NOT wired here: role/tabindex/
// @click/@keydown are native HTML attributes and Vue automatically forwards
// them from the parent onto this component's root element (attribute
// fallthrough) — each screen keeps its own exact interaction semantics
// (toggle-select vs open-info-panel vs select-room) unchanged.
import { readableTextClass } from '@/Support/colorContrast';
import { computed } from 'vue';

const props = defineProps({
    roomNumber: { type: [String, Number], required: true },
    roomTypeLabel: { type: String, default: '' },
    bookingColor: { type: String, default: null },
    // Tailwind border+bg classes used when there is no bookingColor (vacant/
    // no-single-booking states) — each screen supplies its own semantic
    // classes here; this component never invents vacant-state colors.
    vacantClass: { type: String, default: 'border-gray-200 bg-white' },
    selected: { type: Boolean, default: false },
    conflict: { type: Boolean, default: false },
    conflictLabel: { type: String, default: 'Trùng phòng' },
});

const cardStyle = computed(() => (props.bookingColor ? { backgroundColor: props.bookingColor } : {}));
const cardTextClass = computed(() => (props.bookingColor ? readableTextClass(props.bookingColor) : 'text-gray-900'));
const cardThemeClass = computed(() => (props.bookingColor ? 'border-gray-300' : props.vacantClass));
const ringClass = computed(() => {
    if (props.conflict) return 'ring-[3px] ring-red-600 ring-offset-2';
    if (props.selected) return 'ring-[3px] ring-indigo-600 ring-offset-2';
    return '';
});
</script>

<template>
    <div
        class="relative flex w-64 shrink-0 flex-col gap-1.5 rounded-lg border p-2.5 text-xs shadow-sm transition hover:shadow-md"
        :class="[cardThemeClass, cardTextClass, ringClass]"
        :style="cardStyle"
    >
        <div v-if="selected" class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-white shadow">
            <slot name="selected-icon">✓</slot>
        </div>

        <!-- User request (2026-08-20 chat) — numbered pairing badge for the
             board-based "Đổi phòng" flow (source room N <-> replacement room
             N); top-LEFT so it never collides with the top-right selected
             checkmark above. Only RoomOperationsCell.vue populates this. -->
        <slot name="swap-badge" />

        <div v-if="conflict" class="flex items-center gap-1 rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">
            <slot name="conflict-icon" />
            <span class="truncate">{{ conflictLabel }}</span>
        </div>

        <div class="flex items-center justify-between gap-1">
            <!-- User request (2026-08-19 chat) — checkbox (+ any extra header
                 action, e.g. Sơ đồ thao tác's "select all rooms of this
                 booking" button) now sit AFTER the room number, not before.
                 Only RoomOperationsCell.vue populates #checkbox/#header-extra
                 today, so this reorder has no visual effect on the other 3
                 Room Map screens that share this shell. -->
            <div class="flex min-w-0 items-center gap-1">
                <span class="truncate font-semibold">{{ roomNumber }}</span>
                <slot name="checkbox" />
                <slot name="header-extra" />
            </div>
            <span v-if="roomTypeLabel" class="shrink-0 rounded bg-white/90 px-1.5 py-0.5 text-[10px] font-medium text-gray-700">{{ roomTypeLabel }}</span>
        </div>

        <slot name="body" />

        <slot name="status-row" />

        <slot name="footer" />
    </div>
</template>
