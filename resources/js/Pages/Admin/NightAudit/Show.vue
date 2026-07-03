<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, RefreshCw } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface Run {
    id: number
    business_date: string
    status: string
    stays_processed: number
    entries_posted: number
    entries_skipped: number
    run_by_name: string | null
    started_at: string | null
    completed_at: string | null
    error_message: string | null
    can_retry: boolean
}

interface Summary {
    posted: number
    already_posted: number
    skipped: number
    failed: number
    total: number
}

interface BookingLog {
    id: number
    booking_id: number
    booking_ref: string
    stay_id: number | null
    room_number: string | null
    job_class: string
    result: string
    posting_key: string | null
    message: string | null
}

const props = defineProps<{
    run: Run
    summary: Summary
    logs: BookingLog[]
}>()

const statusBadge = (status: string) => ({
    COMPLETED: 'bg-green-100 text-green-800',
    FAILED:    'bg-red-100 text-red-800',
    RUNNING:   'bg-yellow-100 text-yellow-800',
    PENDING:   'bg-gray-100 text-gray-600',
}[status] ?? 'bg-gray-100 text-gray-600')

const statusLabel = (status: string) => ({
    COMPLETED: 'Hoàn tất',
    FAILED:    'Thất bại',
    RUNNING:   'Đang chạy',
    PENDING:   'Chờ',
}[status] ?? status)

const resultBadge = (result: string) => ({
    POSTED:         'bg-green-100 text-green-800',
    ALREADY_POSTED: 'bg-blue-100 text-blue-800',
    SKIPPED:        'bg-gray-100 text-gray-600',
    FAILED:         'bg-red-100 text-red-800',
}[result] ?? 'bg-gray-100 text-gray-600')

const resultLabel = (result: string) => ({
    POSTED:         'Đã ghi',
    ALREADY_POSTED: 'Đã ghi trước',
    SKIPPED:        'Bỏ qua',
    FAILED:         'Lỗi',
}[result] ?? result)

const filterOptions = [
    { label: 'Tất cả', value: null },
    { label: 'Đã ghi', value: 'POSTED' },
    { label: 'Đã ghi trước', value: 'ALREADY_POSTED' },
    { label: 'Bỏ qua', value: 'SKIPPED' },
    { label: 'Lỗi', value: 'FAILED' },
]

const activeFilter = ref<string | null>(null)

const filteredLogs = computed(() =>
    activeFilter.value ? props.logs.filter(l => l.result === activeFilter.value) : props.logs
)

const retryForm = useForm({})
const retryRun = () => {
    retryForm.post(route('admin.night-audit.retry', props.run.id))
}
</script>

<template>
    <AppLayout>
        <Head :title="`Night Audit — ${run.business_date}`" />

        <div class="mx-auto max-w-5xl">
            <!-- Back -->
            <Link
                :href="route('admin.night-audit.index')"
                class="mb-6 inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800"
            >
                <ArrowLeft :size="14" />
                Quay lại danh sách
            </Link>

            <!-- Page header -->
            <div class="mb-6 flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Night Audit — <span class="font-mono">{{ run.business_date }}</span>
                    </h1>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                        <span
                            class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="statusBadge(run.status)"
                        >
                            {{ statusLabel(run.status) }}
                        </span>
                        <span v-if="run.run_by_name">Người chạy: <strong class="text-gray-700">{{ run.run_by_name }}</strong></span>
                        <span v-if="run.started_at">Bắt đầu: {{ run.started_at }}</span>
                        <span v-if="run.completed_at">Hoàn tất: {{ run.completed_at }}</span>
                    </div>
                </div>

                <button
                    v-if="run.can_retry"
                    @click="retryRun"
                    :disabled="retryForm.processing"
                    class="flex items-center gap-2 rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60"
                >
                    <RefreshCw :size="14" />
                    {{ retryForm.processing ? 'Đang thử lại...' : 'Thử lại' }}
                </button>
            </div>

            <!-- Retry error -->
            <div
                v-if="retryForm.errors.run"
                class="mb-4 border-l-4 border-red-400 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                {{ retryForm.errors.run }}
            </div>

            <!-- Run-level error message -->
            <div
                v-if="run.error_message"
                class="mb-4 border-l-4 border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                <strong>Lỗi hệ thống:</strong> {{ run.error_message }}
            </div>

            <!-- Summary cards -->
            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div class="rounded border border-gray-200 bg-white px-4 py-4 text-center">
                    <div class="text-2xl font-bold text-green-700">{{ summary.posted }}</div>
                    <div class="mt-1 text-xs text-gray-500">Đã ghi</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-4 py-4 text-center">
                    <div class="text-2xl font-bold text-blue-600">{{ summary.already_posted }}</div>
                    <div class="mt-1 text-xs text-gray-500">Đã ghi trước</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-4 py-4 text-center">
                    <div class="text-2xl font-bold text-gray-400">{{ summary.skipped }}</div>
                    <div class="mt-1 text-xs text-gray-500">Bỏ qua</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-4 py-4 text-center">
                    <div class="text-2xl font-bold text-red-600">{{ summary.failed }}</div>
                    <div class="mt-1 text-xs text-gray-500">Lỗi</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-4 py-4 text-center">
                    <div class="text-2xl font-bold text-gray-700">{{ summary.total }}</div>
                    <div class="mt-1 text-xs text-gray-500">Tổng cộng</div>
                </div>
            </div>

            <!-- Run stats -->
            <div class="mb-6 grid grid-cols-3 gap-3">
                <div class="rounded border border-gray-200 bg-white px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Lượt lưu trú</div>
                    <div class="mt-1 text-xl font-bold text-gray-800">{{ run.stays_processed }}</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Ghi thành công</div>
                    <div class="mt-1 text-xl font-bold text-green-700">{{ run.entries_posted }}</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Bỏ qua</div>
                    <div class="mt-1 text-xl font-bold text-gray-400">{{ run.entries_skipped }}</div>
                </div>
            </div>

            <!-- Filter tabs -->
            <div class="mb-3 flex flex-wrap gap-2">
                <button
                    v-for="opt in filterOptions"
                    :key="String(opt.value)"
                    @click="activeFilter = opt.value"
                    class="rounded px-3 py-1 text-xs font-medium transition"
                    :class="activeFilter === opt.value
                        ? 'bg-gray-800 text-white'
                        : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50'"
                >
                    {{ opt.label }}
                </button>
            </div>

            <!-- Booking log table -->
            <div class="overflow-x-auto rounded border border-gray-200">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">Đặt phòng</th>
                            <th class="px-4 py-3">Phòng</th>
                            <th class="px-4 py-3">Loại phí</th>
                            <th class="px-4 py-3">Kết quả</th>
                            <th class="px-4 py-3">Ghi chú</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="log in filteredLogs"
                            :key="log.id"
                            class="border-t border-gray-100"
                        >
                            <td class="px-4 py-3 font-mono text-gray-800">{{ log.booking_ref }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ log.room_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ log.job_class }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="resultBadge(log.result)"
                                >
                                    {{ resultLabel(log.result) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ log.message ?? '—' }}</td>
                        </tr>
                        <tr v-if="filteredLogs.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                                Không có bản ghi nào.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
