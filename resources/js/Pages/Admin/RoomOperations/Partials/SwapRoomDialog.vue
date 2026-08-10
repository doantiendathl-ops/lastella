<script setup>
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { AlertTriangle, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps({
    sourceRooms: { type: Array, default: () => [] }, // [{ id, room_number, occupant }]
    allRooms: { type: Array, default: () => [] }, // flat list for target picker
});

const emit = defineEmits(['close', 'done']);

// pairs: { source_assignment_id, target_room_id, source_room_number }
const pairs = ref(
    props.sourceRooms.map((room) => ({
        source_assignment_id: room.occupant.assignment_id,
        source_room_number: room.room_number,
        target_room_id: null,
    })),
);

const targetOptions = computed(() => props.allRooms
    .filter((r) => !props.sourceRooms.some((s) => s.id === r.id))
    .sort((a, b) => a.room_number.localeCompare(b.room_number)));

const previewResult = ref(null);
const previewing = ref(false);
const previewError = ref('');
const acknowledged = ref(false);
const submitting = ref(false);

const readyToPreview = computed(() => pairs.value.every((p) => p.target_room_id !== null));

async function runPreview() {
    if (!readyToPreview.value) return;
    previewing.value = true;
    previewError.value = '';
    acknowledged.value = false;

    try {
        const { data } = await axios.post(route('admin.room-operations.swap.preview'), {
            pairs: pairs.value.map((p) => ({ source_assignment_id: p.source_assignment_id, target_room_id: p.target_room_id })),
        });
        previewResult.value = data;
    } catch (error) {
        previewError.value = error.response?.data?.message ?? 'Không thể xem trước — vui lòng thử lại.';
    } finally {
        previewing.value = false;
    }
}

const canConfirm = computed(() => {
    if (!previewResult.value || previewResult.value.has_blockers) return false;
    return !previewResult.value.has_warnings || acknowledged.value;
});

function confirmSwap() {
    if (!canConfirm.value || submitting.value) return;
    submitting.value = true;

    router.post(
        route('admin.room-operations.swap.execute'),
        {
            pairs: pairs.value.map((p) => ({ source_assignment_id: p.source_assignment_id, target_room_id: p.target_room_id })),
            warnings_acknowledged: acknowledged.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
            },
            onSuccess: (page) => {
                const errors = page.props.errors ?? {};
                if (Object.keys(errors).length > 0) {
                    previewError.value = Object.values(errors).flat().join(' ');
                    return;
                }
                emit('done');
            },
        },
    );
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Đổi phòng</h2>
                <button type="button" class="text-gray-400 hover:text-gray-600" @click="emit('close')">
                    <X class="h-5 w-5" />
                </button>
            </div>

            <div class="space-y-4 p-4">
                <div v-for="pair in pairs" :key="pair.source_assignment_id" class="flex items-center gap-2 text-sm">
                    <span class="w-24 shrink-0 font-medium text-gray-700">Phòng {{ pair.source_room_number }}</span>
                    <span class="text-gray-400">→</span>
                    <select v-model="pair.target_room_id" class="flex-1 rounded border border-gray-300 p-1.5 text-sm">
                        <option :value="null" disabled>-- Chọn phòng đích --</option>
                        <option v-for="opt in targetOptions" :key="opt.id" :value="opt.id">
                            {{ opt.room_number }} ({{ opt.room_type }}){{ opt.occupant ? ' — đang có booking khác' : '' }}
                        </option>
                    </select>
                </div>

                <button
                    type="button"
                    class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!readyToPreview || previewing"
                    @click="runPreview"
                >
                    {{ previewing ? 'Đang xem trước…' : 'Xem trước' }}
                </button>

                <p v-if="previewError" class="text-sm text-red-600">{{ previewError }}</p>

                <div v-if="previewResult" class="space-y-3 rounded border border-gray-200 p-3">
                    <div v-for="(pairResult, idx) in previewResult.pairs" :key="idx" class="space-y-1 text-xs">
                        <p class="font-medium text-gray-800">
                            Phòng {{ pairResult.source?.room_number }} ({{ pairResult.source?.booking_code }}) → Phòng {{ pairResult.target?.room_number }}
                            <span v-if="pairResult.is_move" class="text-gray-500">— dời sang phòng trống</span>
                        </p>
                        <ul v-if="pairResult.displaced?.length" class="ml-4 list-disc text-gray-600">
                            <li v-for="d in pairResult.displaced" :key="d.assignment_id">
                                Booking {{ d.booking_code }} ({{ d.customer_name }}) chuyển sang phòng {{ pairResult.source?.room_number }} trong khoảng
                                {{ d.moved_start_at }} → {{ d.moved_end_at }}
                            </li>
                        </ul>
                        <p v-for="(w, i) in pairResult.warnings" :key="`w${i}`" class="flex items-start gap-1 text-amber-700">
                            <AlertTriangle class="mt-0.5 h-3 w-3 shrink-0" /> {{ w }}
                        </p>
                        <p v-for="(b, i) in pairResult.blockers" :key="`b${i}`" class="font-medium text-red-600">✗ {{ b }}</p>
                    </div>

                    <label v-if="previewResult.has_warnings && !previewResult.has_blockers" class="flex items-center gap-2 text-xs">
                        <input v-model="acknowledged" type="checkbox" class="rounded border-gray-300" />
                        Tôi đã xác nhận các cảnh báo ở trên và muốn tiếp tục.
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-gray-200 px-4 py-3">
                <button type="button" class="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700" @click="emit('close')">Hủy</button>
                <button
                    type="button"
                    class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!canConfirm || submitting"
                    @click="confirmSwap"
                >
                    {{ submitting ? 'Đang xử lý…' : 'Xác nhận đổi phòng' }}
                </button>
            </div>
        </div>
    </div>
</template>
