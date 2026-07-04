<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { Download } from 'lucide-vue-next'
import { computed, reactive } from 'vue'

interface VoidRow {
    id: number
    booking_code: string
    customer_name: string
    charge_type: string
    charge_label: string
    description: string
    amount: number
    entry_date: string
    voided_at: string | null
    voided_by: string
    void_reason: string
}

const props = defineProps<{
    voids: VoidRow[]
    filters: { from: string; to: string }
    businessDate: string
}>()

const filter = reactive({
    from: props.filters.from,
    to: props.filters.to,
})

const applyFilters = () => {
    router.get(route('admin.reconciliation.voids'), filter, { preserveState: true, replace: true })
}

const resetFilters = () => {
    const today = props.businessDate
    const firstOfMonth = today.substring(0, 7) + '-01'
    filter.from = firstOfMonth
    filter.to = today
    applyFilters()
}

// Derived from props.filters (committed server state), not filter (pending client state),
// so the CSV always matches the visible table even if the user has edited the filter without applying.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: props.filters.from, to: props.filters.to })
    return `/admin/reconciliation/voids/export?${params}`
})

const totalVoided = computed(() => props.voids.reduce((s, r) => s + r.amount, 0))

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`

const formatDate = (dateStr: string) => {
    const [y, m, d] = dateStr.split('-')
    return `${d}/${m}/${y}`
}

const periodLabel = computed(() => {
    if (props.filters.from === props.filters.to) return formatDate(props.filters.from)
    return `${formatDate(props.filters.from)} – ${formatDate(props.filters.to)}`
})
</script>

<template>
    <AppLayout>
        <Head title="Phí đã hủy" />

        <div class="mx-auto max-w-6xl">
            <!-- Page header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Phí đã hủy</h1>
                    <p class="mt-1 text-sm text-steel">
                        Ngày kinh doanh hiện tại:
                        <span class="font-mono font-medium text-ink">{{ formatDate(businessDate) }}</span>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        :href="route('admin.reconciliation.index')"
                        class="border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-steel transition hover:border-ink hover:text-ink"
                    >
                        ← Tồn nợ
                    </Link>
                    <a
                        :href="exportUrl"
                        class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-steel transition hover:border-pine hover:text-pine"
                    >
                        <Download class="h-4 w-4" />
                        Xuất CSV
                    </a>
                </div>
            </div>

            <!-- Date range filter -->
            <div class="mb-6 rounded border border-gray-200 bg-white px-5 py-4">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label
                            for="voids-from"
                            class="mb-1 block text-xs font-semibold uppercase tracking-wide text-steel"
                        >
                            Từ ngày (ngày hủy)
                        </label>
                        <input
                            id="voids-from"
                            v-model="filter.from"
                            type="date"
                            class="w-40 border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                        />
                    </div>

                    <div>
                        <label
                            for="voids-to"
                            class="mb-1 block text-xs font-semibold uppercase tracking-wide text-steel"
                        >
                            Đến ngày
                        </label>
                        <input
                            id="voids-to"
                            v-model="filter.to"
                            type="date"
                            class="w-40 border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                        />
                    </div>

                    <div class="flex gap-2">
                        <button
                            @click="applyFilters"
                            class="bg-pine px-4 py-2 text-sm font-semibold text-white transition hover:bg-ink"
                        >
                            Áp dụng
                        </button>
                        <button
                            @click="resetFilters"
                            class="border border-gray-300 px-4 py-2 text-sm text-steel transition hover:text-ink"
                        >
                            Đặt lại
                        </button>
                    </div>
                </div>
            </div>

            <!-- Summary card -->
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div class="rounded border border-gray-200 bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-steel">Tổng phí đã hủy</div>
                    <div class="mt-2 text-xl font-bold text-ink">{{ formatCurrency(totalVoided) }}</div>
                    <div class="mt-1 text-xs text-gray-400">{{ periodLabel }}</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-steel">Số dòng hủy</div>
                    <div class="mt-2 text-xl font-bold text-ink">{{ voids.length }}</div>
                    <div class="mt-1 text-xs text-gray-400">{{ periodLabel }}</div>
                </div>
            </div>

            <!-- Voided Entries Table -->
            <div class="rounded border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-700">Chi tiết phí đã hủy</h2>
                    <p class="text-xs text-steel">{{ periodLabel }}</p>
                </div>

                <div v-if="voids.length === 0" class="px-5 py-8 text-center text-sm text-gray-400">
                    Không có phí nào bị hủy trong khoảng thời gian này.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th class="px-4 py-3">Mã đặt phòng</th>
                                <th class="px-4 py-3">Khách hàng</th>
                                <th class="px-4 py-3">Loại phí</th>
                                <th class="px-4 py-3">Mô tả</th>
                                <th class="px-4 py-3 text-right">Thành tiền</th>
                                <th class="px-4 py-3">Ngày phát sinh</th>
                                <th class="px-4 py-3">Thời gian hủy</th>
                                <th class="px-4 py-3">Người hủy</th>
                                <th class="px-4 py-3">Lý do hủy</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in voids"
                                :key="row.id"
                                class="border-t border-gray-100 hover:bg-gray-50"
                            >
                                <td class="px-4 py-3 font-mono text-sm text-pine">
                                    {{ row.booking_code }}
                                </td>
                                <td class="px-4 py-3 text-gray-800">{{ row.customer_name }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-block rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">
                                        {{ row.charge_label }}
                                    </span>
                                </td>
                                <td class="max-w-xs px-4 py-3 text-gray-600">
                                    <span class="block truncate" :title="row.description">{{ row.description }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-semibold text-gray-700">
                                    {{ formatCurrency(row.amount) }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-500">
                                    {{ formatDate(row.entry_date) }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-500">
                                    {{ row.voided_at ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ row.voided_by }}</td>
                                <td class="max-w-xs px-4 py-3 text-gray-500">
                                    <span class="block truncate" :title="row.void_reason">{{ row.void_reason }}</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50">
                            <tr>
                                <td colspan="4" class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-steel">
                                    Tổng
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-bold text-pine">
                                    {{ formatCurrency(totalVoided) }}
                                </td>
                                <td colspan="4" class="px-4 py-3" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
