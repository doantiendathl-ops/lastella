<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    bookingId: number
    chargeTypes: { value: string; label: string }[]
}>()

const emit = defineEmits<{
    cancel: []
}>()

const form = useForm({
    charge_type: 'OTHER',
    description: '',
    quantity: 1,
    unit_price: 0,
    entry_date: new Date().toISOString().slice(0, 10),
})

const previewAmount = computed(() =>
    (parseFloat(String(form.quantity)) || 0) * (parseFloat(String(form.unit_price)) || 0)
)

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`

const submit = () => {
    form.post(`/admin/bookings/${props.bookingId}/folio/entries`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            emit('cancel')
        },
    })
}
</script>

<template>
    <form class="grid gap-3 border border-gray-100 p-4 md:grid-cols-5" @submit.prevent="submit">
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Loại phí</label>
            <select v-model="form.charge_type" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                <option v-for="type in chargeTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Mô tả</label>
            <input v-model="form.description" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Số lượng</label>
            <input v-model="form.quantity" type="number" min="0.01" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Đơn giá</label>
            <input v-model="form.unit_price" type="number" min="0" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ngày phát sinh</label>
            <input v-model="form.entry_date" type="date" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div class="flex items-end gap-2">
            <div class="mr-auto text-sm">
                <div class="text-xs text-steel">Thành tiền (xem trước)</div>
                <div class="font-semibold">{{ formatCurrency(previewAmount) }}</div>
            </div>
            <button type="button" class="border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" @click="emit('cancel')">Hủy</button>
            <button type="submit" class="inline-flex items-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white disabled:bg-gray-300" :disabled="form.processing">
                <Plus class="h-4 w-4" />
                Thêm
            </button>
        </div>
        <p v-if="Object.keys(form.errors).length" class="md:col-span-5 text-sm text-coral">{{ Object.values(form.errors)[0] }}</p>
    </form>
</template>
