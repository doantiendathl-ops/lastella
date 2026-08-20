<script setup>
import { ArrowLeftRight, Brush, CheckCircle2, ClipboardCheck, DoorOpen, LogOut, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps({
    selectedRooms: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const emit = defineEmits(['swap', 'check-in', 'check-out', 'inspect', 'confirm-no-charge', 'clean', 'clear']);

function eligibleCount(actionKey) {
    return props.selectedRooms.filter((r) => r.actions?.[actionKey]).length;
}

const swapCount = computed(() => eligibleCount('can_swap'));
const checkInCount = computed(() => eligibleCount('can_check_in'));
const checkOutCount = computed(() => eligibleCount('can_check_out'));
const inspectCount = computed(() => eligibleCount('can_inspect'));
const cleanCount = computed(() => props.selectedRooms.filter((r) => r.actions?.can_clean).length);
</script>

<template>
    <div
        v-if="selectedRooms.length > 0"
        class="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded-t-lg border border-gray-200 bg-white p-3 shadow-lg"
    >
        <span class="text-sm font-medium text-gray-700">Đã chọn: {{ selectedRooms.length }} phòng</span>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded border border-indigo-300 bg-indigo-50 px-2.5 py-1.5 text-xs font-medium text-indigo-700 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="swapCount === 0 || !can.swap"
            :title="swapCount === 0 ? 'Không có phòng nào hợp lệ để đổi (phòng đã nhận không thể đổi từ đây).' : ''"
            @click="emit('swap')"
        >
            <ArrowLeftRight class="h-3.5 w-3.5" /> Đổi phòng ({{ swapCount }})
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded border border-blue-300 bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="checkInCount === 0 || !can.checkIn"
            :title="checkInCount === 0 ? 'Không có phòng nào đang chờ nhận phòng.' : ''"
            @click="emit('check-in')"
        >
            <DoorOpen class="h-3.5 w-3.5" /> Nhận phòng ({{ checkInCount }})
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded border border-purple-300 bg-purple-50 px-2.5 py-1.5 text-xs font-medium text-purple-700 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="checkOutCount === 0 || !can.checkOut"
            :title="checkOutCount === 0 ? 'Không có phòng nào đang ở để trả phòng.' : ''"
            @click="emit('check-out')"
        >
            <LogOut class="h-3.5 w-3.5" /> Trả phòng ({{ checkOutCount }})
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded border border-emerald-300 bg-emerald-50 px-2.5 py-1.5 text-xs font-medium text-emerald-700 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="inspectCount === 0 || !can.inspect"
            :title="inspectCount === 0 ? 'Không có phòng nào cần kiểm đồ.' : ''"
            @click="emit('inspect')"
        >
            <ClipboardCheck class="h-3.5 w-3.5" /> Kiểm đồ ({{ inspectCount }})
        </button>

        <!-- User request (2026-08-20 chat) — "ghi một lượt cho nhiều phòng chỉ
             với 1 kết quả 'xác nhận không phát sinh'": same eligible set as
             "Kiểm đồ" (can_inspect), one click records "không phát sinh" for
             every selected room's stay in one batch instead of opening the
             popup once per room. -->
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded border border-lime-300 bg-lime-50 px-2.5 py-1.5 text-xs font-medium text-lime-700 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="inspectCount === 0 || !can.inspect"
            :title="inspectCount === 0 ? 'Không có phòng nào cần kiểm đồ.' : 'Ghi \'Xác nhận không phát sinh\' cho tất cả phòng đã chọn, không cần mở từng phiếu.'"
            @click="emit('confirm-no-charge')"
        >
            <CheckCircle2 class="h-3.5 w-3.5" /> Kiểm đồ nhanh: Xác nhận tất cả không phát sinh ({{ inspectCount }})
        </button>

        <button
            type="button"
            class="inline-flex items-center gap-1 rounded border border-teal-300 bg-teal-50 px-2.5 py-1.5 text-xs font-medium text-teal-700 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="cleanCount === 0 || !can.clean"
            @click="emit('clean')"
        >
            <Brush class="h-3.5 w-3.5" /> Dọn phòng ({{ cleanCount }})
        </button>

        <button
            type="button"
            class="ml-auto inline-flex items-center gap-1 rounded border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
            @click="emit('clear')"
        >
            <X class="h-3.5 w-3.5" /> Bỏ chọn
        </button>
    </div>
</template>
