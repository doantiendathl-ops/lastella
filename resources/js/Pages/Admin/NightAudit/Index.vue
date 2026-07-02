<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, router } from '@inertiajs/vue3'

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
}

defineProps<{
    runs: AuditRun[]
    businessDate: string
}>()

const statusClass = (status: string) => ({
    'COMPLETED': 'text-green-600',
    'FAILED':    'text-red-600',
    'RUNNING':   'text-yellow-600',
    'PENDING':   'text-gray-400',
}[status] ?? 'text-gray-500')

const runAudit = () => {
    router.post(route('admin.night-audit.run'), {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout>
        <Head title="Night Audit" />

        <div class="mx-auto max-w-5xl px-4 py-8">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Night Audit</h1>
                    <p class="mt-1 text-sm text-gray-500">Ngày kế toán hiện tại: <strong>{{ businessDate }}</strong></p>
                </div>
                <button
                    @click="runAudit"
                    class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                >
                    Chạy Night Audit
                </button>
            </div>

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
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="run in runs"
                            :key="run.id"
                            class="border-t border-gray-100"
                        >
                            <td class="px-4 py-3 font-mono">{{ run.business_date }}</td>
                            <td class="px-4 py-3">
                                <span :class="statusClass(run.status)" class="text-xs font-semibold">
                                    {{ run.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">{{ run.stays_processed }}</td>
                            <td class="px-4 py-3 text-right text-green-700">{{ run.entries_posted }}</td>
                            <td class="px-4 py-3 text-right text-gray-400">{{ run.entries_skipped }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ run.run_by_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ run.completed_at ?? '—' }}</td>
                        </tr>
                        <tr v-if="runs.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">Chưa có lần chạy nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
