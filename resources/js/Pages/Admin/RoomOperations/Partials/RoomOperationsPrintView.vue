<script setup>
import { computed } from 'vue';

// User request (2026-08-23 chat, second pass) — full redesign, replacing the
// same day's earlier 2-page-landscape version: "in dọc khổ A4" (portrait,
// see Index.vue's @page rule), "tối đa trong 1 trang", exactly 3 columns per
// room (room number / info block / clean-dirty), and "có thể tạo thành 2
// khung ... nhưng các khung phải tách nhau rõ ràng" — two clearly bordered,
// separated frames side by side, not one continuous table, so the narrow
// portrait width is used without the two halves reading as one merged grid.
//
// Still the SAME board data (`floors` prop, identical shape to Index.vue's
// filteredFloors — always matches whatever search/status filters are
// currently applied on screen), still plain text (not a screenshot of the
// colored interactive tiles) for B&W legibility + room for handwritten notes.
const props = defineProps({
    floors: { type: Array, default: () => [] },
    date: { type: String, default: '' },
});

const printedAt = computed(() => {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(now.getDate())}-${pad(now.getMonth() + 1)}-${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
});

function dateOnly(value) {
    if (!value) return null;
    return String(value).slice(0, 10);
}

// User request (2026-08-23 chat) — column 2's occupancy line is now one of
// exactly 5 fixed phrases instead of raw check-in/check-out timestamps,
// compared against the board's own selected `date` (not the browser's
// today) so a look-ahead or historical print date still reads correctly.
function occupancyStatus(occupant) {
    if (occupant.is_checked_out) return 'Đã trả phòng';

    if (occupant.is_checked_in) {
        if (dateOnly(occupant.end_at) === props.date) return 'Dự kiến trả phòng';
        if (dateOnly(occupant.actual_checkin_at) === props.date) return 'Đã nhận phòng';
        return 'Phòng lưu';
    }

    return 'Dự kiến hôm nay nhận phòng';
}

// User request (2026-08-23 chat) — "Yêu cầu đặc biệt: hiển thị rõ nội dung
// yêu cầu (ghép giường/tách giường/giường phụ/...)": every source the board
// already merges per room (RoomOperationsBoardService::buildRoomCell()) —
// bed_join badge, extra_bed_quantity, special_requests (legacy
// BookingSpecialRequest content, e.g. "Tách giường"), and other_services
// (Unified Request Catalog enrollments) — spelled out as plain text instead
// of the old emoji-only badges, since a printed page can't show a hover
// tooltip.
function specialRequestItems(occupant) {
    const items = [];
    if (occupant.bed_join) items.push('Ghép giường');
    if (occupant.extra_bed_quantity > 0) items.push(`Giường phụ x${occupant.extra_bed_quantity}`);
    for (const request of occupant.special_requests ?? []) {
        items.push(request.label);
    }
    for (const service of occupant.other_services ?? []) {
        items.push(service.quantity > 1 ? `${service.name} x${service.quantity}` : service.name);
    }
    return items;
}

// Flattens every floor's rooms into one ordered list (floor sort order,
// then room_number — same order the board already provides), tagging each
// room with its floor's label so a floor-change can still be marked inside
// either of the two frames below.
const flatRooms = computed(() => props.floors.flatMap(
    (floor) => floor.rooms.map((room) => ({ ...room, floorLabel: floor.name || floor.code })),
));

// Split into two frames by straight count (not by floor) — the most
// reliable way to keep both frames roughly the same height regardless of
// how unevenly rooms are distributed across floors, which is what actually
// keeps this on 1 page. See the top comment for why two frames exist at all.
const halfIndex = computed(() => Math.ceil(flatRooms.value.length / 2));
const leftRooms = computed(() => flatRooms.value.slice(0, halfIndex.value));
const rightRooms = computed(() => flatRooms.value.slice(halfIndex.value));

/** Interleaves a floor-divider row wherever floorLabel changes, so each frame keeps its own floor grouping. */
function toRenderRows(rooms) {
    const rows = [];
    let lastFloor = null;
    for (const room of rooms) {
        if (room.floorLabel !== lastFloor) {
            rows.push({ kind: 'floor', key: `floor-${room.floorLabel}-${room.id}`, label: room.floorLabel });
            lastFloor = room.floorLabel;
        }
        rows.push({ kind: 'room', key: `room-${room.id}`, room });
    }
    return rows;
}

const leftRows = computed(() => toRenderRows(leftRooms.value));
const rightRows = computed(() => toRenderRows(rightRooms.value));
</script>

<template>
    <div class="print-page">
        <h1 class="print-title">SƠ ĐỒ THAO TÁC — {{ date }}</h1>
        <p class="print-meta">In lúc: {{ printedAt }}</p>

        <div class="print-frames">
            <table v-for="(rows, frameIndex) in [leftRows, rightRows]" :key="frameIndex" class="print-frame">
                <thead>
                    <tr>
                        <th class="col-room">Phòng</th>
                        <th class="col-info">Thông tin</th>
                        <th class="col-status">T.thái</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows" :key="row.key">
                        <tr v-if="row.kind === 'floor'" class="floor-row">
                            <td colspan="3">Tầng {{ row.label }}</td>
                        </tr>
                        <tr v-else>
                            <td class="col-room"><strong>{{ row.room.room_number }}</strong></td>
                            <td class="col-info">
                                <template v-if="row.room.occupant">
                                    <div><strong>{{ row.room.occupant.customer_name || '—' }}</strong> — {{ occupancyStatus(row.room.occupant) }}</div>
                                    <div v-if="row.room.occupant.quick_note" class="quick-note">Ghi chú: {{ row.room.occupant.quick_note }}</div>
                                    <div v-if="specialRequestItems(row.room.occupant).length" class="special-request">
                                        Yêu cầu: {{ specialRequestItems(row.room.occupant).join(', ') }}
                                    </div>
                                </template>
                                <template v-else>Phòng trống</template>
                            </td>
                            <td class="col-status">{{ row.room.is_clean ? 'Sạch' : 'Bẩn' }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
/* User request (2026-08-22/23 chat) — table-based, not the colored tile grid:
   legible in B&W, leaves room for a handwritten tick per row. Font sizes
   are print-pt (pt is meaningful for @page-sized output, unlike screen px). */
.print-page {
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
}

.print-title {
    font-size: 12pt;
    font-weight: 700;
    margin: 0 0 1mm 0;
}

.print-meta {
    font-size: 7pt;
    color: #444;
    margin: 0 0 2mm 0;
}

/* User request (2026-08-23 chat) — "2 khung ... tách nhau rõ ràng": a
   visible gap plus its own border/box around each frame, so the two halves
   never read as one continuous table even though the data is really just
   one flat room list split in half. */
.print-frames {
    display: flex;
    gap: 6mm;
    align-items: flex-start;
}

.print-frame {
    flex: 1 1 0;
    min-width: 0;
    border: 0.5mm solid #000;
    border-collapse: collapse;
    font-size: 7pt;
}

/* Repeats the column header on every printed page the table spans (standard
   print CSS — @page break lands mid-table if a frame overflows 1 page). */
.print-frame thead {
    display: table-header-group;
}

.print-frame tr {
    break-inside: avoid;
}

.print-frame th,
.print-frame td {
    border: 0.25mm solid #999;
    padding: 0.6mm 1mm;
    text-align: left;
    vertical-align: top;
}

.print-frame th {
    font-weight: 700;
    background: #f2f2f2;
    border-bottom: 0.4mm solid #000;
}

.floor-row td {
    background: #e5e5e5;
    font-weight: 700;
    border-top: 0.4mm solid #000;
    border-bottom: 0.4mm solid #000;
}

.col-room { width: 12%; }
.col-info { width: 76%; }
.col-status { width: 12%; text-align: center; }

.quick-note,
.special-request {
    font-style: italic;
    color: #333;
}
</style>
