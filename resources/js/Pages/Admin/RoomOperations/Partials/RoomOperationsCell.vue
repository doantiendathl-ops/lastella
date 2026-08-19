<script setup>
import RoomTile from '@/Components/RoomBoard/RoomTile.vue';
import { formatDateShort } from '@/Support/format';
import { AlertTriangle, CheckCircle2, MessageSquare } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({
    room: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    canEditNote: { type: Boolean, default: false },
    // User request (2026-08-18 chat) — ADMIN-only, same as RoomBoardPanel.vue
    // on the booking-detail page: lets the actual check-in/check-out time be
    // corrected without leaving the board.
    canAdjustActualTime: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'view-booking', 'save-note', 'select-booking-rooms', 'edit-check-in', 'edit-check-out', 'view-other-services']);

const occupant = computed(() => props.room.occupant);

const editingNote = computed(() => noteDraft.value !== null);
const noteDraft = ref(null);
const noteError = ref('');

function startEditNote() {
    if (!props.canEditNote) return;
    noteDraft.value = occupant.value?.quick_note ?? '';
    noteError.value = '';
}

function cancelEditNote() {
    noteDraft.value = null;
    noteError.value = '';
}

function saveNote() {
    if (noteDraft.value !== null && noteDraft.value.length > 100) {
        noteError.value = 'Tối đa 100 ký tự.';
        return;
    }
    emit('save-note', { assignmentId: occupant.value.assignment_id, note: noteDraft.value || null });
    noteDraft.value = null;
}

// docs/yeucaumoi.txt mục 7 — when the room belongs to a Booking at the
// selected date/time, the Booking's own color is the Room Tile's PRIMARY
// background — RoomTile.vue (shared shell) owns the actual styling; this
// component only resolves WHICH color/vacant-class applies.
const VACANT_THEME = {
    unavailable: 'border-gray-500 bg-gray-300',
    vacant_clean: 'border-green-500 bg-green-200',
    vacant_dirty: 'border-amber-500 bg-amber-200',
};
const bookingColor = computed(() => props.room.occupant?.booking_color ?? null);
const vacantClass = computed(() => VACANT_THEME[props.room.status_theme] ?? 'border-gray-200 bg-white');

// User request (2026-08-19 chat) — full tile content redesign: every status
// that used to be an icon-only badge (housekeeping, bed join, extra bed,
// checkout inspection) is now a short Vietnamese text label instead, and the
// separate "checked-in/checked-out" door icon is REMOVED — that fact is now
// carried entirely by the actual-time date line below (bold + underline).
const housekeepingText = computed(() => (props.room.is_clean ? 'Sạch' : 'Bẩn'));
const housekeepingClass = computed(() => (props.room.is_clean ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'));

const bedJoinTooltip = computed(() => (occupant.value?.bed_join ? `Ghép giường — ${occupant.value.bed_join.status_label}` : ''));

// User request (2026-08-20 chat) — compact "N chờ xác nhận · N chờ thực hiện
// · N đã hoàn thành" summary for every OTHER active Dịch vụ & Yêu cầu
// enrollment (bed join/extra bed are excluded server-side — they already
// have their own badge above). Zero-count buckets are omitted.
const OTHER_SERVICE_STATUS_TEXT = {
    CREATED: 'chờ xác nhận',
    CONFIRMED: 'chờ thực hiện',
    COMPLETED: 'đã hoàn thành',
};
const otherServicesSummary = computed(() => {
    const items = occupant.value?.other_services ?? [];
    if (items.length === 0) return '';

    const counts = { CREATED: 0, CONFIRMED: 0, COMPLETED: 0 };
    items.forEach((item) => {
        if (counts[item.status] !== undefined) counts[item.status] += 1;
    });

    return Object.entries(counts)
        .filter(([, count]) => count > 0)
        .map(([status, count]) => `${count} ${OTHER_SERVICE_STATUS_TEXT[status]}`)
        .join(' · ');
});

// Room-Conflict Detection follow-up: two (or more) live assignments hold the
// same physical room with genuinely overlapping date ranges — a real
// double-booking, not the benign same-day handoff case. Deliberately its own
// highest-priority, unmissable marker (red ring + banner) separate from the
// 5-state status tint and the icon row, since this represents a data
// conflict on the room itself, not a fact about the currently-displayed
// occupant.
const conflictLabel = computed(() => {
    if (!props.room.has_room_conflict) return '';
    const codes = (props.room.conflicting_bookings ?? []).map((b) => b.booking_code).filter(Boolean).join(', ');
    return `Trùng phòng — ${codes || 'booking khác'}`;
});
const conflictTooltip = computed(() => {
    if (!props.room.has_room_conflict) return '';
    const codes = (props.room.conflicting_bookings ?? []).map((b) => b.booking_code).filter(Boolean).join(', ');
    return codes ? `Trùng phòng với: ${codes}` : 'Phòng đang bị trùng với booking khác.';
});

// User request (2026-08-19 chat) — the planned in/out date line now shows
// the ACTUAL time (bold + underline), in place of the planned value, the
// moment each half is recorded — independently per side, since a booking
// can be checked in on one side and still only planned on the other.
// Editing (ADMIN-only, ClaudeCode 2026-08-18 feature) moves from a separate
// pencil icon to clicking the date text itself.
const checkinDateText = computed(() => (occupant.value?.is_checked_in
    ? formatDateShort(occupant.value.actual_checkin_at)
    : formatDateShort(occupant.value?.start_at)));
const checkoutDateText = computed(() => (occupant.value?.is_checked_out
    ? formatDateShort(occupant.value.actual_checkout_at)
    : formatDateShort(occupant.value?.end_at)));

const canEditCheckin = computed(() => props.canAdjustActualTime && occupant.value?.is_checked_in);
const canEditCheckout = computed(() => props.canAdjustActualTime && occupant.value?.is_checked_out);

const checkinDateClass = computed(() => ({
    'underline decoration-2 underline-offset-2': occupant.value?.is_checked_in,
    'cursor-pointer hover:opacity-70': canEditCheckin.value,
}));
const checkoutDateClass = computed(() => ({
    'underline decoration-2 underline-offset-2': occupant.value?.is_checked_out,
    'cursor-pointer hover:opacity-70': canEditCheckout.value,
}));

const checkinDateTitle = computed(() => {
    if (canEditCheckin.value) return 'Bấm để sửa giờ nhận phòng thực tế';
    return occupant.value?.is_checked_in ? 'Giờ nhận phòng thực tế' : 'Giờ nhận phòng dự kiến';
});
const checkoutDateTitle = computed(() => {
    if (canEditCheckout.value) return 'Bấm để sửa giờ trả phòng thực tế';
    return occupant.value?.is_checked_out ? 'Giờ trả phòng thực tế' : 'Giờ trả phòng dự kiến';
});

function handleCheckinDateClick() {
    if (canEditCheckin.value) emit('edit-check-in', props.room);
}
function handleCheckoutDateClick() {
    if (canEditCheckout.value) emit('edit-check-out', props.room);
}
</script>

<template>
    <RoomTile
        :room-number="room.room_number"
        :room-type-label="room.room_type"
        :booking-color="bookingColor"
        :vacant-class="vacantClass"
        :selected="selected"
        :conflict="room.has_room_conflict"
        :conflict-label="conflictLabel"
    >
        <template #conflict-icon>
            <AlertTriangle :title="conflictTooltip" :aria-label="conflictTooltip" class="h-3 w-3 shrink-0" />
        </template>

        <template #checkbox>
            <input
                type="checkbox"
                class="h-3.5 w-3.5 shrink-0 rounded border-gray-300"
                :checked="selected"
                @change="emit('toggle-select', room.id)"
            />
        </template>

        <!-- User request (2026-08-19 chat) — "select every room of this
             booking" button moved out of the body row, next to the room's
             own selection checkbox in the header. -->
        <template #header-extra>
            <button
                v-if="occupant"
                type="button"
                title="Chọn tất cả phòng của booking này"
                aria-label="Chọn tất cả phòng của booking này"
                class="shrink-0 rounded border border-current/40 p-0.5 opacity-80 hover:opacity-100"
                @click="emit('select-booking-rooms', occupant.booking_id)"
            >
                <CheckCircle2 class="h-3 w-3" />
            </button>
        </template>

        <template #body>
            <div v-if="occupant" class="space-y-1">
                <button
                    type="button"
                    class="block text-left font-bold leading-snug underline decoration-dotted underline-offset-2 hover:decoration-solid"
                    style="overflow-wrap: anywhere;"
                    @click="emit('view-booking', occupant.booking_id)"
                >
                    {{ occupant.customer_name }}
                </button>
                <!-- User request (2026-08-19 chat) — bold, more legible than the
                     old text-[10px] opacity-80 hint; each side independently
                     swaps from planned to actual (bold + underline) the moment
                     that half is recorded, and becomes clickable to correct
                     (ADMIN-only) once it is. -->
                <div class="text-xs">
                    <span
                        class="font-bold"
                        :class="checkinDateClass"
                        :title="checkinDateTitle"
                        @click="handleCheckinDateClick"
                    >{{ checkinDateText }}</span>
                    <span class="opacity-70"> → </span>
                    <span
                        class="font-bold"
                        :class="checkoutDateClass"
                        :title="checkoutDateTitle"
                        @click="handleCheckoutDateClick"
                    >{{ checkoutDateText }}</span>
                </div>
            </div>
            <div v-else class="text-[11px] italic text-gray-500">Phòng trống</div>
        </template>

        <template #status-row>
            <!--
                User request (2026-08-19 chat) — every status here is now a
                short Vietnamese text label (was icon-only): housekeeping
                (Sạch/Bẩn), Ghép giường, Giường phụ x{n}, Đã kiểm out
                (checkout inspection — ONLY the completed state is shown, by
                design, per the user's own list). flex-wrap (not flex-nowrap)
                because text labels need more width than icons did.
            -->
            <div class="flex flex-wrap items-center gap-1 rounded bg-white/75 px-1 py-1 text-gray-900">
                <span class="rounded px-1 py-0.5 text-[10px] font-semibold" :class="housekeepingClass">{{ housekeepingText }}</span>

                <span
                    v-if="occupant?.bed_join"
                    :title="bedJoinTooltip"
                    class="rounded bg-orange-100 px-1 py-0.5 text-[10px] font-semibold text-orange-700"
                >Ghép giường</span>

                <span
                    v-if="occupant?.extra_bed_quantity > 0"
                    class="rounded bg-cyan-100 px-1 py-0.5 text-[10px] font-semibold text-cyan-700"
                >Giường phụ x{{ occupant.extra_bed_quantity }}</span>

                <span
                    v-if="occupant?.inspection_status === 'completed'"
                    class="rounded bg-emerald-100 px-1 py-0.5 text-[10px] font-semibold text-emerald-700"
                >Đã kiểm out</span>
            </div>
        </template>

        <template #footer>
            <!-- User request (2026-08-20 chat) — every OTHER active Dịch vụ &
                 Yêu cầu enrollment (bed join/extra bed already have their own
                 badge above), summarized ABOVE the note box; click opens a
                 popup list + link to the booking's Dịch vụ & Yêu cầu page. -->
            <button
                v-if="otherServicesSummary"
                type="button"
                class="mb-1 block w-full rounded bg-white/75 px-1 py-0.5 text-left text-[10px] font-medium text-indigo-700 hover:bg-white"
                @click="emit('view-other-services', room)"
            >
                {{ occupant.other_services.length }} yêu cầu &amp; dịch vụ khác: {{ otherServicesSummary }}
            </button>

            <!-- Mục XVIII/XXVII: quick note is shown in FULL — no truncate/line-clamp/max-height,
                 wraps across as many lines as needed. -->
            <div class="pt-1">
                <div v-if="!editingNote" class="flex items-start gap-1 rounded bg-white/75 px-1 py-1">
                    <MessageSquare class="mt-0.5 h-3 w-3 shrink-0 text-gray-500" />
                    <button
                        v-if="canEditNote && occupant"
                        type="button"
                        class="flex-1 text-left text-[10px] leading-snug text-gray-700 hover:text-indigo-600"
                        style="white-space: normal; overflow-wrap: anywhere;"
                        @click="startEditNote"
                    >
                        {{ occupant.quick_note || 'Thêm ghi chú nhanh…' }}
                    </button>
                    <span
                        v-else
                        class="flex-1 text-[10px] leading-snug text-gray-600"
                        style="white-space: normal; overflow-wrap: anywhere;"
                    >
                        {{ occupant?.quick_note || '—' }}
                    </span>
                </div>
                <div v-else class="space-y-1">
                    <textarea
                        v-model="noteDraft"
                        rows="3"
                        maxlength="100"
                        class="w-full rounded border border-gray-300 bg-white p-1 text-[10px]"
                        placeholder="Ghi chú nhanh (tối đa 100 ký tự)"
                    />
                    <div class="flex items-center justify-between text-[10px]">
                        <span :class="noteError ? 'text-red-600' : 'text-gray-500'">{{ noteError || `${noteDraft?.length ?? 0}/100` }}</span>
                        <div class="flex gap-1">
                            <button type="button" class="text-gray-600 hover:underline" @click="cancelEditNote">Hủy</button>
                            <button type="button" class="font-medium text-indigo-600 hover:underline" @click="saveNote">Lưu</button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="room.pending_special_requests > 0" class="rounded bg-white/75 px-1 py-0.5 text-[10px] font-medium text-red-700">
                {{ room.pending_special_requests }} yêu cầu đặc biệt chờ xử lý
            </div>
        </template>
    </RoomTile>
</template>
