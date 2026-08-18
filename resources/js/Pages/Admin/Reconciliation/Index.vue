<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatDate } from '@/Support/format'
import { Head, Link, router } from '@inertiajs/vue3'
import { Download } from 'lucide-vue-next'
import { computed, reactive } from 'vue'

interface OutstandingRow {
    booking_id: number
    booking_code: string
    customer_name: string
    status: string
    folio_status: string | null
    total_charges: number
    paid_total: number
    balance_due: number
    checkout_at: string | null
}

interface DiscrepancyRow {
    type: string
    booking_id: number
    booking_code: string
    customer_name: string
    booking_status: string
    folio_status: string | null
    balance_due: number
}

const props = defineProps<{
    outstanding: OutstandingRow[]
    discrepancies: DiscrepancyRow[]
    filters: { status: string | null }
    businessDate: string
}>()

const BOOKING_STATUS_OPTIONS = [
    { value: '', label: 'Tất cả trạng thái' },
    { value: 'DRAFT', label: 'Nháp' },
    { value: 'PENDING_ASSIGNMENT', label: 'Chờ phân phòng' },
    { value: 'PARTIALLY_ASSIGNED', label: 'Phân một phần' },
    { value: 'FULLY_ASSIGNED', label: 'Đã phân đủ' },
    { value: 'HELD', label: 'Giữ chỗ' },
    { value: 'DEPOSITED', label: 'Đã đặt cọc' },
    { value: 'PARTIALLY_CHECKED_IN', label: 'Nhận phòng một phần' },
    { value: 'CHECKED_IN', label: 'Đang lưu trú' },
    { value: 'PARTIALLY_CHECKED_OUT', label: 'Trả phòng một phần' },
    { value: 'CHECKED_OUT', label: 'Đã trả phòng' },
    { value: 'CANCELLED', label: 'Đã hủy' },
    { value: 'NO_SHOW', label: 'Không đến' },
]

const STATUS_LABELS: Record<string, string> = Object.fromEntries(
    BOOKING_STATUS_OPTIONS.filter((o) => o.value).map((o) => [o.value, o.label]),
)

const DISCREPANCY_LABELS: Record<string, string> = {
    CHECKED_OUT_OUTSTANDING_BALANCE: 'Đã trả phòng còn nợ',
    CLOSED_FOLIO_NON_ZERO_BALANCE: 'Folio đóng còn số dư',
    NON_ZERO_BALANCE: 'Số dư bất thường',
}

const FOLIO_STATUS_LABELS: Record<string, string> = {
    OPEN: 'Đang mở',
    CLOSED: 'Đã đóng',
    VOIDED: 'Đã hủy',
}

const filter = reactive({ status: props.filters.status ?? '' })

const applyFilters = () => {
    router.get(
        route('admin.reconciliation.index'),
        { status: filter.status || undefined },
        { preserveState: true, replace: true },
    )
}

const resetFilters = () => {
    filter.status = ''
    applyFilters()
}

// Derived from props.filters (committed server state), not filter (pending client state),
// so the CSV always matches the visible table even if the user has edited the filter without applying.
const exportUrl = computed(() => {
    const params = new URLSearchParams()
    if (props.filters.status) params.set('status', props.filters.status)
    const qs = params.toString()
    return `/admin/reconciliation/export${qs ? '?' + qs : ''}`
})

const totalOutstanding = computed(() => props.outstanding.reduce((s, r) => s + r.balance_due, 0))

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`

// docs/Prompt_2.txt mục VI — "checkout date" column; checkout_at is a
// "YYYY-MM-DD HH:MM:SS" string (Stay.actual_checkout_at), null when the
// booking hasn't actually checked out yet (e.g. a mid-stay discrepancy row).
const formatCheckoutAt = (value: string | null) => (value ? formatDate(value) : '—')

const discrepancyBadgeClass = (type: string) => {
    if (type === 'CHECKED_OUT_OUTSTANDING_BALANCE') return 'bg-coral/10 text-coral'
    if (type === 'CLOSED_FOLIO_NON_ZERO_BALANCE') return 'bg-amber/10 text-amber-700'
    return 'bg-gray-100 text-gray-600'
}

const statusBadgeClass = (status: string) => {
    if (status === 'CHECKED_OUT') return 'bg-gray-100 text-gray-600'
    if (status === 'CHECKED_IN') return 'bg-blue-100 text-blue-700'
    if (status === 'CANCELLED') return 'bg-coral/10 text-coral'
    return 'bg-gray-100 text-gray-600'
}
</script>

<template>
    <AppLayout>
        <Head title="Đối soát tài chính" />

        <div class="mx-auto max-w-6xl">
            <!-- Page header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Đối soát tài chính</h1>
                    <p class="mt-1 text-sm text-steel">
                        Ngày kinh doanh hiện tại:
                        <span class="font-mono font-medium text-ink">{{ formatDate(businessDate) }}</span>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        :href="route('admin.reconciliation.voids')"
                        class="border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-steel transition hover:border-ink hover:text-ink"
                    >
                        Phí đã hủy →
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

            <!-- Status filter -->
            <div class="mb-6 rounded border border-gray-200 bg-white px-5 py-4">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label
                            for="status-filter"
                            class="mb-1 block text-xs font-semibold uppercase tracking-wide text-steel"
                        >
                            Trạng thái đặt phòng
                        </label>
                        <select
                            id="status-filter"
                            v-model="filter.status"
                            class="w-56 border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                        >
                            <option
                                v-for="opt in BOOKING_STATUS_OPTIONS"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </option>
                        </select>
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
                <div class="rounded border border-pine bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-steel">Tổng tồn nợ</div>
                    <div class="mt-2 text-xl font-bold text-coral">{{ formatCurrency(totalOutstanding) }}</div>
                    <div class="mt-1 text-xs text-gray-400">{{ outstanding.length }} đặt phòng</div>
                </div>
                <div class="rounded border border-gray-200 bg-white px-5 py-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-steel">Bất thường</div>
                    <div class="mt-2 text-xl font-bold" :class="discrepancies.length > 0 ? 'text-coral' : 'text-pine'">
                        {{ discrepancies.length }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">trường hợp cần xem xét</div>
                </div>
            </div>

            <!-- Outstanding Balances Table -->
            <div class="mb-8 rounded border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-700">Tồn nợ</h2>
                    <p class="text-xs text-steel">Đặt phòng có số dư chưa thanh toán</p>
                </div>

                <div v-if="outstanding.length === 0" class="px-5 py-8 text-center text-sm text-gray-400">
                    Không có khoản tồn nợ nào.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th class="px-5 py-3">Mã đặt phòng</th>
                                <th class="px-5 py-3">Khách hàng</th>
                                <th class="px-5 py-3">Trạng thái</th>
                                <th class="px-5 py-3">Folio</th>
                                <th class="px-5 py-3">Ngày trả phòng</th>
                                <th class="px-5 py-3 text-right">Tổng phí</th>
                                <th class="px-5 py-3 text-right">Đã TT</th>
                                <th class="px-5 py-3 text-right">Còn nợ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in outstanding"
                                :key="row.booking_id"
                                class="border-t border-gray-100 hover:bg-gray-50"
                            >
                                <td class="px-5 py-3">
                                    <Link
                                        :href="route('admin.bookings.show', row.booking_id)"
                                        class="font-mono text-pine hover:underline"
                                    >
                                        {{ row.booking_code }}
                                    </Link>
                                </td>
                                <td class="px-5 py-3 text-gray-800">{{ row.customer_name }}</td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="statusBadgeClass(row.status)"
                                    >
                                        {{ STATUS_LABELS[row.status] ?? row.status }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-xs text-gray-500">
                                    {{ row.folio_status ? (FOLIO_STATUS_LABELS[row.folio_status] ?? row.folio_status) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-xs text-gray-500">
                                    {{ formatCheckoutAt(row.checkout_at) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-gray-700">
                                    {{ formatCurrency(row.total_charges) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-gray-500">
                                    {{ formatCurrency(row.paid_total) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-coral">
                                    {{ formatCurrency(row.balance_due) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50">
                            <tr>
                                <td colspan="7" class="px-5 py-3 text-xs font-semibold uppercase tracking-wide text-steel">
                                    Tổng tồn nợ
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-bold text-coral">
                                    {{ formatCurrency(totalOutstanding) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Discrepancies Table -->
            <div class="rounded border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-700">Bất thường tài chính</h2>
                    <p class="text-xs text-steel">Folio đóng hoặc đã trả phòng còn số dư bất hợp lệ</p>
                </div>

                <div v-if="discrepancies.length === 0" class="px-5 py-8 text-center text-sm text-gray-400">
                    Không phát hiện bất thường.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th class="px-5 py-3">Loại bất thường</th>
                                <th class="px-5 py-3">Mã đặt phòng</th>
                                <th class="px-5 py-3">Khách hàng</th>
                                <th class="px-5 py-3">Trạng thái</th>
                                <th class="px-5 py-3">Folio</th>
                                <th class="px-5 py-3 text-right">Số dư</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in discrepancies"
                                :key="`${row.booking_id}-${row.type}`"
                                class="border-t border-gray-100 hover:bg-gray-50"
                            >
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="discrepancyBadgeClass(row.type)"
                                    >
                                        {{ DISCREPANCY_LABELS[row.type] ?? row.type }}
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <Link
                                        :href="route('admin.bookings.show', row.booking_id)"
                                        class="font-mono text-pine hover:underline"
                                    >
                                        {{ row.booking_code }}
                                    </Link>
                                </td>
                                <td class="px-5 py-3 text-gray-800">{{ row.customer_name }}</td>
                                <td class="px-5 py-3 text-xs text-gray-500">
                                    {{ STATUS_LABELS[row.booking_status] ?? row.booking_status }}
                                </td>
                                <td class="px-5 py-3 text-xs text-gray-500">
                                    {{ row.folio_status ? (FOLIO_STATUS_LABELS[row.folio_status] ?? row.folio_status) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-coral">
                                    {{ formatCurrency(row.balance_due) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
