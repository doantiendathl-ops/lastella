<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatDate } from '@/Support/format'
import { Head, Link } from '@inertiajs/vue3'
import { ChevronLeft, Clock, Info } from 'lucide-vue-next'
import { computed } from 'vue'

interface TimelineRow {
    id: number
    entry_date: string
    created_at: string
    charge_type: string | null
    charge_label: string | null
    description: string | null
    quantity: number
    unit_price: number
    amount: number
    posting_source: string
    posting_key: string | null
    stay_id: number | null
    room_number: string | null
    posted_by: string
    is_voided: boolean
    voided_at: string | null
    voided_by: string | null
    void_reason: string | null
    running_total: string
}

const props = defineProps<{
    booking: {
        id: number
        booking_code: string
        customer_name: string
        status: string
    }
    timeline: TimelineRow[]
    includeVoided: boolean
    folio_status: string | null
}>()

const FOLIO_STATUS_LABELS: Record<string, string> = {
    OPEN: 'Đang mở',
    CLOSED: 'Đã đóng',
    VOIDED: 'Đã hủy folio',
}

const BOOKING_STATUS_LABELS: Record<string, string> = {
    DRAFT: 'Nháp',
    PENDING_ASSIGNMENT: 'Chờ phân phòng',
    PARTIALLY_ASSIGNED: 'Phân một phần',
    FULLY_ASSIGNED: 'Đã phân đủ',
    HELD: 'Giữ chỗ',
    DEPOSITED: 'Đã đặt cọc',
    PARTIALLY_CHECKED_IN: 'Nhận phòng một phần',
    CHECKED_IN: 'Đang lưu trú',
    PARTIALLY_CHECKED_OUT: 'Trả phòng một phần',
    CHECKED_OUT: 'Đã trả phòng',
    CANCELLED: 'Đã hủy',
    NO_SHOW: 'Không đến',
}

const SOURCE_LABELS: Record<string, string> = {
    MANUAL: 'Thủ công',
    NIGHT_AUDIT: 'Kiểm toán đêm',
    BREAKFAST_JOB: 'Ăn sáng',
}

const formatCurrency = (value: number | string) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`

const sourceBadgeClass = (source: string) => {
    if (source === 'NIGHT_AUDIT') return 'bg-blue-100 text-blue-700'
    if (source === 'BREAKFAST_JOB') return 'bg-pine/10 text-pine'
    return 'bg-gray-100 text-gray-600'
}

const activeEntries = computed(() => props.timeline.filter((r) => !r.is_voided))
const voidedEntries = computed(() => props.timeline.filter((r) => r.is_voided))
const totalActive = computed(() => activeEntries.value.reduce((s, r) => s + r.amount, 0))
const lastRunningTotal = computed(() => props.timeline[props.timeline.length - 1]?.running_total ?? '0.00')
</script>

<template>
    <AppLayout>
        <Head :title="`Dòng thời gian phí – ${booking.booking_code}`" />

        <div class="mx-auto max-w-7xl">
            <!-- Page header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="mb-1">
                        <Link
                            :href="route('admin.bookings.show', booking.id)"
                            class="inline-flex items-center gap-1 text-sm text-steel hover:text-ink"
                        >
                            <ChevronLeft class="h-4 w-4" aria-hidden="true" />
                            Đặt phòng
                        </Link>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900">Dòng thời gian phí</h1>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-steel">
                        <span class="font-mono font-semibold text-ink">{{ booking.booking_code }}</span>
                        <span>·</span>
                        <span>{{ booking.customer_name }}</span>
                        <span>·</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                            {{ BOOKING_STATUS_LABELS[booking.status] ?? booking.status }}
                        </span>
                        <span v-if="folio_status" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                            Folio: {{ FOLIO_STATUS_LABELS[folio_status] ?? folio_status }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="!includeVoided"
                    class="flex items-center gap-1.5 rounded border border-amber/30 bg-amber/5 px-3 py-2 text-xs font-medium text-amber"
                >
                    <Info class="h-3.5 w-3.5 flex-shrink-0" aria-hidden="true" />
                    Chỉ hiện phí còn hiệu lực
                </div>
            </div>

            <!-- Empty: no folio -->
            <div
                v-if="folio_status === null"
                class="rounded border border-gray-200 bg-white px-5 py-12 text-center text-sm text-gray-400"
            >
                Đặt phòng này chưa có folio.
            </div>

            <!-- Empty: folio exists, no entries -->
            <div
                v-else-if="timeline.length === 0"
                class="rounded border border-gray-200 bg-white px-5 py-12 text-center text-sm text-gray-400"
            >
                Folio chưa có dòng phí nào.
            </div>

            <template v-else>
                <!-- Summary cards -->
                <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="rounded border border-gray-200 bg-white px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-steel">Tổng phí</div>
                        <div class="mt-1.5 text-lg font-bold text-ink">{{ formatCurrency(totalActive) }}</div>
                        <div class="mt-0.5 text-xs text-gray-400">{{ activeEntries.length }} dòng hiệu lực</div>
                    </div>

                    <div class="rounded border border-gray-200 bg-white px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-steel">Dòng phí</div>
                        <div class="mt-1.5 text-lg font-bold text-ink">{{ timeline.length }}</div>
                        <div class="mt-0.5 text-xs text-gray-400">tổng cộng</div>
                    </div>

                    <div v-if="includeVoided" class="rounded border border-gray-200 bg-white px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-steel">Đã hủy</div>
                        <div
                            class="mt-1.5 text-lg font-bold"
                            :class="voidedEntries.length > 0 ? 'text-coral' : 'text-gray-400'"
                        >
                            {{ voidedEntries.length }}
                        </div>
                        <div class="mt-0.5 text-xs text-gray-400">dòng bị hủy</div>
                    </div>

                    <div class="rounded border border-pine bg-white px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-steel">Lũy kế cuối</div>
                        <div class="mt-1.5 text-lg font-bold text-pine">{{ formatCurrency(lastRunningTotal) }}</div>
                        <div class="mt-0.5 text-xs text-gray-400">không tính phí hủy</div>
                    </div>
                </div>

                <!-- Timeline table -->
                <div class="rounded border border-gray-200 bg-white">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <div class="flex items-center gap-2">
                            <Clock class="h-4 w-4 text-steel" aria-hidden="true" />
                            <h2 class="text-sm font-semibold text-gray-700">Chi tiết phí theo thời gian</h2>
                        </div>
                        <p class="mt-0.5 text-xs text-steel">Thứ tự: ngày phát sinh → giờ tạo → ID</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-100 bg-gray-50">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                    <th scope="col" class="px-4 py-3">Ngày</th>
                                    <th scope="col" class="px-4 py-3">Loại phí</th>
                                    <th scope="col" class="px-4 py-3">Mô tả</th>
                                    <th scope="col" class="px-4 py-3">Phòng</th>
                                    <th scope="col" class="px-4 py-3">Nguồn</th>
                                    <th scope="col" class="px-4 py-3 text-right">Thành tiền</th>
                                    <th scope="col" class="px-4 py-3 text-right">Lũy kế</th>
                                    <th scope="col" class="px-4 py-3">Người đăng</th>
                                    <th scope="col" class="px-4 py-3">Trạng thái</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="row in timeline" :key="row.id">
                                    <!-- Main entry row -->
                                    <tr
                                        class="border-t border-gray-100"
                                        :class="row.is_voided ? 'bg-red-50/40 opacity-70' : 'hover:bg-gray-50'"
                                    >
                                        <td class="px-4 py-2.5">
                                            <div class="font-mono text-xs font-medium text-gray-700">
                                                {{ formatDate(row.entry_date) }}
                                            </div>
                                            <div class="mt-0.5 font-mono text-xs text-gray-400">{{ formatDate(row.created_at) }}</div>
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700">
                                                {{ row.charge_label ?? row.charge_type ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="max-w-xs px-4 py-2.5 text-gray-700">
                                            <span class="block truncate" :title="row.description ?? ''">
                                                {{ row.description ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 font-mono text-xs text-gray-500">
                                            {{ row.room_number ?? '—' }}
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <span
                                                class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="sourceBadgeClass(row.posting_source)"
                                            >
                                                {{ SOURCE_LABELS[row.posting_source] ?? row.posting_source }}
                                            </span>
                                        </td>
                                        <td
                                            class="px-4 py-2.5 text-right font-mono font-semibold"
                                            :class="row.is_voided ? 'line-through text-gray-400' : 'text-gray-800'"
                                        >
                                            {{ formatCurrency(row.amount) }}
                                        </td>
                                        <td
                                            class="px-4 py-2.5 text-right font-mono text-xs"
                                            :class="row.is_voided ? 'text-gray-300' : 'font-semibold text-pine'"
                                        >
                                            {{ formatCurrency(row.running_total) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-xs text-gray-500">{{ row.posted_by }}</td>
                                        <td class="px-4 py-2.5">
                                            <span
                                                v-if="row.is_voided"
                                                class="inline-block rounded-full bg-coral/10 px-2 py-0.5 text-xs font-semibold text-coral"
                                            >
                                                HỦY
                                            </span>
                                        </td>
                                    </tr>
                                    <!-- Void detail sub-row -->
                                    <tr v-if="row.is_voided" class="border-t border-red-100 bg-red-50/20">
                                        <td colspan="9" class="px-4 py-1.5">
                                            <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-xs text-coral/80">
                                                <span>
                                                    Hủy lúc:
                                                    <span class="font-mono">{{ row.voided_at ? formatDate(row.voided_at) : '—' }}</span>
                                                </span>
                                                <span>Người hủy: {{ row.voided_by ?? '—' }}</span>
                                                <span v-if="row.void_reason">Lý do: {{ row.void_reason }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="border-t border-gray-200 bg-gray-50">
                                <tr>
                                    <td colspan="5" class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-steel">
                                        Tổng
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-ink">
                                        {{ formatCurrency(totalActive) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-pine">
                                        {{ formatCurrency(lastRunningTotal) }}
                                    </td>
                                    <td colspan="2" class="px-4 py-3" />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
