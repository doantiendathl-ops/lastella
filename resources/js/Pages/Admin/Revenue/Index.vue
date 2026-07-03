<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { Download } from 'lucide-vue-next'
import { computed, reactive } from 'vue'

interface ChargeTypeRow {
    charge_type: string
    label: string
    amount: number
    count: number
}

interface DateRow {
    date: string
    total: number
    count: number
}

interface SourceRow {
    source: string
    label: string
    amount: number
    count: number
}

interface DailyData {
    date: string
    total: number
    by_charge_type: ChargeTypeRow[]
}

interface PeriodData {
    from: string
    to: string
    total: number
    by_charge_type: ChargeTypeRow[]
    by_date: DateRow[]
}

interface BySourceData {
    from: string
    to: string
    total: number
    by_source: SourceRow[]
}

const props = defineProps<{
    daily: DailyData
    period: PeriodData
    bySource: BySourceData
    filters: { from: string; to: string }
    businessDate: string
}>()

const filter = reactive({
    from: props.filters.from,
    to: props.filters.to,
})

const applyFilters = () => {
    router.get(route('admin.reports.revenue.index'), filter, { preserveState: true, replace: true })
}

const resetFilters = () => {
    const today = props.businessDate
    const firstOfMonth = today.substring(0, 7) + '-01'
    filter.from = firstOfMonth
    filter.to = today
    applyFilters()
}

const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: filter.from, to: filter.to })
    return `/admin/reports/revenue/export?${params}`
})

const isMultiDay = computed(() => props.period.by_date.length > 1)

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

const maxAmount = computed(() => {
    const amounts = props.period.by_charge_type.map((r) => r.amount)
    return amounts.length > 0 ? Math.max(...amounts) : 1
})

const barWidth = (amount: number) =>
    maxAmount.value > 0 ? Math.round((amount / maxAmount.value) * 100) : 0
</script>

<template>
    <AppLayout>
        <Head title="Báo cáo doanh thu" />

        <div class="mx-auto max-w-5xl">
            <!-- Page header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Báo cáo doanh thu</h1>
                    <p class="mt-1 text-sm text-steel">
                        Ngày kinh doanh hiện tại:
                        <span class="font-mono font-medium text-ink">{{ formatDate(businessDate) }}</span>
                    </p>
                </div>

                <a
                    :href="exportUrl"
                    class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-steel transition hover:border-pine hover:text-pine"
                >
                    <Download class="h-4 w-4" />
                    Xuất CSV
                </a>
            </div>

            <!-- Date range filter -->
            <div class="mb-6 rounded border border-gray-200 bg-white px-5 py-4">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-steel">
                            Từ ngày
                        </label>
                        <input
                            v-model="filter.from"
                            type="date"
                            class="w-40 border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-steel">
                            Đến ngày
                        </label>
                        <input
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

            <!-- Summary cards -->
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
                <!-- Today -->
                <div class="rounded border border-gray-200 bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-steel">Hôm nay</div>
                    <div class="mt-2 text-xl font-bold text-pine">{{ formatCurrency(daily.total) }}</div>
                    <div class="mt-1 text-xs text-gray-400">{{ formatDate(daily.date) }}</div>
                </div>

                <!-- Period total -->
                <div class="rounded border border-pine bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-steel">Kỳ được chọn</div>
                    <div class="mt-2 text-xl font-bold text-ink">{{ formatCurrency(period.total) }}</div>
                    <div class="mt-1 text-xs text-gray-400">{{ periodLabel }}</div>
                </div>

                <!-- Entry count -->
                <div class="rounded border border-gray-200 bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-steel">Số giao dịch</div>
                    <div class="mt-2 text-xl font-bold text-ink">
                        {{ period.by_charge_type.reduce((s, r) => s + r.count, 0) }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">{{ periodLabel }}</div>
                </div>
            </div>

            <!-- Revenue by Charge Type -->
            <div class="mb-6 rounded border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-700">Doanh thu theo loại phí</h2>
                    <p class="text-xs text-steel">{{ periodLabel }}</p>
                </div>

                <div v-if="period.by_charge_type.length === 0" class="px-5 py-8 text-center text-sm text-gray-400">
                    Chưa có doanh thu trong khoảng thời gian này.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th class="px-5 py-3">Loại phí</th>
                                <th class="px-5 py-3 text-right">Giao dịch</th>
                                <th class="px-5 py-3 text-right">Thành tiền</th>
                                <th class="w-1/3 px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in period.by_charge_type"
                                :key="row.charge_type"
                                class="border-t border-gray-100"
                            >
                                <td class="px-5 py-3 font-medium text-gray-800">{{ row.label }}</td>
                                <td class="px-5 py-3 text-right text-gray-500">{{ row.count }}</td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-gray-900">
                                    {{ formatCurrency(row.amount) }}
                                </td>
                                <td class="px-5 py-3">
                                    <div class="h-1.5 w-full rounded-full bg-gray-100">
                                        <div
                                            class="h-1.5 rounded-full bg-pine transition-all"
                                            :style="{ width: barWidth(row.amount) + '%' }"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50">
                            <tr>
                                <td class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-steel">Tổng</td>
                                <td class="px-5 py-3 text-right text-xs font-semibold text-gray-500">
                                    {{ period.by_charge_type.reduce((s, r) => s + r.count, 0) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-bold text-pine">
                                    {{ formatCurrency(period.total) }}
                                </td>
                                <td class="px-5 py-3" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Revenue by Posting Source -->
            <div class="mb-6 rounded border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-700">Doanh thu theo nguồn</h2>
                    <p class="text-xs text-steel">{{ periodLabel }}</p>
                </div>

                <div v-if="bySource.by_source.length === 0" class="px-5 py-8 text-center text-sm text-gray-400">
                    Chưa có doanh thu trong khoảng thời gian này.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th class="px-5 py-3">Nguồn</th>
                                <th class="px-5 py-3 text-right">Giao dịch</th>
                                <th class="px-5 py-3 text-right">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in bySource.by_source"
                                :key="row.source"
                                class="border-t border-gray-100"
                            >
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="{
                                            'bg-blue-100 text-blue-800': row.source === 'NIGHT_AUDIT',
                                            'bg-gray-100 text-gray-700': row.source === 'MANUAL',
                                            'bg-amber-100 text-amber-800': row.source === 'SYSTEM_AUTO',
                                        }"
                                    >
                                        {{ row.label }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right text-gray-500">{{ row.count }}</td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-gray-900">
                                    {{ formatCurrency(row.amount) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50">
                            <tr>
                                <td class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-steel">Tổng</td>
                                <td class="px-5 py-3 text-right text-xs font-semibold text-gray-500">
                                    {{ bySource.by_source.reduce((s, r) => s + r.count, 0) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-bold text-pine">
                                    {{ formatCurrency(bySource.total) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Daily Breakdown (only when range spans multiple days) -->
            <div v-if="isMultiDay" class="rounded border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-700">Chi tiết theo ngày</h2>
                    <p class="text-xs text-steel">{{ periodLabel }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th class="px-5 py-3">Ngày</th>
                                <th class="px-5 py-3 text-right">Giao dịch</th>
                                <th class="px-5 py-3 text-right">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in period.by_date"
                                :key="row.date"
                                class="border-t border-gray-100"
                            >
                                <td class="px-5 py-3 font-mono text-gray-700">{{ formatDate(row.date) }}</td>
                                <td class="px-5 py-3 text-right text-gray-500">{{ row.count }}</td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-gray-900">
                                    {{ formatCurrency(row.total) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50">
                            <tr>
                                <td class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-steel">Tổng</td>
                                <td class="px-5 py-3 text-right text-xs font-semibold text-gray-500">
                                    {{ period.by_date.reduce((s, r) => s + r.count, 0) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-bold text-pine">
                                    {{ formatCurrency(period.total) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
