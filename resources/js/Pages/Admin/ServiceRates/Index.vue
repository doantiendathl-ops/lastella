<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { Plus, ToggleLeft, ToggleRight } from 'lucide-vue-next'
import { ref } from 'vue'

interface ServiceRate {
    id: number
    name: string
    charge_type: string
    charge_label: string
    unit_price: number
    effective_from: string
    unit_label: string
    gl_account_code: string | null
    is_active: boolean
    display_order: number
}

interface ChargeTypeOption {
    value: string
    label: string
}

const props = defineProps<{
    rates: ServiceRate[]
    chargeTypes: ChargeTypeOption[]
}>()

const showForm = ref(false)
const editingRate = ref<ServiceRate | null>(null)

const form = useForm({
    name: '',
    charge_type: '',
    unit_price: 0,
    effective_from: new Date().toISOString().slice(0, 10),
    unit_label: 'lần',
    display_order: 0,
    gl_account_code: '',
})

const formatCurrency = (value: number) =>
    new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value) + ' đ'

const openCreate = () => {
    editingRate.value = null
    form.reset()
    showForm.value = true
}

const openEdit = (rate: ServiceRate) => {
    editingRate.value = rate
    form.name            = rate.name
    form.charge_type     = rate.charge_type
    form.unit_price      = rate.unit_price
    form.effective_from  = rate.effective_from
    form.unit_label      = rate.unit_label
    form.display_order   = rate.display_order
    form.gl_account_code = rate.gl_account_code ?? ''
    showForm.value = true
}

const submit = () => {
    if (editingRate.value) {
        form.patch(route('admin.service-rates.update', editingRate.value.id), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false },
        })
    } else {
        form.post(route('admin.service-rates.store'), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false; form.reset() },
        })
    }
}

const toggleActive = (rate: ServiceRate) => {
    router.patch(route('admin.service-rates.toggle', rate.id), {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout>
        <Head title="Danh mục dịch vụ" />

        <div class="mx-auto max-w-5xl px-4 py-8">
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-gray-900">Danh mục dịch vụ</h1>
                <button
                    @click="openCreate"
                    class="flex items-center gap-2 rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                >
                    <Plus :size="16" /> Thêm dịch vụ
                </button>
            </div>

            <!-- Create / Edit Form -->
            <div v-if="showForm" class="mb-6 rounded border border-gray-200 bg-gray-50 p-5">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">
                    {{ editingRate ? 'Cập nhật phiên bản giá' : 'Thêm dịch vụ mới' }}
                </h2>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Tên dịch vụ *</label>
                        <input v-model="form.name" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Loại phí *</label>
                        <select v-model="form.charge_type" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option value="">-- Chọn --</option>
                            <option v-for="ct in chargeTypes" :key="ct.value" :value="ct.value">{{ ct.label }}</option>
                        </select>
                        <p v-if="form.errors.charge_type" class="mt-1 text-xs text-red-600">{{ form.errors.charge_type }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Đơn giá *</label>
                        <input v-model="form.unit_price" type="number" min="0" step="1000" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.unit_price" class="mt-1 text-xs text-red-600">{{ form.errors.unit_price }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Hiệu lực từ *</label>
                        <input v-model="form.effective_from" type="date" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.effective_from" class="mt-1 text-xs text-red-600">{{ form.errors.effective_from }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Đơn vị</label>
                        <input v-model="form.unit_label" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Thứ tự hiển thị</label>
                        <input v-model="form.display_order" type="number" min="0" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="sm:col-span-3 flex gap-2 pt-1">
                        <button type="submit" :disabled="form.processing" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                            {{ editingRate ? 'Lưu phiên bản' : 'Tạo dịch vụ' }}
                        </button>
                        <button type="button" @click="showForm = false" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>

            <!-- Rates Table -->
            <div class="overflow-x-auto rounded border border-gray-200">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">Tên</th>
                            <th class="px-4 py-3">Loại phí</th>
                            <th class="px-4 py-3 text-right">Đơn giá</th>
                            <th class="px-4 py-3">Đơn vị</th>
                            <th class="px-4 py-3">Hiệu lực từ</th>
                            <th class="px-4 py-3">Trạng thái</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="rate in rates"
                            :key="rate.id"
                            class="border-t border-gray-100"
                            :class="{ 'opacity-50': !rate.is_active }"
                        >
                            <td class="px-4 py-3 font-medium">{{ rate.name }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ rate.charge_label }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(rate.unit_price) }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ rate.unit_label }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ rate.effective_from }}</td>
                            <td class="px-4 py-3">
                                <span :class="rate.is_active ? 'text-green-600' : 'text-gray-400'" class="text-xs font-medium">
                                    {{ rate.is_active ? 'Hoạt động' : 'Tắt' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button @click="openEdit(rate)" class="text-xs text-blue-600 hover:underline">Sửa</button>
                                    <button @click="toggleActive(rate)" class="text-xs text-gray-500 hover:underline">
                                        {{ rate.is_active ? 'Tắt' : 'Bật' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="rates.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">Chưa có dịch vụ nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
