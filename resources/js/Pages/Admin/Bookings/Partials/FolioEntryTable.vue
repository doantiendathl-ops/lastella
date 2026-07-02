<script setup lang="ts">
import VoidEntryDialog from './VoidEntryDialog.vue'
import { XCircle } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface CheckableStay {
    id: number
    room_number: string
}

const props = defineProps<{
    entries: {
        id: number
        charge_type: string
        charge_type_label: string
        description: string
        quantity: number
        unit_price: number
        amount: number
        entry_date: string
        posted_by: string | null
        is_voided: boolean
        voided_at: string | null
        voided_by: string | null
        void_reason: string | null
        is_system_entry: boolean
        can_void: boolean
        stay_id: number | null
        posting_source: string | null
    }[]
    bookingId: number
    canVoidCharge: boolean
    checkableStays?: CheckableStay[]
}>()

const openVoidId = ref<number | null>(null)

const colCount = computed(() => (props.canVoidCharge ? 9 : 8))

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`

const roomNumberForStay = (stayId: number | null): string | null => {
    if (!stayId || !props.checkableStays) return null
    return props.checkableStays.find(s => s.id === stayId)?.room_number ?? null
}

const sourceBadgeClass = (source: string | null) => {
    if (source === 'NIGHT_AUDIT') return 'bg-indigo-50 border-indigo-200 text-indigo-700'
    if (source === 'SYSTEM_AUTO') return 'bg-amber-50 border-amber-200 text-amber-700'
    return 'bg-gray-50 border-gray-200 text-gray-500'
}

const sourceBadgeLabel = (source: string | null) => {
    if (source === 'NIGHT_AUDIT') return 'Audit'
    if (source === 'SYSTEM_AUTO') return 'Auto'
    return 'Manual'
}
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                <tr>
                    <th class="px-4 py-3">Ngày</th>
                    <th class="px-4 py-3">Loại phí</th>
                    <th class="px-4 py-3">Mô tả</th>
                    <th class="px-4 py-3">SL</th>
                    <th class="px-4 py-3">Đơn giá</th>
                    <th class="px-4 py-3">Thành tiền</th>
                    <th class="px-4 py-3">Phòng</th>
                    <th class="px-4 py-3">Nguồn</th>
                    <th class="px-4 py-3">Người đăng</th>
                    <th v-if="canVoidCharge" class="px-4 py-3 text-right">Thao tác</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <template v-for="entry in entries" :key="entry.id">
                    <tr :class="entry.is_voided ? 'bg-gray-50' : ''">
                        <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.entry_date }}</td>
                        <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.charge_type_label }}</td>
                        <td class="px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.description }}</td>
                        <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ entry.quantity }}</td>
                        <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ formatCurrency(entry.unit_price) }}</td>
                        <td class="whitespace-nowrap px-4 py-3" :class="entry.is_voided ? 'text-gray-400 line-through' : ''">{{ formatCurrency(entry.amount) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs text-steel">
                            {{ entry.stay_id ? (roomNumberForStay(entry.stay_id) ?? `#${entry.stay_id}`) : '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span
                                class="inline-flex items-center border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                :class="sourceBadgeClass(entry.posting_source)"
                            >
                                {{ sourceBadgeLabel(entry.posting_source) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <template v-if="entry.is_voided">
                                <span class="inline-flex items-center border border-gray-200 bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-400">VOIDED</span>
                                <div v-if="entry.voided_by" class="mt-0.5 text-xs text-gray-400">{{ entry.voided_by }}{{ entry.void_reason ? ' · ' + entry.void_reason : '' }}</div>
                            </template>
                            <template v-else-if="entry.is_system_entry">
                                <span class="text-xs text-steel">Hệ thống</span>
                            </template>
                            <template v-else>{{ entry.posted_by }}</template>
                        </td>
                        <td v-if="canVoidCharge" class="whitespace-nowrap px-4 py-3 text-right">
                            <button
                                v-if="entry.can_void && !entry.is_system_entry"
                                type="button"
                                class="inline-flex h-8 items-center gap-1 border border-gray-200 px-2 text-xs font-semibold text-steel hover:border-coral hover:text-coral"
                                @click="openVoidId = openVoidId === entry.id ? null : entry.id"
                            >
                                <XCircle class="h-3.5 w-3.5" />
                                Huỷ
                            </button>
                        </td>
                    </tr>
                    <tr v-if="openVoidId === entry.id" :key="`void-${entry.id}`">
                        <td :colspan="colCount" class="px-4 pb-3">
                            <VoidEntryDialog
                                :entry="entry"
                                :booking-id="bookingId"
                                @close="openVoidId = null"
                            />
                        </td>
                    </tr>
                </template>
                <tr v-if="!entries.length">
                    <td :colspan="colCount" class="px-4 py-10 text-center text-sm text-steel">Chưa có phí phát sinh.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
