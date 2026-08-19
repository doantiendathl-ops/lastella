<script setup lang="ts">
import CurrencyInput from '@/Components/CurrencyInput.vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ChevronLeft, Info } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface RoomOption {
    id: number
    room_number: string
}

interface AvailableService {
    id: number
    category_name: string | null
    name: string
    description: string | null
    is_chargeable: boolean
    scope: 'BOOKING' | 'ROOM' | 'BOTH'
    billing_mode: 'ONE_TIME' | 'PER_NIGHT' | 'BOTH'
    quantity_enabled: boolean
    default_quantity: number
    unit_label: string
    fulfillment_required: boolean
    current_price: number | null
    has_price: boolean
}

interface BookingServiceRow {
    id: number
    service_name: string
    category_name: string | null
    room_number: string | null
    quantity: number
    billing_mode_selected: string
    suggested_price: number
    actual_price: number
    is_price_overridden: boolean
    price_override_reason: string | null
    fulfillment_status: string
    fulfillment_status_label: string
    created_by: string | null
    confirmed_by: string | null
    completed_by: string | null
    cancelled_by: string | null
}

const props = defineProps<{
    booking: { id: number; booking_code: string; customer_name: string; status: string }
    available_services: AvailableService[]
    rooms: RoomOption[]
    booking_services: BookingServiceRow[]
    can: { manage: boolean }
}>()

const showForm = ref(false)
const selectedServiceId = ref<number | null>(null)

const selectedService = computed<AvailableService | null>(
    () => props.available_services.find((s) => s.id === selectedServiceId.value) ?? null,
)

const form = useForm({
    service_id: null as number | null,
    room_assignment_id: null as number | null,
    quantity: 1,
    billing_mode_selected: null as string | null,
    actual_price: null as number | null,
    price_override_reason: '',
})

const priceIsOverridden = computed(() => {
    if (!selectedService.value || form.actual_price === null) return false
    return Number(form.actual_price) !== Number(selectedService.value.current_price ?? 0)
})

const selectService = (service: AvailableService): void => {
    selectedServiceId.value = service.id
    form.service_id = service.id
    form.room_assignment_id = null
    form.quantity = service.default_quantity
    form.billing_mode_selected = service.billing_mode === 'BOTH' ? null : service.billing_mode
    form.actual_price = service.current_price
    form.price_override_reason = ''
    showForm.value = true
}

const submit = (): void => {
    form.post(route('admin.bookings.services.store', props.booking.id), {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false
            selectedServiceId.value = null
            form.reset()
        },
    })
}

const formatCurrency = (value: number | null): string => {
    if (value === null) return 'Miễn phí'
    return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`
}

const STATUS_CLASSES: Record<string, string> = {
    CREATED: 'text-gray-500',
    CONFIRMED: 'text-blue-600',
    COMPLETED: 'text-pine',
    CANCELLED: 'text-gray-400 line-through',
}

const confirmRow = (row: BookingServiceRow): void => {
    if (!confirm(`Xác nhận "${row.service_name}"?`)) return
    router.patch(route('admin.bookings.services.confirm', [props.booking.id, row.id]), {}, { preserveScroll: true })
}

const completeRow = (row: BookingServiceRow): void => {
    if (!confirm(`Đánh dấu "${row.service_name}" đã hoàn thành?`)) return
    router.patch(route('admin.bookings.services.complete', [props.booking.id, row.id]), {}, { preserveScroll: true })
}

const cancelRow = (row: BookingServiceRow): void => {
    if (!confirm(`Hủy "${row.service_name}"?`)) return
    router.patch(route('admin.bookings.services.cancel', [props.booking.id, row.id]), {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout>
        <Head :title="`Dịch vụ & Yêu cầu – ${booking.booking_code}`" />

        <div class="mx-auto max-w-5xl px-4 py-8">
            <Link :href="route('admin.bookings.show', booking.id)" class="mb-4 inline-flex items-center gap-1 text-sm text-steel hover:text-pine">
                <ChevronLeft class="h-4 w-4" /> Đặt phòng
            </Link>

            <h1 class="text-2xl font-bold text-gray-900">Dịch vụ &amp; Yêu cầu</h1>
            <p class="mb-6 text-sm text-steel">{{ booking.booking_code }} · {{ booking.customer_name }} · {{ booking.status }}</p>

            <!-- Available services -->
            <div class="mb-6 space-y-2">
                <h2 class="text-sm font-semibold text-gray-700">Chọn dịch vụ/yêu cầu</h2>
                <div v-if="available_services.length === 0" class="flex items-center gap-2 border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-steel">
                    <Info class="h-4 w-4 shrink-0" /> Chưa có dịch vụ nào được cấu hình. Vào "Dịch vụ & Yêu cầu" trong Admin để thêm.
                </div>
                <div v-else class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="service in available_services"
                        :key="service.id"
                        type="button"
                        class="border px-4 py-3 text-left text-sm hover:border-pine"
                        :class="selectedServiceId === service.id ? 'border-pine bg-pine/5' : 'border-gray-200'"
                        @click="selectService(service)"
                    >
                        <div class="font-medium text-gray-900">{{ service.name }}</div>
                        <div class="text-xs text-steel">{{ service.category_name }} · {{ formatCurrency(service.current_price) }}</div>
                    </button>
                </div>
            </div>

            <!-- Enrollment form -->
            <div v-if="showForm && selectedService" class="mb-8 border border-gray-200 bg-gray-50 p-5">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">Thêm "{{ selectedService.name }}"</h2>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-2">
                    <div v-if="selectedService.scope !== 'BOOKING'">
                        <label class="block text-xs font-semibold text-gray-600">Phòng {{ selectedService.scope === 'ROOM' ? '*' : '' }}</label>
                        <select v-model.number="form.room_assignment_id" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option :value="null">-- Toàn booking --</option>
                            <option v-for="r in rooms" :key="r.id" :value="r.id">Phòng {{ r.room_number }}</option>
                        </select>
                        <p v-if="form.errors.room_assignment_id" class="mt-1 text-xs text-red-600">{{ form.errors.room_assignment_id }}</p>
                    </div>

                    <div v-if="selectedService.billing_mode === 'BOTH'">
                        <label class="block text-xs font-semibold text-gray-600">Cách tính *</label>
                        <select v-model="form.billing_mode_selected" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option :value="null">-- Chọn --</option>
                            <option value="ONE_TIME">Một lần</option>
                            <option value="PER_NIGHT">Qua đêm</option>
                        </select>
                    </div>

                    <div v-if="selectedService.quantity_enabled">
                        <label class="block text-xs font-semibold text-gray-600">Số lượng ({{ selectedService.unit_label }})</label>
                        <input v-model.number="form.quantity" type="number" min="1" max="99" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>

                    <div v-if="selectedService.is_chargeable">
                        <label class="block text-xs font-semibold text-gray-600">Giá đề xuất</label>
                        <div class="mt-1 px-3 py-2 text-sm text-gray-500">{{ formatCurrency(selectedService.current_price) }}</div>
                    </div>

                    <div v-if="selectedService.is_chargeable">
                        <label class="block text-xs font-semibold text-gray-600">Giá thực hiện</label>
                        <CurrencyInput v-model="form.actual_price" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>

                    <div v-if="priceIsOverridden" class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Lý do thay đổi giá *</label>
                        <input v-model="form.price_override_reason" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.price_override_reason" class="mt-1 text-xs text-red-600">{{ form.errors.price_override_reason }}</p>
                    </div>

                    <div class="sm:col-span-2 flex gap-2 pt-1">
                        <button type="submit" :disabled="form.processing" class="bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-60">
                            Lưu
                        </button>
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" @click="showForm = false; selectedServiceId = null">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>

            <!-- Enrolled services -->
            <div>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">Đã thêm vào booking này</h2>
                <div class="overflow-x-auto border border-gray-200">
                    <table class="w-full min-w-[800px] text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-3">Dịch vụ</th>
                                <th class="px-4 py-3">Phòng</th>
                                <th class="px-4 py-3">SL</th>
                                <th class="px-4 py-3 text-right">Giá thực hiện</th>
                                <th class="px-4 py-3">Trạng thái</th>
                                <th class="px-4 py-3">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in booking_services" :key="row.id" class="border-t border-gray-100">
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ row.service_name }}
                                    <div v-if="row.is_price_overridden" class="text-xs text-amber-600">Đổi giá: {{ row.price_override_reason }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ row.room_number ?? 'Toàn booking' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ row.quantity }}</td>
                                <td class="px-4 py-3 text-right font-mono text-gray-800">{{ formatCurrency(row.actual_price) }}</td>
                                <td class="px-4 py-3">
                                    <span class="text-xs font-medium" :class="STATUS_CLASSES[row.fulfillment_status]">
                                        {{ row.fulfillment_status_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div v-if="can.manage" class="flex flex-wrap gap-2">
                                        <button v-if="row.fulfillment_status === 'CREATED'" type="button" class="text-xs text-pine hover:underline" @click="confirmRow(row)">Xác nhận</button>
                                        <button v-if="row.fulfillment_status === 'CREATED' || row.fulfillment_status === 'CONFIRMED'" type="button" class="text-xs text-pine hover:underline" @click="completeRow(row)">Hoàn thành</button>
                                        <!-- User request (2026-08-20 chat) — allow cancelling even after
                                             COMPLETED (fulfillment lifecycle is independent of billing,
                                             see ServiceFulfillmentStatus::allowedNextStatuses()). -->
                                        <button v-if="row.fulfillment_status !== 'CANCELLED'" type="button" class="text-xs text-red-600 hover:underline" @click="cancelRow(row)">Hủy</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="booking_services.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-400">Chưa có dịch vụ/yêu cầu nào.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
