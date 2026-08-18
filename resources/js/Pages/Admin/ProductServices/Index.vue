<script setup lang="ts">
import CurrencyInput from '@/Components/CurrencyInput.vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { Plus, Settings2 } from 'lucide-vue-next'
import { ref } from 'vue'

interface Category {
    id: number
    code: string
    name: string
}

interface ProductServiceItem {
    id: number
    category_id: number | null
    category_name: string | null
    code: string
    name: string
    type: string
    type_label: string
    unit: string
    price: number
    free_quantity_default: number
    use_in_checkout_inspection: boolean
    can_add_to_booking: boolean
    is_active: boolean
    sort_order: number
    description: string | null
}

const props = defineProps<{
    items: ProductServiceItem[]
    categories: Category[]
}>()

const showForm = ref(false)
const editingItem = ref<ProductServiceItem | null>(null)

const form = useForm({
    category_id: '' as number | '',
    code: '',
    name: '',
    type: 'product',
    unit: 'cái',
    price: 0,
    free_quantity_default: 0,
    use_in_checkout_inspection: false,
    can_add_to_booking: true,
    is_active: true,
    sort_order: 0,
    description: '',
})

const formatCurrency = (value: number) =>
    new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value) + ' đ'

const openCreate = () => {
    editingItem.value = null
    form.reset()
    form.can_add_to_booking = true
    form.is_active = true
    showForm.value = true
}

const openEdit = (item: ProductServiceItem) => {
    editingItem.value = item
    form.category_id = item.category_id ?? ''
    form.code = item.code
    form.name = item.name
    form.type = item.type
    form.unit = item.unit
    form.price = item.price
    form.free_quantity_default = item.free_quantity_default
    form.use_in_checkout_inspection = item.use_in_checkout_inspection
    form.can_add_to_booking = item.can_add_to_booking
    form.is_active = item.is_active
    form.sort_order = item.sort_order
    form.description = item.description ?? ''
    showForm.value = true
}

const submit = () => {
    if (editingItem.value) {
        form.patch(route('product-services.update', editingItem.value.id), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false },
        })
    } else {
        form.post(route('product-services.store'), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false; form.reset() },
        })
    }
}

const toggleActive = (item: ProductServiceItem) => {
    router.patch(route('product-services.toggle', item.id), {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout>
        <Head title="Sản phẩm và dịch vụ" />

        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">Sản phẩm và dịch vụ</h1>
                <Link
                    href="/product-service-categories"
                    class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-steel hover:text-ink"
                >
                    <Settings2 :size="16" /> Quản lý nhóm
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-6xl">
            <div class="mb-6 flex items-center justify-end">
                <button
                    @click="openCreate"
                    class="flex items-center gap-2 rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                >
                    <Plus :size="16" /> Thêm sản phẩm/dịch vụ
                </button>
            </div>

            <!-- Create / Edit Form -->
            <div v-if="showForm" class="mb-6 rounded border border-gray-200 bg-gray-50 p-5">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">
                    {{ editingItem ? 'Cập nhật sản phẩm/dịch vụ' : 'Thêm sản phẩm/dịch vụ mới' }}
                </h2>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Mã *</label>
                        <input v-model="form.code" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600">Tên *</label>
                        <input v-model="form.name" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Loại *</label>
                        <select v-model="form.type" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option value="product">Sản phẩm</option>
                            <option value="service">Dịch vụ</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Nhóm</label>
                        <select v-model="form.category_id" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option value="">-- Không nhóm --</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Đơn vị *</label>
                        <input v-model="form.unit" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Giá bán *</label>
                        <CurrencyInput v-model="form.price" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.price" class="mt-1 text-xs text-red-600">{{ form.errors.price }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">SL miễn phí mặc định</label>
                        <input v-model="form.free_quantity_default" type="number" min="0" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Thứ tự hiển thị</label>
                        <input v-model="form.sort_order" type="number" min="0" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold text-gray-600">Ghi chú nội bộ</label>
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="sm:col-span-4 flex flex-wrap gap-5 pt-1">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="form.use_in_checkout_inspection" type="checkbox" class="h-4 w-4" />
                            Xuất hiện trong kiểm đồ nhanh
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="form.can_add_to_booking" type="checkbox" class="h-4 w-4" />
                            Được thêm trực tiếp vào booking
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input v-model="form.is_active" type="checkbox" class="h-4 w-4" />
                            Đang hoạt động
                        </label>
                    </div>
                    <div class="sm:col-span-4 flex gap-2 pt-1">
                        <button type="submit" :disabled="form.processing" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                            {{ editingItem ? 'Lưu thay đổi' : 'Tạo mới' }}
                        </button>
                        <button type="button" @click="showForm = false" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>

            <!-- Items table -->
            <div class="overflow-x-auto rounded border border-gray-200">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">Mã</th>
                            <th class="px-4 py-3">Tên</th>
                            <th class="px-4 py-3">Nhóm</th>
                            <th class="px-4 py-3">Loại</th>
                            <th class="px-4 py-3 text-right">Giá</th>
                            <th class="px-4 py-3">Đơn vị</th>
                            <th class="px-4 py-3 text-center">Kiểm đồ</th>
                            <th class="px-4 py-3 text-center">Booking</th>
                            <th class="px-4 py-3">Trạng thái</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in items"
                            :key="item.id"
                            class="border-t border-gray-100"
                            :class="{ 'opacity-50': !item.is_active }"
                        >
                            <td class="px-4 py-3 font-mono text-xs">{{ item.code }}</td>
                            <td class="px-4 py-3 font-medium">{{ item.name }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ item.category_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ item.type_label }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(item.price) }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ item.unit }}</td>
                            <td class="px-4 py-3 text-center">{{ item.use_in_checkout_inspection ? '✓' : '' }}</td>
                            <td class="px-4 py-3 text-center">{{ item.can_add_to_booking ? '✓' : '' }}</td>
                            <td class="px-4 py-3">
                                <span :class="item.is_active ? 'text-green-600' : 'text-gray-400'" class="text-xs font-medium">
                                    {{ item.is_active ? 'Hoạt động' : 'Ngừng' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <button @click="openEdit(item)" class="text-xs text-blue-600 hover:underline">Sửa</button>
                                    <button @click="toggleActive(item)" class="text-xs text-gray-500 hover:underline">
                                        {{ item.is_active ? 'Ngừng' : 'Bật' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="items.length === 0">
                            <td colspan="10" class="px-4 py-8 text-center text-gray-400">Chưa có sản phẩm/dịch vụ nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
