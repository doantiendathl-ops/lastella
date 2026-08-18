<script setup lang="ts">
import CurrencyInput from '@/Components/CurrencyInput.vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { Plus, DollarSign } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface Option {
    value: string
    label: string
}

interface CategoryOption {
    id: number
    name: string
}

interface ServiceRow {
    id: number
    category_id: number
    category_name: string | null
    code: string
    name: string
    description: string | null
    is_chargeable: boolean
    scope: string
    scope_label: string
    billing_mode: string
    billing_mode_label: string
    quantity_enabled: boolean
    default_quantity: number
    unit_label: string
    fulfillment_required: boolean
    is_active: boolean
    is_bookable: boolean
    sort_order: number
    current_price: number | null
    current_price_effective_from: string | null
    price_count: number
    has_been_used: boolean
    can_change_identity: boolean
}

const props = defineProps<{
    services: ServiceRow[]
    categories: CategoryOption[]
    scopes: Option[]
    billingModes: Option[]
    businessDate: string
}>()

const page = usePage()
const flashSuccess = computed(() => (page.props.flash as Record<string, string> | null)?.success ?? null)

const showForm = ref(false)
const editingService = ref<ServiceRow | null>(null)
const priceFormFor = ref<number | null>(null)

const form = useForm({
    category_id: '' as number | string,
    code: '',
    name: '',
    description: '',
    is_chargeable: true,
    scope: 'BOOKING',
    billing_mode: 'ONE_TIME',
    quantity_enabled: false,
    default_quantity: 1,
    unit_label: 'lần',
    fulfillment_required: false,
    sort_order: 0,
    is_active: true,
    is_bookable: true,
})

const priceForm = useForm({
    unit_price: 0,
    effective_from: props.businessDate,
})

const formatCurrency = (value: number | null): string => {
    if (value === null) return 'Chưa có giá'
    return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`
}

const openCreate = (): void => {
    editingService.value = null
    form.reset()
    showForm.value = true
}

const openEdit = (service: ServiceRow): void => {
    editingService.value = service
    form.category_id = service.category_id
    form.code = service.code
    form.name = service.name
    form.description = service.description ?? ''
    form.is_chargeable = service.is_chargeable
    form.scope = service.scope
    form.billing_mode = service.billing_mode
    form.quantity_enabled = service.quantity_enabled
    form.default_quantity = service.default_quantity
    form.unit_label = service.unit_label
    form.fulfillment_required = service.fulfillment_required
    form.sort_order = service.sort_order
    form.is_active = service.is_active
    form.is_bookable = service.is_bookable
    showForm.value = true
}

const submit = (): void => {
    if (editingService.value) {
        form.patch(route('admin.services.update', editingService.value.id), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false },
        })
    } else {
        form.post(route('admin.services.store'), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false; form.reset() },
        })
    }
}

const toggleField = (service: ServiceRow, field: 'is_active' | 'is_bookable'): void => {
    router.patch(route('admin.services.toggle', service.id), { field }, { preserveScroll: true })
}

const openPriceForm = (service: ServiceRow): void => {
    priceFormFor.value = priceFormFor.value === service.id ? null : service.id
    priceForm.reset()
    priceForm.effective_from = props.businessDate
}

const submitPrice = (serviceId: number): void => {
    priceForm.post(route('admin.services.prices.store', serviceId), {
        preserveScroll: true,
        onSuccess: () => { priceFormFor.value = null; priceForm.reset() },
    })
}
</script>

<template>
    <AppLayout>
        <Head title="Dịch vụ & Yêu cầu" />

        <div class="mx-auto max-w-6xl px-4 py-8">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold text-gray-900">Dịch vụ & Yêu cầu</h1>
                <div class="flex gap-2">
                    <Link
                        :href="route('admin.service-categories.index')"
                        class="flex items-center gap-2 border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Danh mục
                    </Link>
                    <button
                        type="button"
                        class="flex items-center gap-2 bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90"
                        @click="openCreate"
                    >
                        <Plus :size="16" /> Thêm dịch vụ
                    </button>
                </div>
            </div>

            <p class="mb-6 text-sm text-steel">
                Danh mục dịch vụ &amp; yêu cầu hợp nhất (Gói dịch vụ, Phụ phí hệ thống, Yêu cầu Booking cũ vẫn còn dùng được song song
                trong giai đoạn chuyển đổi).
            </p>

            <div v-if="flashSuccess" class="mb-5 border-l-4 border-pine bg-pine/5 px-4 py-3 text-sm font-medium text-pine">
                {{ flashSuccess }}
            </div>

            <!-- Create / Edit Form -->
            <div v-if="showForm" class="mb-6 border border-gray-200 bg-gray-50 p-5">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">
                    {{ editingService ? 'Cập nhật dịch vụ' : 'Thêm dịch vụ' }}
                </h2>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Tên dịch vụ *</label>
                        <input v-model="form.name" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Mã *</label>
                        <input
                            v-model="form.code"
                            type="text"
                            required
                            :disabled="!!editingService && !editingService.can_change_identity"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm uppercase disabled:bg-gray-100 disabled:text-gray-400"
                        />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Danh mục *</label>
                        <select v-model="form.category_id" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm">
                            <option value="">-- Chọn --</option>
                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="form.errors.category_id" class="mt-1 text-xs text-red-600">{{ form.errors.category_id }}</p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Mô tả</label>
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Phạm vi áp dụng *</label>
                        <select
                            v-model="form.scope"
                            required
                            :disabled="!!editingService && editingService.has_been_used"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100"
                        >
                            <option v-for="s in scopes" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Cách tính phí *</label>
                        <select
                            v-model="form.billing_mode"
                            required
                            :disabled="!!editingService && editingService.has_been_used"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100"
                        >
                            <option v-for="m in billingModes" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Đơn vị *</label>
                        <input v-model="form.unit_label" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Số lượng mặc định *</label>
                        <input v-model.number="form.default_quantity" type="number" min="1" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Thứ tự hiển thị</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="flex flex-wrap items-center gap-4 sm:col-span-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                            <input v-model="form.is_chargeable" type="checkbox" :disabled="!!editingService && editingService.has_been_used" /> Có thu phí
                        </label>
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                            <input v-model="form.quantity_enabled" type="checkbox" :disabled="!!editingService && editingService.has_been_used" /> Cho nhập số lượng
                        </label>
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                            <input v-model="form.fulfillment_required" type="checkbox" /> Yêu cầu xác nhận/hoàn thành
                        </label>
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                            <input v-model="form.is_active" type="checkbox" /> Hoạt động
                        </label>
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                            <input v-model="form.is_bookable" type="checkbox" /> Cho phép đăng ký mới
                        </label>
                    </div>

                    <div
                        v-if="editingService && editingService.has_been_used"
                        class="sm:col-span-3 border-l-4 border-amber-400 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                    >
                        Không thể đổi mã, phạm vi áp dụng, cách tính phí, có thu phí hay không, hoặc cho nhập số lượng sau khi dịch vụ
                        đã có giá hoặc đã được dùng trên booking. Đổi tên/đơn vị chỉ áp dụng hiển thị — giao dịch cũ giữ nguyên.
                    </div>

                    <div class="sm:col-span-3 flex gap-2 pt-1">
                        <button type="submit" :disabled="form.processing" class="bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-60">
                            {{ editingService ? 'Lưu thay đổi' : 'Tạo dịch vụ' }}
                        </button>
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" @click="showForm = false">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>

            <!-- Service list -->
            <div class="overflow-x-auto border border-gray-200">
                <table class="w-full min-w-[1000px] text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">Tên dịch vụ</th>
                            <th class="px-4 py-3">Danh mục</th>
                            <th class="px-4 py-3">Phạm vi</th>
                            <th class="px-4 py-3">Cách tính</th>
                            <th class="px-4 py-3 text-right">Giá hiện tại</th>
                            <th class="px-4 py-3">Trạng thái</th>
                            <th class="px-4 py-3">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="service in services" :key="service.id">
                            <tr class="border-t border-gray-100" :class="{ 'opacity-60': !service.is_active }">
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ service.name }}
                                    <div class="font-mono text-xs text-gray-400">{{ service.code }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ service.category_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ service.scope_label }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ service.billing_mode_label }}</td>
                                <td class="px-4 py-3 text-right font-mono" :class="service.current_price === null ? 'text-gray-400' : 'text-gray-800'">
                                    <template v-if="service.is_chargeable">{{ formatCurrency(service.current_price) }}</template>
                                    <template v-else>Miễn phí</template>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-col gap-1">
                                        <span class="text-xs font-medium" :class="service.is_active ? 'text-pine' : 'text-gray-400'">
                                            {{ service.is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}
                                        </span>
                                        <span class="text-xs font-medium" :class="service.is_bookable ? 'text-pine' : 'text-gray-400'">
                                            {{ service.is_bookable ? 'Cho đăng ký mới' : 'Không cho đăng ký mới' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="text-xs text-pine hover:underline" @click="openEdit(service)">Sửa</button>
                                        <button type="button" class="text-xs text-gray-500 hover:underline" @click="toggleField(service, 'is_active')">
                                            {{ service.is_active ? 'Ngừng' : 'Bật' }}
                                        </button>
                                        <button type="button" class="text-xs text-gray-500 hover:underline" @click="toggleField(service, 'is_bookable')">
                                            {{ service.is_bookable ? 'Ngừng đăng ký' : 'Cho đăng ký' }}
                                        </button>
                                        <button
                                            v-if="service.is_chargeable"
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600 hover:underline"
                                            @click="openPriceForm(service)"
                                        >
                                            <DollarSign class="h-3.5 w-3.5" /> Giá ({{ service.price_count }})
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="priceFormFor === service.id" class="border-t border-gray-100 bg-gray-50">
                                <td colspan="7" class="px-4 py-3">
                                    <form @submit.prevent="submitPrice(service.id)" class="flex flex-wrap items-end gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-600">Giá mới *</label>
                                            <CurrencyInput v-model="priceForm.unit_price" required class="mt-1 w-40 border border-gray-300 px-3 py-2 text-sm" />
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-600">Áp dụng từ ngày *</label>
                                            <input v-model="priceForm.effective_from" type="date" required class="mt-1 border border-gray-300 px-3 py-2 text-sm" />
                                        </div>
                                        <button type="submit" :disabled="priceForm.processing" class="bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-60">
                                            Lưu giá
                                        </button>
                                        <p v-if="priceForm.errors.unit_price" class="text-xs text-red-600">{{ priceForm.errors.unit_price }}</p>
                                    </form>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="services.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">Chưa có dịch vụ nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
