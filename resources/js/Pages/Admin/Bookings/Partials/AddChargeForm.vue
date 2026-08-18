<script setup lang="ts">
import CurrencyInput from '@/Components/CurrencyInput.vue'
import { useForm } from '@inertiajs/vue3'
import { Plus, X } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface ServiceRate {
    id: number
    name: string
    charge_type: string
    unit_price: number
    unit_label: string
}

interface CheckableStay {
    id: number
    room_number: string
}

interface ProductServiceItem {
    id: number
    name: string
    category_name: string | null
    charge_type: string
    unit_price: number
    unit_label: string
}

const props = defineProps<{
    bookingId: number
    chargeTypes: { value: string; label: string }[]
    serviceRates: ServiceRate[]
    productServices?: ProductServiceItem[]
    canOverrideProductPrice?: boolean
    checkableStays: CheckableStay[]
}>()

const emit = defineEmits<{
    cancel: []
}>()

const selectedRateId = ref<number | null>(null)
const selectedProductId = ref<number | null>(null)

const priceLocked = computed(() => selectedProductId.value !== null && !props.canOverrideProductPrice)

const selectProduct = (product: ProductServiceItem) => {
    if (selectedProductId.value === product.id) {
        selectedProductId.value = null
        form.charge_type = 'OTHER'
        form.unit_price = 0
        form.description = ''
    } else {
        selectedProductId.value = product.id
        selectedRateId.value = null
        form.charge_type = product.charge_type
        form.unit_price = product.unit_price
        form.description = product.name
    }
}

const manualChargeTypes = computed(() =>
    props.chargeTypes.filter(t => t.value !== 'ROOM')
)

const form = useForm({
    charge_type: 'OTHER',
    description: '',
    quantity: 1,
    unit_price: 0,
    entry_date: new Date().toISOString().slice(0, 10),
    stay_id: null as number | null,
})

const previewAmount = computed(() =>
    (parseFloat(String(form.quantity)) || 0) * (parseFloat(String(form.unit_price)) || 0)
)

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`

const selectRate = (rate: ServiceRate) => {
    if (selectedRateId.value === rate.id) {
        selectedRateId.value = null
        form.charge_type = 'OTHER'
        form.unit_price = 0
        form.description = ''
    } else {
        selectedRateId.value = rate.id
        selectedProductId.value = null
        form.charge_type = rate.charge_type
        form.unit_price = rate.unit_price
        form.description = rate.name
    }
}

const submit = () => {
    form.post(`/admin/bookings/${props.bookingId}/folio/entries`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            selectedRateId.value = null
            selectedProductId.value = null
            emit('cancel')
        },
    })
}
</script>

<template>
    <div class="border border-gray-100 bg-gray-50 p-4 space-y-4">
        <!-- Catalog tiles -->
        <div v-if="serviceRates.length > 0">
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-steel">Dịch vụ nhanh</div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="rate in serviceRates"
                    :key="rate.id"
                    type="button"
                    class="inline-flex flex-col items-start border px-3 py-2 text-left text-xs transition-colors"
                    :class="selectedRateId === rate.id
                        ? 'border-pine bg-pine text-white'
                        : 'border-gray-300 bg-white text-ink hover:border-pine'"
                    @click="selectRate(rate)"
                >
                    <span class="font-semibold">{{ rate.name }}</span>
                    <span class="mt-0.5 opacity-80">{{ formatCurrency(rate.unit_price) }} / {{ rate.unit_label }}</span>
                </button>
            </div>
        </div>

        <!-- Product/service catalog tiles -->
        <div v-if="(productServices ?? []).length > 0">
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-steel">Sản phẩm/dịch vụ</div>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="product in productServices"
                    :key="product.id"
                    type="button"
                    class="inline-flex flex-col items-start border px-3 py-2 text-left text-xs transition-colors"
                    :class="selectedProductId === product.id
                        ? 'border-pine bg-pine text-white'
                        : 'border-gray-300 bg-white text-ink hover:border-pine'"
                    @click="selectProduct(product)"
                >
                    <span class="font-semibold">{{ product.name }}</span>
                    <span class="mt-0.5 opacity-80">{{ formatCurrency(product.unit_price) }} / {{ product.unit_label }}</span>
                    <span v-if="product.category_name" class="opacity-60">{{ product.category_name }}</span>
                </button>
            </div>
        </div>

        <!-- Manual form fields -->
        <form class="grid gap-3 md:grid-cols-5" @submit.prevent="submit">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại phí</label>
                <select v-model="form.charge_type" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    <option v-for="type in manualChargeTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Mô tả</label>
                <input v-model="form.description" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Số lượng</label>
                <input v-model="form.quantity" type="number" min="0.01" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Đơn giá</label>
                <CurrencyInput
                    v-model="form.unit_price"
                    :disabled="priceLocked"
                    :title="priceLocked ? 'Không có quyền sửa giá danh mục sản phẩm/dịch vụ' : ''"
                    class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-steel"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ngày phát sinh</label>
                <input v-model="form.entry_date" type="date" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
            </div>

            <div v-if="checkableStays.length > 0">
                <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Phòng (tùy chọn)</label>
                <select v-model="form.stay_id" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                    <option :value="null">— Không gán phòng —</option>
                    <option v-for="stay in checkableStays" :key="stay.id" :value="stay.id">
                        {{ stay.room_number }}
                    </option>
                </select>
            </div>

            <div class="flex items-end gap-2 md:col-span-5">
                <div class="mr-auto text-sm">
                    <div class="text-xs text-steel">Thành tiền (xem trước)</div>
                    <div class="font-semibold">{{ formatCurrency(previewAmount) }}</div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink"
                    @click="emit('cancel')"
                >
                    <X class="h-4 w-4" />
                    Hủy
                </button>
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white disabled:bg-gray-300"
                    :disabled="form.processing"
                >
                    <Plus class="h-4 w-4" />
                    Thêm
                </button>
            </div>
            <p v-if="Object.keys(form.errors).length" class="md:col-span-5 text-sm text-coral">{{ Object.values(form.errors)[0] }}</p>
        </form>
    </div>
</template>
