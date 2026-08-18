<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatDate } from '@/Support/format'
import { Head, Link } from '@inertiajs/vue3'
import { ChevronLeft, History } from 'lucide-vue-next'
import { computed } from 'vue'

interface RateRow {
    id: number
    charge_type: string
    charge_label: string
    name: string
    unit_price: number
    effective_from: string
    is_active: boolean
    tax_rate: number
    gl_account_code: string | null
    created_by: string
    created_at: string
}

const props = defineProps<{
    chargeType: string
    chargeLabel: string
    rates: RateRow[]
}>()

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`

const formatTaxRate = (rate: number) =>
    rate === 0 ? '—' : `${(rate * 100).toFixed(1)}%`

const activeCount = computed(() => props.rates.filter((r) => r.is_active).length)
</script>

<template>
    <AppLayout>
        <Head :title="`Lịch sử giá – ${chargeLabel}`" />

        <div class="mx-auto max-w-5xl">
            <!-- Header -->
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="mb-1">
                        <Link
                            :href="route('admin.service-rates.index')"
                            class="inline-flex items-center gap-1 text-sm text-steel hover:text-ink"
                        >
                            <ChevronLeft class="h-4 w-4" aria-hidden="true" />
                            Danh mục dịch vụ
                        </Link>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900">Lịch sử giá</h1>
                    <p class="mt-1 text-sm text-steel">
                        Loại phí:
                        <span class="font-semibold text-ink">{{ chargeLabel }}</span>
                        <span class="ml-2 font-mono text-xs text-gray-400">({{ chargeType }})</span>
                    </p>
                </div>

                <div class="flex items-center gap-2 text-sm text-steel">
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">
                        {{ rates.length }} phiên bản
                    </span>
                    <span v-if="activeCount > 0" class="rounded-full bg-pine/10 px-2.5 py-1 text-xs font-medium text-pine">
                        {{ activeCount }} đang dùng
                    </span>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="rates.length === 0"
                class="rounded border border-gray-200 bg-white px-5 py-12 text-center text-sm text-gray-400"
            >
                Chưa có lịch sử giá cho loại phí này.
            </div>

            <!-- Rate history table -->
            <div v-else class="rounded border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <History class="h-4 w-4 text-steel" aria-hidden="true" />
                        <h2 class="text-sm font-semibold text-gray-700">Lịch sử các phiên bản giá</h2>
                    </div>
                    <p class="mt-0.5 text-xs text-steel">
                        Sắp xếp mới nhất trước. Mỗi thay đổi đơn giá tạo một phiên bản mới (ADR-66).
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th scope="col" class="px-5 py-3">Hiệu lực từ</th>
                                <th scope="col" class="px-5 py-3">Tên</th>
                                <th scope="col" class="px-5 py-3 text-right">Đơn giá</th>
                                <th scope="col" class="px-5 py-3 text-right">Thuế</th>
                                <th scope="col" class="px-5 py-3">Tài khoản GL</th>
                                <th scope="col" class="px-5 py-3">Trạng thái</th>
                                <th scope="col" class="px-5 py-3">Tạo bởi</th>
                                <th scope="col" class="px-5 py-3">Thời gian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="rate in rates"
                                :key="rate.id"
                                class="border-t border-gray-100"
                                :class="rate.is_active ? 'hover:bg-gray-50' : 'bg-gray-50/40 opacity-70 hover:bg-gray-100/40'"
                            >
                                <td class="px-5 py-3 font-mono text-sm font-semibold text-gray-700">
                                    {{ formatDate(rate.effective_from) }}
                                </td>
                                <td class="px-5 py-3 text-gray-800">{{ rate.name }}</td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-gray-800">
                                    {{ formatCurrency(rate.unit_price) }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-xs text-gray-500">
                                    {{ formatTaxRate(rate.tax_rate) }}
                                </td>
                                <td class="px-5 py-3 font-mono text-xs text-gray-500">
                                    {{ rate.gl_account_code ?? '—' }}
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="rate.is_active ? 'bg-pine/10 text-pine' : 'bg-gray-100 text-gray-500'"
                                    >
                                        {{ rate.is_active ? 'Đang dùng' : 'Ngừng' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-xs text-gray-500">{{ rate.created_by }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ formatDate(rate.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
