<script setup>
import RoomTile from '@/Components/RoomBoard/RoomTile.vue';
import { formatDateShort } from '@/Support/format';
import {
    AlertTriangle,
    BedDouble,
    BedSingle,
    Brush,
    CheckCircle2,
    ClipboardCheck,
    ClipboardX,
    DoorClosed,
    DoorOpen,
    LogOut,
    MessageSquare,
    Pencil,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({
    room: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    canEditNote: { type: Boolean, default: false },
    // User request (2026-08-18 chat) — ADMIN-only, same as RoomBoardPanel.vue
    // on the booking-detail page: shows a pencil to correct an already-
    // recorded actual check-in/check-out time.
    canAdjustActualTime: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-select', 'view-booking', 'save-note', 'select-booking-rooms', 'edit-check-in', 'edit-check-out']);

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

// Mục XV-XXVI: one compact icon-only status row, five groups, each with an
// exact tooltip/aria-label — canonical sources unchanged (only the display
// changed from text badges to icons):
//   1. Housekeeping  → Room::isRoomClean() (physical room state)
//   2. Occupancy     → Stay.actual_checkin_at/actual_checkout_at (3-state)
//   3. Bed join      → BookingSpecialRequest (twin_to_double), room-scoped
//   4. Extra bed     → room_assignments.extra_bed_quantity, room-scoped
//   5. Inspection    → Stay::inspectionStatus()
const housekeepingIcon = computed(() => (props.room.is_clean ? CheckCircle2 : Brush));
const housekeepingTooltip = computed(() => (props.room.is_clean ? 'Đã dọn' : 'Chưa dọn'));
const housekeepingClass = computed(() => (props.room.is_clean ? 'text-green-600' : 'text-amber-600'));

const occupancyIcon = computed(() => {
    if (!occupant.value) return null;
    if (occupant.value.is_checked_out) return LogOut;
    if (occupant.value.is_checked_in) return DoorOpen;
    return DoorClosed;
});
const occupancyTooltip = computed(() => {
    if (!occupant.value) return '';
    if (occupant.value.is_checked_out) return 'Đã trả phòng';
    if (occupant.value.is_checked_in) return 'Đã nhận phòng';
    return 'Chưa nhận phòng';
});
// docs/yeucaumoi.txt mục 8 — DoorClosed/DoorOpen/LogOut no longer own the
// full Room Tile background; they're one icon inside the status-strip row
// (see the icon row's bg-white/75 backing below) instead, so the Booking
// color underneath stays the dominant, visible background. Same 3
// operational states, same colors, new scope only.
const occupancyClass = computed(() => {
    if (!occupant.value) return '';
    if (occupant.value.is_checked_out) return 'text-gray-700';
    if (occupant.value.is_checked_in) return 'text-blue-700';
    return 'text-purple-700';
});

const bedJoinTooltip = computed(() => (occupant.value?.bed_join ? `Ghép giường — ${occupant.value.bed_join.status_label}` : ''));

const inspectionIcon = computed(() => (occupant.value?.inspection_status === 'completed' ? ClipboardCheck : ClipboardX));
const inspectionLabel = {
    completed: 'Đã kiểm đồ',
    draft: 'Đang kiểm đồ (nháp)',
    skipped: 'Bỏ qua kiểm đồ',
    none: 'Chưa kiểm đồ',
};
const inspectionTooltip = computed(() => inspectionLabel[occupant.value?.inspection_status] ?? '');
const inspectionClass = computed(() => (occupant.value?.inspection_status === 'completed' ? 'text-emerald-600' : 'text-amber-600'));

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
                class="h-3.5 w-3.5 rounded border-gray-300"
                :checked="selected"
                @change="emit('toggle-select', room.id)"
            />
        </template>

        <template #body>
            <div v-if="occupant" class="space-y-1">
                <div class="flex items-start justify-between gap-1">
                    <button
                        type="button"
                        class="block text-left font-medium leading-snug underline decoration-dotted underline-offset-2 hover:decoration-solid"
                        style="overflow-wrap: anywhere;"
                        @click="emit('view-booking', occupant.booking_id)"
                    >
                        {{ occupant.customer_name }}
                    </button>
                    <!-- docs/yeucaumoi.txt mục 12 — select every room this Booking
                         currently occupies, without clicking each one. -->
                    <button
                        type="button"
                        title="Chọn tất cả phòng của booking này"
                        aria-label="Chọn tất cả phòng của booking này"
                        class="shrink-0 rounded border border-current/40 p-0.5 opacity-80 hover:opacity-100"
                        @click="emit('select-booking-rooms', occupant.booking_id)"
                    >
                        <CheckCircle2 class="h-3 w-3" />
                    </button>
                </div>
                <div class="text-[10px] opacity-80">
                    {{ formatDateShort(occupant.start_at) }} → {{ formatDateShort(occupant.end_at) }}
                </div>
            </div>
            <div v-else class="text-[11px] italic text-gray-500">Phòng trống</div>
        </template>

        <template #status-row>
            <!--
                Mục XVI-XXVI: one compact icon-only row, five status groups, never wraps.
                docs/yeucaumoi.txt mục 8 — this row IS the "status strip": a light backing
                band that keeps every operational icon legible regardless of the Booking
                color behind the tile, without letting any single state (DoorClosed/
                DoorOpen/LogOut included) take over the tile's own background.
            -->
            <div class="flex flex-nowrap items-center gap-2 rounded bg-white/75 px-1 py-1 text-gray-900">
                <span :title="housekeepingTooltip" :aria-label="housekeepingTooltip" class="shrink-0">
                    <component :is="housekeepingIcon" class="h-4 w-4" :class="housekeepingClass" />
                </span>

                <span v-if="occupant" :title="occupancyTooltip" :aria-label="occupancyTooltip" class="shrink-0">
                    <component :is="occupancyIcon" class="h-4 w-4" :class="occupancyClass" />
                </span>

                <!-- User request (2026-08-18 chat) — ADMIN-only: correct an
                     already-recorded actual time without leaving the board. -->
                <button
                    v-if="canAdjustActualTime && occupant?.is_checked_in"
                    type="button"
                    title="Sửa thời gian nhận phòng thực tế"
                    aria-label="Sửa thời gian nhận phòng thực tế"
                    class="shrink-0 text-gray-500 hover:text-indigo-600"
                    @click="emit('edit-check-in', room)"
                >
                    <Pencil class="h-3 w-3" />
                </button>
                <button
                    v-if="canAdjustActualTime && occupant?.is_checked_out"
                    type="button"
                    title="Sửa thời gian trả phòng thực tế"
                    aria-label="Sửa thời gian trả phòng thực tế"
                    class="shrink-0 text-gray-500 hover:text-indigo-600"
                    @click="emit('edit-check-out', room)"
                >
                    <Pencil class="h-3 w-3" />
                </button>

                <span v-if="occupant?.bed_join" :title="bedJoinTooltip" :aria-label="bedJoinTooltip" class="shrink-0">
                    <BedDouble class="h-4 w-4 text-orange-600" />
                </span>

                <span
                    v-if="occupant?.extra_bed_quantity > 0"
                    :title="`Giường phụ x${occupant.extra_bed_quantity}`"
                    :aria-label="`Giường phụ x${occupant.extra_bed_quantity}`"
                    class="shrink-0"
                >
                    <BedSingle class="h-4 w-4 text-cyan-600" />
                </span>

                <span v-if="occupant" :title="inspectionTooltip" :aria-label="inspectionTooltip" class="shrink-0">
                    <component :is="inspectionIcon" class="h-4 w-4" :class="inspectionClass" />
                </span>
            </div>
        </template>

        <template #footer>
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
