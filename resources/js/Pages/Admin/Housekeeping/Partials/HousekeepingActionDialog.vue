<script setup>
import { cleaningPriorityLabels, cleaningReasonLabels } from '@/Support/vietnameseLabels';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    action: { type: String, required: true }, // assign | start | complete | pass | fail | skip | outOfOrder | release
    room: { type: Object, required: true },
});

const emit = defineEmits(['close', 'success']);

const ACTION_CONFIG = {
    assign: {
        title: (room) => `Phân công dọn phòng ${room.room_number}`,
        method: 'post',
        route: (room) => route('admin.housekeeping.assign', room.id),
        confirmLabel: 'Phân công',
    },
    start: {
        title: (room) => `Bắt đầu dọn phòng ${room.room_number}?`,
        method: 'patch',
        route: (room) => route('admin.housekeeping.start', room.active_assignment.id),
        confirmLabel: 'Bắt đầu',
    },
    complete: {
        title: (room) => `Hoàn thành dọn phòng ${room.room_number}`,
        method: 'patch',
        route: (room) => route('admin.housekeeping.complete', room.active_assignment.id),
        confirmLabel: 'Hoàn thành',
    },
    pass: {
        title: (room) => `Phòng ${room.room_number} đạt kiểm tra`,
        method: 'patch',
        route: (room) => route('admin.housekeeping.inspect.pass', room.id),
        confirmLabel: 'Xác nhận đạt',
    },
    fail: {
        title: (room) => `Phòng ${room.room_number} không đạt kiểm tra`,
        method: 'patch',
        route: (room) => route('admin.housekeeping.inspect.fail', room.id),
        confirmLabel: 'Xác nhận không đạt',
    },
    skip: {
        title: (room) => `Bỏ qua kiểm tra phòng ${room.room_number}`,
        method: 'patch',
        route: (room) => route('admin.housekeeping.inspect.skip', room.id),
        confirmLabel: 'Bỏ qua kiểm tra',
    },
    outOfOrder: {
        title: (room) => `Khóa bảo trì phòng ${room.room_number}`,
        method: 'patch',
        route: (room) => route('admin.housekeeping.out-of-order', room.id),
        confirmLabel: 'Khóa bảo trì',
    },
    release: {
        title: (room) => `Mở khóa bảo trì phòng ${room.room_number}`,
        method: 'patch',
        route: (room) => route('admin.housekeeping.release', room.id),
        confirmLabel: 'Mở khóa',
    },
};

const config = computed(() => ACTION_CONFIG[props.action]);

const form = reactive({
    assigned_to: '',
    priority: 'NORMAL',
    reason: 'CHECKOUT',
    notes: '',
    target_status: 'VACANT_DIRTY',
});

const processing = ref(false);
const errors = ref({});

function payloadFor(action) {
    switch (action) {
        case 'assign':
            return {
                assigned_to: form.assigned_to === '' ? null : Number(form.assigned_to),
                priority: form.priority,
                reason: form.reason,
                notes: form.notes || null,
            };
        case 'complete':
        case 'pass':
        case 'fail':
        case 'skip':
            return { notes: form.notes || null };
        case 'outOfOrder':
            return { reason: form.notes };
        case 'release':
            return { target_status: form.target_status };
        case 'start':
        default:
            return {};
    }
}

function submit() {
    processing.value = true;
    errors.value = {};

    const { method, route: buildRoute } = config.value;

    axios[method](buildRoute(props.room), payloadFor(props.action))
        .then(() => {
            emit('success');
            emit('close');
        })
        .catch((error) => {
            errors.value = error.response?.data?.errors ?? { _general: [error.response?.data?.message ?? 'Có lỗi xảy ra.'] };
        })
        .finally(() => {
            processing.value = false;
        });
}

const firstError = computed(() => Object.values(errors.value)[0]?.[0]);
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="emit('close')">
        <div class="w-full max-w-md border border-gray-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <span class="text-sm font-semibold text-ink">{{ config.title(room) }}</span>
                <button type="button" class="text-steel hover:text-ink" @click="emit('close')">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form class="space-y-3 p-4" @submit.prevent="submit">
                <template v-if="action === 'assign'">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Mã người dùng nhân viên (để trống để tự nhận)</label>
                        <input v-model="form.assigned_to" type="number" min="1" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Mức ưu tiên</label>
                        <select v-model="form.priority" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="(label, value) in cleaningPriorityLabels" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Lý do dọn phòng</label>
                        <select v-model="form.reason" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="(label, value) in cleaningReasonLabels" :key="value" :value="value">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ghi chú (tùy chọn)</label>
                        <textarea v-model="form.notes" rows="2" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                </template>

                <template v-else-if="['complete', 'pass', 'fail'].includes(action)">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ghi chú (tùy chọn)</label>
                        <textarea v-model="form.notes" rows="2" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                </template>

                <template v-else-if="action === 'skip'">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Lý do bỏ qua kiểm tra (bắt buộc)</label>
                        <textarea v-model="form.notes" rows="2" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                </template>

                <template v-else-if="action === 'outOfOrder'">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Lý do khóa bảo trì (bắt buộc)</label>
                        <textarea v-model="form.notes" rows="2" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                </template>

                <template v-else-if="action === 'release'">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Trạng thái sau khi mở khóa</label>
                        <select v-model="form.target_status" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option value="VACANT_DIRTY">Trống bẩn</option>
                            <option value="VACANT_CLEAN">Trống sạch</option>
                        </select>
                    </div>
                </template>

                <template v-else-if="action === 'start'">
                    <p class="text-sm text-steel">Xác nhận bắt đầu dọn phòng {{ room.room_number }}.</p>
                </template>

                <p v-if="firstError" class="text-sm text-coral">{{ firstError }}</p>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="border border-gray-300 px-3 py-1.5 text-xs font-semibold text-steel hover:text-ink" @click="emit('close')">Hủy</button>
                    <button
                        type="submit"
                        class="border border-pine bg-pine px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                        :disabled="processing"
                    >
                        {{ config.confirmLabel }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
