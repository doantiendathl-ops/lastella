<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { ChevronLeft, History, Plus } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface RateRow {
    id: number
    unit_price: number
    effective_from: string
    is_active: boolean
    tax_rate: number
    gl_account_code: string | null
    created_by: string
    created_at: string
}

const props = defineProps<{
    package: { id: number; code: string; name: string; unit_label: string }
    rates: RateRow[]
}>()

const page = usePage()
const flashSuccess = computed(() => (page.props.flash as Record<string, string> | null)?.success ?? null)

const showForm = ref(false)

const form = useForm({
    unit_price: 0,
    effective_from: new Date().toISOString().slice(0, 10),
    tax_rate: 0,
    gl_account_code: '',
    is_active: true,
})

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`

const formatTaxRate = (rate: number) => (rate === 0 ? '—' : `${(rate * 100).toFixed(1)}%`)

const activeCount = computed(() => props.rates.filter((r) => r.is_active).length)

const submit = (): void => {
    form.post(route('admin.service-packages.rates.store', props.package.id), {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset() },
    })
}

const toggleRate = (rate: RateRow): void => {
    router.patch(
        route('admin.service-packages.rates.toggle', { servicePackage: props.package.id, rate: rate.id }),
        {},
        { preserveScroll: true },
    )
}
</script>

<template>
    <AppLayout>
        <Head :title="`Lịch sử giá – ${package.name}`" />

        <div class="mx-auto max-w-5xl">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="mb-1">
                        <Link :href="route('admin.service-packages.index')" class="inline-flex items-center gap-1 text-sm text-steel hover:text-ink">
                            <ChevronLeft class="h-4 w-4" aria-hidden="true" />
                            Gói dịch vụ
                        </Link>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900">Lịch sử giá</h1>
                    <p class="mt-1 text-sm text-steel">
                        Gói: <span class="font-semibold text-ink">{{ package.name }}</span>
                        <span class="ml-2 font-mono text-xs text-gray-400">({{ package.code }})</span>
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">{{ rates.length }} phiên bản</span>
                    <span v-if="activeCount > 0" class="rounded-full bg-pine/10 px-2.5 py-1 text-xs font-medium text-pine">{{ activeCount }} đang dùng</span>
                    <button type="button" class="flex items-center gap-1 bg-pine px-3 py-1.5 text-xs font-semibold text-white hover:bg-pine/90" @click="showForm = !showForm">
                        <Plus class="h-3.5 w-3.5" /> Thêm mức giá
                    </button>
                </div>
            </div>

            <div v-if="flashSuccess" class="mb-5 border-l-4 border-pine bg-pine/5 px-4 py-3 text-sm font-medium text-pine">
                {{ flashSuccess }}
            </div>

            <!-- Add rate form -->
            <div v-if="showForm" class="mb-6 border border-gray-200 bg-gray-50 p-5">
                <p class="mb-3 text-xs text-amber-700">
                    Thay đổi giá sẽ tạo một phiên bản mới. Giá cũ vẫn được giữ trong lịch sử.
                </p>
                <form @submit.prevent="submit" class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Đơn giá ({{ package.unit_label }}) *</label>
                        <input v-model.number="form.unit_price" type="number" min="0" step="1000" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.unit_price" class="mt-1 text-xs text-red-600">{{ form.errors.unit_price }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Hiệu lực từ *</label>
                        <input v-model="form.effective_from" type="date" required class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                        <p v-if="form.errors.effective_from" class="mt-1 text-xs text-red-600">{{ form.errors.effective_from }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Thuế suất (0–1)</label>
                        <input v-model.number="form.tax_rate" type="number" min="0" max="1" step="0.01" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600">Mã tài khoản GL</label>
                        <input v-model="form.gl_account_code" type="text" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="sm:col-span-4 flex gap-2 pt-1">
                        <button type="submit" :disabled="form.processing" class="bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-pine/90 disabled:opacity-60">
                            Thêm mức giá
                        </button>
                        <button type="button" class="border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" @click="showForm = false">
                            Hủy
                        </button>
                    </div>
                </form>
            </div>

            <div v-if="rates.length === 0" class="border border-gray-200 bg-white px-5 py-12 text-center text-sm text-gray-400">
                Chưa có lịch sử giá cho gói này.
            </div>

            <div v-else class="border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-5 py-3">
                    <div class="flex items-center gap-2">
                        <History class="h-4 w-4 text-steel" aria-hidden="true" />
                        <h2 class="text-sm font-semibold text-gray-700">Lịch sử các phiên bản giá</h2>
                    </div>
                    <p class="mt-0.5 text-xs text-steel">Sắp xếp mới nhất trước. Mỗi thay đổi đơn giá tạo một phiên bản mới — không sửa đè giá cũ.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-100 bg-gray-50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-steel">
                                <th class="px-5 py-3">Hiệu lực từ</th>
                                <th class="px-5 py-3 text-right">Đơn giá</th>
                                <th class="px-5 py-3 text-right">Thuế</th>
                                <th class="px-5 py-3">Tài khoản GL</th>
                                <th class="px-5 py-3">Trạng thái</th>
                                <th class="px-5 py-3">Tạo bởi</th>
                                <th class="px-5 py-3">Thời gian</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rate in rates" :key="rate.id" class="border-t border-gray-100" :class="rate.is_active ? 'hover:bg-gray-50' : 'bg-gray-50/40 opacity-70'">
                                <td class="px-5 py-3 font-mono text-sm font-semibold text-gray-700">{{ rate.effective_from }}</td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-gray-800">{{ formatCurrency(rate.unit_price) }}</td>
                                <td class="px-5 py-3 text-right font-mono text-xs text-gray-500">{{ formatTaxRate(rate.tax_rate) }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ rate.gl_account_code ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="rate.is_active ? 'bg-pine/10 text-pine' : 'bg-gray-100 text-gray-500'">
                                        {{ rate.is_active ? 'Đang dùng' : 'Ngừng' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-xs text-gray-500">{{ rate.created_by }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-gray-400">{{ rate.created_at }}</td>
                                <td class="px-5 py-3">
                                    <button type="button" class="text-xs text-gray-500 hover:underline" @click="toggleRate(rate)">
                                        {{ rate.is_active ? 'Ngừng' : 'Bật' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
