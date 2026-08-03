<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { Plus, History } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface Option {
    value: string
    label: string
}

interface ServicePackageRow {
    id: number
    code: string
    name: string
    description: string | null
    charge_type: string
    charge_type_label: string
    calculation_strategy: string
    calculation_strategy_label: string
    quantity_mode: string
    quantity_mode_label: string
    default_quantity: number
    unit_label: string
    posting_frequency: string
    posting_frequency_label: string
    is_active: boolean
    is_bookable: boolean
    display_order: number
    current_rate: number | null
    current_rate_effective_from: string | null
    rate_count: number
    has_been_used: boolean
    can_change_code: boolean
    can_delete: boolean
}

const props = defineProps<{
    packages: ServicePackageRow[]
    chargeTypes: Option[]
    calculationStrategies: Option[]
    quantityModes: Option[]
    postingFrequencies: Option[]
    businessDate: string
}>()

const page = usePage()
const flashSuccess = computed(() => (page.props.flash as Record<string, string> | null)?.success ?? null)

const showForm = ref(false)
const editingPackage = ref<ServicePackageRow | null>(null)

const isDev = import.meta.env.DEV

const form = useForm({
    code: '',
    name: '',
    description: '',
    charge_type: '',
    calculation_strategy: '',
    quantity_mode: '',
    posting_frequency: props.postingFrequencies[0]?.value ?? '',
    default_quantity: 1,
    unit_label: 'đêm',
    display_order: 0,
    is_active: true,
    is_bookable: true,
})

const formatCurrency = (value: number | null): string => {
    if (value === null) return 'Chưa có giá'
    return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`
}

const openCreate = (): void => {
    editingPackage.value = null
    form.reset()
    form.posting_frequency = props.postingFrequencies[0]?.value ?? ''
    showForm.value = true
}

const openEdit = (pkg: ServicePackageRow): void => {
    editingPackage.value = pkg
    form.code = pkg.code
    form.name = pkg.name
    form.description = pkg.description ?? ''
    form.charge_type = pkg.charge_type
    form.calculation_strategy = pkg.calculation_strategy
    form.quantity_mode = pkg.quantity_mode
    form.posting_frequency = pkg.posting_frequency
    form.default_quantity = pkg.default_quantity
    form.unit_label = pkg.unit_label
    form.display_order = pkg.display_order
    form.is_active = pkg.is_active
    form.is_bookable = pkg.is_bookable
    showForm.value = true
}

const submit = (): void => {
    if (editingPackage.value) {
        form.patch(route('admin.service-packages.update', editingPackage.value.id), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false },
        })
    } else {
        form.post(route('admin.service-packages.store'), {
            preserveScroll: true,
            onSuccess: () => { showForm.value = false; form.reset() },
        })
    }
}

const toggleField = (pkg: ServicePackageRow, field: 'is_active' | 'is_bookable'): void => {
    router.patch(route('admin.service-packages.toggle', pkg.id), { field }, { preserveScroll: true })
}
</script>

<template>
    <AppLayout>
        <Head title="Gói dịch vụ" />

        <div class="mx-auto max-w-6xl px-4 py-8">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold text-gray-900">Gói dịch vụ</h1>
                <button
                    type="button"
                    class="flex items-center gap-2 bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90"
                    @click="openCreate"
                >
                    <Plus :size="16" /> Thêm gói dịch vụ
                </button>
            </div>

            <p class="mb-6 text-sm text-steel">
                Quản lý tên, giá theo thời gian, trạng thái của các gói đăng ký trên booking (ăn sáng, người thêm, giường phụ...).
                Cần quản lý phụ phí hệ thống (thuế du lịch, nhận sớm/trả muộn, dịch vụ nhanh)?
                <Link :href="route('admin.service-rates.index')" class="font-medium text-pine hover:underline">
                    Quản lý phụ phí hệ thống
                </Link>
            </p>

            <div v-if="flashSuccess" class="mb-5 border-l-4 border-pine bg-pine/5 px-4 py-3 text-sm font-medium text-pine">
                {{ flashSuccess }}
            </div>

            <div
                v-if="isDev"
                class="mb-5 border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800"
            >
                Giá gói đang được cấu hình; tích hợp tính phí tự động sẽ hoàn tất ở Milestone 4. Night Audit hiện chưa đọc giá từ đây.
            </div>

            <!-- Create / Edit Form -->
            <div v-if="showForm" class="mb-6 border border-gray-200 bg-gray-50 p-5">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">
                    {{ editingPackage ? 'Cập nhật gói dịch vụ' : 'Thêm gói dịch vụ' }}
                </h2>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Tên gói *</label>
                        <input v-model="form.name" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Mã gói *</label>
                        <input
                            v-model="form.code"
                            type="text"
                            required
                            :disabled="!!editingPackage && !editingPackage.can_change_code"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm uppercase disabled:bg-gray-100 disabled:text-gray-400"
                        />
                        <p v-if="editingPackage && !editingPackage.can_change_code" class="mt-1 text-xs text-steel">
                            Không thể đổi mã sau khi gói đã có biểu giá hoặc đã được đăng ký trên booking.
                        </p>
                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Loại phí *</label>
                        <select
                            v-model="form.charge_type"
                            required
                            :disabled="!!editingPackage && editingPackage.has_been_used"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100"
                        >
                            <option value="">-- Chọn --</option>
                            <option v-for="ct in chargeTypes" :key="ct.value" :value="ct.value">{{ ct.label }}</option>
                        </select>
                        <p v-if="form.errors.charge_type" class="mt-1 text-xs text-red-600">{{ form.errors.charge_type }}</p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-xs font-semibold text-gray-600">Mô tả</label>
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Cách tính *</label>
                        <select
                            v-model="form.calculation_strategy"
                            required
                            :disabled="!!editingPackage && editingPackage.has_been_used"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100"
                        >
                            <option value="">-- Chọn --</option>
                            <option v-for="s in calculationStrategies" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                        <p v-if="form.errors.calculation_strategy" class="mt-1 text-xs text-red-600">{{ form.errors.calculation_strategy }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Cách xác định số lượng *</label>
                        <select
                            v-model="form.quantity_mode"
                            required
                            :disabled="!!editingPackage && editingPackage.has_been_used"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100"
                        >
                            <option value="">-- Chọn --</option>
                            <option v-for="m in quantityModes" :key="m.value" :value="m.value">{{ m.label }}</option>
                        </select>
                        <p v-if="form.errors.quantity_mode" class="mt-1 text-xs text-red-600">{{ form.errors.quantity_mode }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Số lượng mặc định *</label>
                        <input v-model.number="form.default_quantity" type="number" min="1" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Đơn vị *</label>
                        <input v-model="form.unit_label" type="text" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Tần suất ghi phí *</label>
                        <select
                            v-model="form.posting_frequency"
                            required
                            :disabled="!!editingPackage && editingPackage.has_been_used"
                            class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-100"
                        >
                            <option v-for="f in postingFrequencies" :key="f.value" :value="f.value">{{ f.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Thứ tự hiển thị</label>
                        <input v-model.number="form.display_order" type="number" min="0" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="flex items-center gap-4 sm:col-span-3">
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                            <input v-model="form.is_active" type="checkbox" /> Hoạt động
                        </label>
                        <label class="flex items-center gap-2 text-xs font-semibold text-gray-600">
                            <input v-model="form.is_bookable" type="checkbox" /> Cho phép đăng ký mới
                        </label>
                    </div>

                    <div
                        v-if="editingPackage && editingPackage.has_been_used"
                        class="sm:col-span-3 border-l-4 border-amber-400 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                    >
                        Thay đổi tên hoặc đơn vị chỉ áp dụng cho hiển thị/các giao dịch tương lai; các FolioEntry đã phát sinh không thay đổi.
                    </div>

                    <div class="sm:col-span-3 flex gap-2 pt-1">
                        <button type="submit" :disabled="form.processing" class="bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-60">
                            {{ editingPackage ? 'Lưu thay đổi' : 'Tạo gói dịch vụ' }}
                        </button>
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" @click="showForm = false">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>

            <!-- Package list -->
            <div class="overflow-x-auto border border-gray-200">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3">Tên gói</th>
                            <th class="px-4 py-3">Mã</th>
                            <th class="px-4 py-3">Cách tính</th>
                            <th class="px-4 py-3">Loại phí</th>
                            <th class="px-4 py-3 text-right">Giá hiện tại</th>
                            <th class="px-4 py-3">Đơn vị</th>
                            <th class="px-4 py-3">Hiệu lực</th>
                            <th class="px-4 py-3">Trạng thái</th>
                            <th class="px-4 py-3">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pkg in packages" :key="pkg.id" class="border-t border-gray-100" :class="{ 'opacity-60': !pkg.is_active }">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ pkg.name }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ pkg.code }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ pkg.calculation_strategy_label }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ pkg.charge_type_label }}</td>
                            <td class="px-4 py-3 text-right font-mono" :class="pkg.current_rate === null ? 'text-gray-400' : 'text-gray-800'">
                                {{ formatCurrency(pkg.current_rate) }}
                            </td>
                            <td class="px-4 py-3 text-gray-500">{{ pkg.unit_label }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ pkg.current_rate_effective_from ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs font-medium" :class="pkg.is_active ? 'text-pine' : 'text-gray-400'">
                                        {{ pkg.is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}
                                    </span>
                                    <span class="text-xs font-medium" :class="pkg.is_bookable ? 'text-pine' : 'text-gray-400'">
                                        {{ pkg.is_bookable ? 'Cho đăng ký mới' : 'Không cho đăng ký mới' }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="text-xs text-pine hover:underline" @click="openEdit(pkg)">Sửa</button>
                                    <button type="button" class="text-xs text-gray-500 hover:underline" @click="toggleField(pkg, 'is_active')">
                                        {{ pkg.is_active ? 'Ngừng' : 'Bật' }}
                                    </button>
                                    <button type="button" class="text-xs text-gray-500 hover:underline" @click="toggleField(pkg, 'is_bookable')">
                                        {{ pkg.is_bookable ? 'Ngừng đăng ký' : 'Cho đăng ký' }}
                                    </button>
                                    <Link
                                        :href="route('admin.service-packages.history', pkg.id)"
                                        class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600 hover:underline"
                                        title="Lịch sử giá"
                                    >
                                        <History class="h-3.5 w-3.5" /> Giá
                                    </Link>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="packages.length === 0">
                            <td colspan="9" class="px-4 py-8 text-center text-gray-400">Chưa có gói dịch vụ nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
