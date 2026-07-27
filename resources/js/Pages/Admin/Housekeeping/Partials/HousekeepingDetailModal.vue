<script setup>
import axios from 'axios';
import { X } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const props = defineProps({
    room: { type: Object, required: true },
    legacyActions: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'open-action']);

const loading = ref(true);
const detail = ref(null);
const errorMessage = ref('');

onMounted(async () => {
    try {
        const response = await axios.get(route('admin.housekeeping.detail', props.room.id));
        detail.value = response.data;
    } catch (error) {
        errorMessage.value = error.response ? (error.response.data?.message ?? 'Không thể tải chi tiết phòng.') : 'Mất kết nối, không thể tải chi tiết phòng.';
    } finally {
        loading.value = false;
    }
});

const inspectionResultLabels = {
    pass: 'Đạt',
    fail: 'Không đạt',
    skip: 'Bỏ qua',
};
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="emit('close')">
        <div class="flex max-h-[90vh] w-full max-w-lg flex-col border border-gray-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <span class="text-sm font-semibold text-ink">Chi tiết phòng {{ room.room_number }}</span>
                <button type="button" class="flex min-h-11 min-w-11 items-center justify-center text-steel hover:text-ink" @click="emit('close')">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-4 text-sm">
                <div v-if="loading" class="text-center text-steel">Đang tải...</div>
                <p v-else-if="errorMessage" class="text-coral">{{ errorMessage }}</p>
                <template v-else-if="detail">
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2">
                        <dt class="text-xs uppercase text-steel">Loại phòng</dt>
                        <dd>{{ detail.room.room_type ?? '—' }}</dd>
                        <dt class="text-xs uppercase text-steel">Trạng thái vận hành</dt>
                        <dd>{{ detail.room.operational_status_label }}</dd>
                        <dt class="text-xs uppercase text-steel">Sạch/Bẩn</dt>
                        <dd class="font-semibold" :class="detail.room.cleaning_status === 'DIRTY' ? 'text-amber-700' : 'text-pine'">
                            {{ detail.room.cleaning_status_label }}
                        </dd>
                        <dt class="text-xs uppercase text-steel">Người cập nhật gần nhất</dt>
                        <dd>{{ detail.active_assignment?.assigned_to ?? '—' }}</dd>
                        <dt class="text-xs uppercase text-steel">Thời gian cập nhật</dt>
                        <dd>{{ detail.active_assignment?.updated_at ?? '—' }}</dd>
                    </dl>

                    <div v-if="detail.stay" class="mt-4 border-t border-gray-100 pt-3">
                        <h3 class="mb-2 text-xs font-semibold uppercase text-steel">Lưu trú hiện tại</h3>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-2">
                            <dt class="text-xs uppercase text-steel">Khách</dt>
                            <dd>{{ detail.stay.guest_name ?? '—' }}</dd>
                            <dt class="text-xs uppercase text-steel">Nhận phòng</dt>
                            <dd>{{ detail.stay.checked_in_at ?? '—' }}</dd>
                            <dt class="text-xs uppercase text-steel">Dự kiến trả</dt>
                            <dd>{{ detail.stay.planned_checkout_at ?? '—' }}</dd>
                        </dl>
                        <p v-if="detail.stay.note" class="mt-2 text-xs text-steel">Ghi chú lễ tân: {{ detail.stay.note }}</p>
                        <div v-if="detail.stay.special_requests?.length" class="mt-2">
                            <h4 class="text-xs font-semibold uppercase text-steel">Yêu cầu đặc biệt</h4>
                            <ul class="mt-1 list-disc pl-4 text-xs">
                                <li v-for="(req, i) in detail.stay.special_requests" :key="i">{{ req.note }} ({{ req.status }})</li>
                            </ul>
                        </div>
                    </div>

                    <div v-if="detail.active_assignment?.notes" class="mt-3 border-t border-gray-100 pt-3">
                        <h3 class="mb-1 text-xs font-semibold uppercase text-steel">Ghi chú housekeeping</h3>
                        <p class="text-xs">{{ detail.active_assignment.notes }}</p>
                    </div>

                    <div v-if="detail.recent_cleanings?.length" class="mt-4 border-t border-gray-100 pt-3">
                        <h3 class="mb-2 text-xs font-semibold uppercase text-steel">Lịch sử thay đổi</h3>
                        <ul class="space-y-1 text-xs text-steel">
                            <li v-for="(record, i) in detail.recent_cleanings" :key="i">
                                {{ record.started_at }} — {{ record.cleaned_by ?? '—' }}
                                <span v-if="record.reason_label">· {{ record.reason_label }}</span>
                                <span v-if="record.inspection_result">
                                    · Kiểm tra: {{ inspectionResultLabels[record.inspection_result] ?? record.inspection_result }} ({{ record.inspected_by ?? '—' }})
                                </span>
                                <div v-if="record.cleaning_notes" class="text-[11px] italic text-gray-400">{{ record.cleaning_notes }}</div>
                            </li>
                        </ul>
                    </div>

                    <div v-if="legacyActions.length" class="mt-4 border-t border-gray-100 pt-3">
                        <h3 class="mb-2 text-xs font-semibold uppercase text-steel">Nâng cao</h3>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="action in legacyActions"
                                :key="action.key"
                                type="button"
                                class="min-h-9 border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-steel hover:border-pine hover:text-pine"
                                @click="emit('open-action', action.key)"
                            >
                                {{ action.label }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
