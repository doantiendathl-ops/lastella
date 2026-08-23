<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatDate } from '@/Support/format'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

interface AuditRun {
    id: number
    business_date: string
    status: string
    stays_processed: number
    entries_posted: number
    entries_skipped: number
    run_by_name: string | null
    started_at: string | null
    completed_at: string | null
    confirmed_at: string | null
    is_awaiting_confirmation: boolean
}

const props = defineProps<{
    runs: AuditRun[]
    businessDate: string
}>()

const page = usePage()
const canTrigger = computed(() =>
    (page.props.auth as any)?.user?.permissions?.includes('night_audit.run') ?? false
)

const showTriggerForm = ref(false)
const triggerForm = useForm({ date: props.businessDate })

const submitTrigger = () => {
    triggerForm.post(route('admin.night-audit.trigger'), {
        preserveScroll: true,
        onSuccess: () => {
            showTriggerForm.value = false
            triggerForm.reset()
        },
    })
}

const statusClass = (status: string) => ({
    COMPLETED: 'text-green-600',
    FAILED:    'text-red-600',
    RUNNING:   'text-yellow-600',
    PENDING:   'text-gray-400',
}[status] ?? 'text-gray-500')

const statusLabel = (status: string) => ({
    COMPLETED: 'Hoàn tất',
    FAILED:    'Thất bại',
    RUNNING:   'Đang chạy',
    PENDING:   'Chờ',
}[status] ?? status)
</script>

<template>
    <AppLayout>
        <Head title="Night Audit" />

        <div class="mx-auto max-w-5xl">
            <!-- Page header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Night Audit</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        Ngày kế toán hiện tại: <strong>{{ businessDate }}</strong>
                    </p>
                </div>
                <button
                    v-if="canTrigger"
                    @click="showTriggerForm = !showTriggerForm"
                    class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                >
                    {{ showTriggerForm ? 'Đóng' : 'Kích hoạt Night Audit' }}
                </button>
            </div>

            <!-- Trigger form -->
            <div v-if="showTriggerForm && canTrigger" class="mb-6 rounded border border-gray-200 bg-gray-50 p-5">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">Kích hoạt Night Audit thủ công</h2>
                <form @submit.prevent="submitTrigger" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Ngày kế toán *</label>
                        <input
                            v-model="triggerForm.date"
                            type="date"
                            required
                            class="mt-1 border border-gray-300 px-3 py-2 text-sm"
                        />
                        <p v-if="triggerForm.errors.date" class="mt-1 text-xs text-red-600">
                            {{ triggerForm.errors.date }}
                        </p>
                    </div>
                    <div class="flex gap-2 pb-0.5">
                        <button
                            type="submit"
                            :disabled="triggerForm.processing"
                            class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                        >
                            {{ triggerForm.processing ? 'Đang chạy...' : 'Kích hoạt' }}
                        </button>
                        <button
                            type="button"
                            @click="showTriggerForm = false"
                            class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                        >
                            Hủy
                        </button>
                    </div>
                </form>
            </div>

            <!-- Run history table -->
            <div class="overflow-x-auto rounded border border-gray-200">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">Ngày KT</th>
                            <th class="px-4 py-3">Trạng thái</th>
                            <th class="px-4 py-3 text-right">Phòng</th>
                            <th class="px-4 py-3 text-right">Đã ghi</th>
                            <th class="px-4 py-3 text-right">Bỏ qua</th>
                            <th class="px-4 py-3">Người chạy</th>
                            <th class="px-4 py-3">Hoàn tất</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="run in runs"
                            :key="run.id"
                            class="border-t border-gray-100"
                        >
                            <td class="px-4 py-3 font-mono">{{ formatDate(run.business_date) }}</td>
                            <td class="px-4 py-3">
                                <span :class="statusClass(run.status)" class="text-xs font-semibold">
                                    {{ statusLabel(run.status) }}
                                </span>
                                <span
                                    v-if="run.is_awaiting_confirmation"
                                    class="ml-1.5 inline-block rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700"
                                    title="Vẫn có thể Tính lại — cửa sổ chỉnh sửa chưa đóng"
                                >
                                    Chờ xác nhận
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ run.stays_processed }}</td>
                            <td class="px-4 py-3 text-right text-green-700">{{ run.entries_posted }}</td>
                            <td class="px-4 py-3 text-right text-gray-400">{{ run.entries_skipped }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ run.run_by_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ run.completed_at ? formatDate(run.completed_at) : '—' }}</td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="route('admin.night-audit.show', run.id)"
                                    class="text-xs text-blue-600 hover:underline"
                                >
                                    Chi tiết
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="runs.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400">
                                Chưa có lần chạy nào.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
