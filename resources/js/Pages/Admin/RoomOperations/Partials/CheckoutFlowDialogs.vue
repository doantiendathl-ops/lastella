<script setup>
import { AlertTriangle } from 'lucide-vue-next';

const props = defineProps({
    flow: { type: Object, default: null },
    canOverrideInspection: { type: Boolean, default: false },
});

const emit = defineEmits(['cancel', 'skip-inspection', 'update:skip-reason', 'confirm', 'confirm-final', 'open-inspection']);

function roomLabel(rooms) {
    return rooms.length === 1 ? `phòng ${rooms[0].room_number}` : `${rooms.length} phòng đã chọn`;
}

// docs/Prompt_2.txt mục VIII — outstanding balance warning on the final
// checkout dialog. Never implies the debt is forgiven; it stays trackable
// in Đối soát after checkout.
const formatCurrency = (value) => `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`;
const roomsWithBalance = (rooms) => rooms.filter((r) => (r.balance_due ?? 0) > 0);
</script>

<template>
    <!--
        Mục III/IV: checkout inspection warning shown BEFORE the exact-room
        confirmation, whenever any selected room is still uninspected. No
        checkout request is ever sent during this stage.

        User request (2026-08-20 chat) — this is now a REAL gate, not
        advisory: the free "Vẫn tiếp tục" bypass (no permission, no reason,
        no record) is REMOVED. The only way past an uninspected room is
        either completing the inspection, or (checkout_inspection.override
        only) "Bỏ qua và tiếp tục" — which already creates a full audit
        record (Stay.inspection_skipped_at/by/reason + a StayEvent, see
        StayService::skipCheckoutInspection()) before checkout proceeds.
        Anyone without that permission is hard-blocked here: "Đóng" and
        "Kiểm đồ ngay" are the only options.
    -->
    <div v-if="flow?.stage === 'inspection-warning'" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl">
            <h2 class="flex items-center gap-2 text-base font-semibold text-red-600">
                <AlertTriangle class="h-4 w-4" /> Chưa kiểm đồ
            </h2>
            <p class="mt-2 text-sm text-gray-600">
                {{ flow.uninspectedRooms.length === 1 ? `Phòng ${flow.uninspectedRooms[0].room_number} chưa` : `Các phòng sau chưa` }}
                được kiểm đồ khi trả phòng. Vui lòng mở kiểm đồ nhanh để ghi nhận đồ dùng/minibar phát sinh trước khi trả phòng.
            </p>
            <p class="mt-2 text-sm font-semibold text-red-700">
                {{ canOverrideInspection
                    ? 'Chưa kiểm đồ thì không thể trả phòng, trừ khi bỏ qua kiểm đồ có ghi lý do bên dưới.'
                    : 'Chưa kiểm đồ thì không thể trả phòng. Vui lòng kiểm đồ trước, hoặc liên hệ người có quyền bỏ qua kiểm đồ.' }}
            </p>
            <ul v-if="flow.uninspectedRooms.length > 1" class="mt-2 flex flex-wrap gap-1 text-xs">
                <li v-for="r in flow.uninspectedRooms" :key="r.room_id" class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">
                    {{ r.room_number }}
                </li>
            </ul>
            <!-- Mục III/VII: opens the SAME in-page inspection popup — never
                 navigates away from Sơ đồ thao tác. -->
            <button
                type="button"
                class="mt-3 inline-block text-sm font-semibold text-indigo-600 underline"
                @click="emit('open-inspection')"
            >
                Kiểm đồ ngay
            </button>

            <template v-if="canOverrideInspection">
                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Hoặc bỏ qua kiểm đồ (yêu cầu lý do)</p>
                <textarea
                    :value="flow.skipReason"
                    rows="2"
                    placeholder="Lý do bỏ qua kiểm đồ..."
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm"
                    @input="emit('update:skip-reason', $event.target.value)"
                />
            </template>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900" @click="emit('cancel')">
                    Đóng
                </button>
                <button
                    v-if="canOverrideInspection"
                    type="button"
                    class="rounded border border-red-400 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50"
                    :disabled="!flow.skipReason?.trim()"
                    @click="emit('skip-inspection')"
                >
                    Bỏ qua và tiếp tục
                </button>
            </div>
        </div>
    </div>

    <!-- Mục III/IV/V/VI: exact-room checkout confirmation — required for EVERY
         checkout from the board, inspected or not, single or multi-room.
         No request has been sent yet at this point. -->
    <div v-else-if="flow?.stage === 'confirm'" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-5 shadow-xl">
            <h2 class="text-base font-semibold text-gray-900">Xác nhận trả phòng</h2>
            <p class="mt-2 text-sm text-gray-600">
                Bạn có chắc chắn muốn trả {{ roomLabel(flow.rooms) }} không?
            </p>
            <ul v-if="flow.rooms.length > 1" class="mt-2 flex flex-wrap gap-1 text-xs">
                <li v-for="r in flow.rooms" :key="r.room_id" class="rounded bg-gray-100 px-1.5 py-0.5 font-medium text-gray-700">
                    {{ r.room_number }}
                </li>
            </ul>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900" @click="emit('cancel')">
                    Hủy
                </button>
                <button type="button" class="rounded border border-indigo-600 bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" @click="emit('confirm')">
                    Xác nhận trả phòng
                </button>
            </div>
        </div>
    </div>

    <!-- Mirrors the existing ADR-55 final-checkout charge-review dialog wording
         (RoomBoardPanel.vue) — backend-driven: only appears when
         StayService::checkOut() actually threw FinalCheckoutConfirmationRequiredException. -->
    <div v-else-if="flow?.stage === 'final-confirm'" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md rounded-lg border border-gray-200 bg-white p-5 shadow-xl">
            <h2 class="text-base font-semibold text-gray-900">Xác nhận trả phòng cuối cùng</h2>
            <p class="mt-2 text-sm text-gray-600">
                {{ roomLabel(flow.finalRooms) }} đang lưu trú cuối cùng của booking tương ứng.
            </p>
            <p class="mt-3 text-sm text-gray-600">Sau khi trả phòng:</p>
            <ul class="mt-1.5 space-y-1 text-sm text-gray-600">
                <li>• Phí vận hành (minibar, giặt ủi, nhà hàng…) <strong>sẽ không thể thêm hoặc huỷ nữa.</strong></li>
                <li>• Vui lòng đảm bảo tất cả phí phát sinh đã được nhập trước khi tiếp tục.</li>
            </ul>
            <div v-if="roomsWithBalance(flow.finalRooms).length > 0" class="mt-3 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                <p v-for="r in roomsWithBalance(flow.finalRooms)" :key="r.room_id">
                    Phòng {{ r.room_number }}: Booking còn công nợ <strong>{{ formatCurrency(r.balance_due) }}</strong>. Sau khi trả phòng, số tiền này sẽ tiếp tục được theo dõi trong Đối soát.
                </p>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900" @click="emit('cancel')">
                    Quay lại
                </button>
                <button type="button" class="rounded border border-indigo-600 bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" @click="emit('confirm-final')">
                    Xác nhận trả phòng cuối cùng
                </button>
            </div>
        </div>
    </div>
</template>
