<script setup>
import { formatDateShort } from '@/Support/format';
import { computed } from 'vue';

// User request (2026-08-22 chat) — "in sơ đồ thao tác trên trang A4... vì
// không phải lúc nào buồng cũng có thể xử lý trên máy tính hoặc điện
// thoại": a print-only table view of the SAME board data (`floors` prop —
// identical shape to Index.vue's filteredFloors, so it always matches
// whatever the operator currently has on screen, filters included), laid
// out for a physical checklist buồng phòng can carry room to room, not a
// screenshot of the colored interactive tiles (bad for B&W printers, no
// room for handwritten notes). See Index.vue's <style> block for how this
// becomes the ONLY thing that renders when the browser prints.
const props = defineProps({
    floors: { type: Array, default: () => [] },
    date: { type: String, default: '' },
});

const printedAt = computed(() => {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(now.getDate())}-${pad(now.getMonth() + 1)}-${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
});

function roomStatusText(room) {
    const clean = room.is_clean ? 'Sạch' : 'Bẩn';
    const occ = room.occupant;
    if (!occ) return `Trống — ${clean}`;
    if (occ.is_checked_out) return `Đã trả phòng — ${clean}`;
    if (occ.is_checked_in) return `Đang ở — ${clean}`;
    return `Chờ nhận phòng — ${clean}`;
}

function checkinCellText(occ) {
    if (!occ) return '—';
    return occ.is_checked_in
        ? `Đã nhận: ${formatDateShort(occ.actual_checkin_at)}`
        : `Dự kiến: ${formatDateShort(occ.start_at)}`;
}

function checkoutCellText(occ) {
    if (!occ) return '—';
    return occ.is_checked_out
        ? `Đã trả: ${formatDateShort(occ.actual_checkout_at)}`
        : `Dự kiến: ${formatDateShort(occ.end_at)}`;
}
</script>

<template>
    <div class="print-page">
        <h1 class="print-title">SƠ ĐỒ THAO TÁC — {{ date }}</h1>
        <p class="print-meta">In lúc: {{ printedAt }}</p>

        <table v-for="floor in props.floors" :key="floor.id" class="print-table">
            <thead>
                <tr>
                    <th colspan="11" class="print-floor-header">Tầng {{ floor.name || floor.code }}</th>
                </tr>
                <tr>
                    <th class="col-room">Phòng</th>
                    <th class="col-type">Loại</th>
                    <th class="col-status">Trạng thái</th>
                    <th class="col-guest">Khách</th>
                    <th class="col-time">Nhận phòng</th>
                    <th class="col-time">Trả phòng</th>
                    <th class="col-narrow">Ghép giường</th>
                    <th class="col-narrow">Giường phụ</th>
                    <th class="col-narrow">Kiểm đồ</th>
                    <th class="col-note">Ghi chú nhanh</th>
                    <th class="col-check">Đã dọn</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="room in floor.rooms" :key="room.id">
                    <td class="col-room">{{ room.room_number }}</td>
                    <td class="col-type">{{ room.room_type_name || room.room_type }}</td>
                    <td class="col-status">{{ roomStatusText(room) }}</td>
                    <td class="col-guest">{{ room.occupant?.customer_name || '—' }}</td>
                    <td class="col-time">{{ checkinCellText(room.occupant) }}</td>
                    <td class="col-time">{{ checkoutCellText(room.occupant) }}</td>
                    <td class="col-narrow">{{ room.occupant?.bed_join ? 'Có' : '—' }}</td>
                    <td class="col-narrow">{{ room.occupant?.extra_bed_quantity > 0 ? `x${room.occupant.extra_bed_quantity}` : '—' }}</td>
                    <td class="col-narrow">{{ room.occupant?.inspection_status === 'completed' ? 'Đã kiểm' : '—' }}</td>
                    <td class="col-note">{{ room.occupant?.quick_note || '' }}</td>
                    <td class="col-check">☐</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
/* User request (2026-08-22 chat) — table-based, not the colored tile grid:
   legible in B&W, leaves room for a handwritten tick per row. Font sizes
   are print-pt (pt is meaningful for @page-sized output, unlike screen px). */
.print-page {
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
}

.print-title {
    font-size: 14pt;
    font-weight: 700;
    margin: 0 0 2mm 0;
}

.print-meta {
    font-size: 8pt;
    color: #444;
    margin: 0 0 4mm 0;
}

.print-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6mm;
    font-size: 8.5pt;
}

/* Repeats the floor + column header on every printed page the table spans
   (standard print CSS — @page break lands mid-table for a busy floor). */
.print-table thead {
    display: table-header-group;
}

.print-table tr {
    break-inside: avoid;
}

.print-floor-header {
    text-align: left;
    background: #e5e5e5;
    font-size: 10pt;
    padding: 1.5mm 2mm;
    border: 0.3mm solid #000;
}

.print-table th,
.print-table td {
    border: 0.3mm solid #000;
    padding: 1mm 1.5mm;
    text-align: left;
    vertical-align: top;
}

.print-table th {
    font-weight: 700;
    background: #f2f2f2;
}

.col-room { width: 8%; font-weight: 700; }
.col-type { width: 8%; }
.col-status { width: 13%; }
.col-guest { width: 14%; }
.col-time { width: 12%; }
.col-narrow { width: 7%; text-align: center; }
.col-note { width: 14%; }
.col-check { width: 5%; text-align: center; font-size: 11pt; }
</style>
