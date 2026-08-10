<script setup>
import axios from 'axios';
import { Minus, Plus, Search, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    stay: { type: Object, required: true },
    products: { type: Array, required: true },
});

const emit = defineEmits(['close', 'success']);

// Complimentary/Chargeable Separation (Mục X-XXI): these product codes get
// their complimentary standard from ROOM standard occupancy
// (RoomType.standard_adults, Mục XII) instead of the product's own static
// free_quantity_default — a stable, finite whitelist by product `code`,
// matching the same string-constant convention already used elsewhere in
// this codebase (e.g. PackageEnrollmentService::EXTRA_BED_PER_NIGHT) rather
// than a new schema column, since `code` already uniquely identifies it.
const ROOM_CAPACITY_SCALED_PRODUCT_CODES = ['MB_WATER_500', 'MB_WATER'];

function complimentaryStandardFor(product) {
    if (ROOM_CAPACITY_SCALED_PRODUCT_CODES.includes(product.code)) {
        return props.stay.standard_occupancy ?? product.free_quantity_default ?? 0;
    }
    return product.free_quantity_default ?? 0;
}

const inspection = ref(props.stay.inspection);
const loading = ref(true);
const processing = ref(false);
const errorMessage = ref('');
const note = ref('');
const search = ref('');
const categoryFilter = ref('');
const rows = ref([]);
// Mục IV/XXIV: user explicitly opted into correcting an already-Completed
// sheet (still pre-checkout) — a local UI toggle, not a new inspection state.
const editingCompleted = ref(false);

const categories = computed(() => {
    const names = new Set(props.products.map((p) => p.category_name).filter(Boolean));
    return Array.from(names);
});

const filteredProducts = computed(() => props.products.filter((p) => {
    const matchesSearch = search.value === '' || p.name.toLowerCase().includes(search.value.toLowerCase()) || p.code.toLowerCase().includes(search.value.toLowerCase());
    const matchesCategory = categoryFilter.value === '' || p.category_name === categoryFilter.value;
    return matchesSearch && matchesCategory;
}));

const formatCurrency = (value) => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value) + ' đ';

// Mục XVI: CHARGE = chargeable_quantity × unit_price. No subtraction, ever.
const lineTotalFor = (row) => Number(row.chargeable_quantity || 0) * Number(row.unit_price);

const grandTotal = computed(() => rows.value.reduce((sum, row) => sum + lineTotalFor(row), 0));

const hydrateFromInspection = () => {
    rows.value = (inspection.value?.items ?? []).map((item) => ({
        product_service_id: item.product_service_id,
        product_name: item.product_name,
        unit: item.unit,
        unit_price: item.unit_price,
        complimentary_standard: item.complimentary_quantity,
        // Loaded verbatim from the saved item — never re-derived (Mục XV: "trừ khi
        // user đang mở lại chính Inspection đã lưu, khi đó phải load saved quantity").
        chargeable_quantity: item.chargeable_quantity,
        note: item.note ?? '',
    }));
    note.value = inspection.value?.note ?? '';
};

onMounted(async () => {
    try {
        if (!inspection.value) {
            const response = await axios.post(route('admin.checkout-inspections.draft', props.stay.stay_id));
            inspection.value = response.data;
        }
        hydrateFromInspection();
    } catch (error) {
        errorMessage.value = error.response ? (error.response.data?.message ?? 'Không thể mở phiếu kiểm đồ.') : 'Mất kết nối, không thể mở phiếu kiểm đồ.';
    } finally {
        loading.value = false;
    }
});

const isCompleted = computed(() => inspection.value?.status === 'COMPLETED');
// Mục V/VI: server-computed, authoritative — false once the stay has checked
// out, for every role including ADMIN. Never derived client-side.
const canEditCompleted = computed(() => inspection.value?.can_edit_completed === true);
const isLockedByCheckout = computed(() => isCompleted.value && !canEditCompleted.value);
// Inputs are live whenever this is a Draft, OR the user is actively correcting
// an already-Completed (still pre-checkout) sheet.
const rowsEditable = computed(() => !isCompleted.value || editingCompleted.value);

function startEditCompleted() {
    editingCompleted.value = true;
}

function cancelEditCompleted() {
    editingCompleted.value = false;
    hydrateFromInspection();
}

const addProduct = (product) => {
    if (!rowsEditable.value) return;
    const existing = rows.value.find((r) => r.product_service_id === product.id);
    if (existing) return;
    // Mục XV/XXI: chargeable quantity ALWAYS starts at 0 — never prefilled from
    // complimentary allowance, room capacity, guest count, or anything else.
    rows.value.push({
        product_service_id: product.id,
        product_name: product.name,
        unit: product.unit,
        unit_price: product.price,
        complimentary_standard: complimentaryStandardFor(product),
        chargeable_quantity: 0,
        note: '',
    });
};

const removeRow = (index) => {
    rows.value.splice(index, 1);
};

const buildPayload = () => ({
    note: note.value || null,
    items: rows.value.map((row) => ({
        product_service_id: row.product_service_id,
        chargeable_quantity: Number(row.chargeable_quantity) || 0,
        complimentary_quantity: row.complimentary_standard ?? null,
        note: row.note || null,
    })),
});

const saveDraft = async () => {
    processing.value = true;
    errorMessage.value = '';
    try {
        const response = await axios.patch(route('admin.checkout-inspections.save', inspection.value.id), buildPayload());
        inspection.value = response.data;
        emit('success');
    } catch (error) {
        errorMessage.value = error.response ? (error.response.data?.message ?? 'Không thể lưu nháp.') : 'Mất kết nối, thao tác chưa được lưu.';
    } finally {
        processing.value = false;
    }
};

const complete = async (clearItems = false) => {
    processing.value = true;
    errorMessage.value = '';
    try {
        const payload = clearItems ? { note: note.value || null, items: [] } : buildPayload();
        const response = await axios.post(route('admin.checkout-inspections.complete', inspection.value.id), payload);
        inspection.value = response.data;
        emit('success');
        emit('close');
    } catch (error) {
        errorMessage.value = error.response ? (error.response.data?.message ?? 'Không thể hoàn tất kiểm đồ.') : 'Mất kết nối, thao tác chưa được lưu.';
    } finally {
        processing.value = false;
    }
};

// Mục IV/VII/IX/XXVI: corrects an already-Completed sheet, pre-checkout only.
// The final amount reflects ONLY the resubmitted data (server voids the old
// posted lines and reposts fresh ones) — never a sum of old+new. Same-data
// resubmission is a no-op server-side (idempotent).
const saveCompletedEdit = async () => {
    processing.value = true;
    errorMessage.value = '';
    try {
        const response = await axios.patch(route('admin.checkout-inspections.edit-completed', inspection.value.id), buildPayload());
        inspection.value = response.data;
        editingCompleted.value = false;
        emit('success');
    } catch (error) {
        errorMessage.value = error.response ? (error.response.data?.message ?? 'Không thể lưu thay đổi.') : 'Mất kết nối, thao tác chưa được lưu.';
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="emit('close')">
        <div class="flex max-h-[90vh] w-full max-w-3xl flex-col border border-gray-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <div>
                    <span class="text-sm font-semibold text-ink">Kiểm đồ phòng {{ stay.room_number }}</span>
                    <span v-if="stay.guest_name" class="ml-2 text-xs text-steel">{{ stay.guest_name }}</span>
                </div>
                <button type="button" class="flex min-h-11 min-w-11 items-center justify-center text-steel hover:text-ink" @click="emit('close')">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div v-if="loading" class="p-8 text-center text-sm text-steel">Đang tải...</div>

            <template v-else>
                <!-- Mục V/VI: locked after checkout — view-only, no role bypass, no financial mutation. -->
                <div v-if="isLockedByCheckout" class="border-b border-amber-300 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-800">
                    Phòng đã trả. Dữ liệu kiểm đồ đã được chốt. Tổng phí: {{ formatCurrency(inspection.total_amount) }}
                </div>
                <div v-else-if="isCompleted && !editingCompleted" class="flex items-center justify-between border-b border-pine bg-linen px-4 py-2 text-xs font-semibold text-pine">
                    <span>ĐÃ KIỂM ĐỒ — Tổng phí: {{ formatCurrency(inspection.total_amount) }}</span>
                    <button type="button" class="rounded border border-pine px-2 py-1 text-xs font-semibold text-pine hover:bg-pine hover:text-white" @click="startEditCompleted">
                        Sửa
                    </button>
                </div>
                <div v-else-if="isCompleted && editingCompleted" class="flex items-center justify-between border-b border-amber-300 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-800">
                    <span>Đang sửa phiếu đã hoàn tất — lưu sẽ cập nhật phí đã ghi nhận.</span>
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 text-xs font-semibold text-steel hover:text-ink" @click="cancelEditCompleted">
                        Hủy sửa
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-4">
                    <div v-if="rowsEditable" class="mb-4 border border-gray-200 p-3">
                        <div class="mb-2 flex flex-wrap gap-2">
                            <div class="relative min-w-48 flex-1">
                                <Search class="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-steel" />
                                <input v-model="search" type="search" placeholder="Tìm sản phẩm..." class="min-h-11 w-full border border-gray-300 py-2 pl-8 pr-2 text-sm" />
                            </div>
                            <select v-model="categoryFilter" class="min-h-11 border border-gray-300 px-2 py-2 text-sm">
                                <option value="">Tất cả nhóm</option>
                                <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                            </select>
                        </div>
                        <div class="flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                            <button
                                v-for="product in filteredProducts"
                                :key="product.id"
                                type="button"
                                class="min-h-11 border border-gray-300 px-3 py-2 text-xs text-ink hover:border-pine hover:text-pine"
                                @click="addProduct(product)"
                            >
                                {{ product.name }} · {{ formatCurrency(product.price) }}
                            </button>
                            <p v-if="filteredProducts.length === 0" class="text-xs text-steel">Không có sản phẩm phù hợp.</p>
                        </div>
                    </div>

                    <!-- Mobile: one block per product. -->
                    <div v-if="rows.length > 0" class="space-y-2 sm:hidden">
                        <div v-for="(row, index) in rows" :key="index" class="border border-gray-200 p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-ink">{{ row.product_name }}</div>
                                    <div class="text-xs text-steel">{{ row.unit }}</div>
                                    <div v-if="row.complimentary_standard > 0" class="text-xs text-steel">
                                        Miễn phí theo tiêu chuẩn: {{ row.complimentary_standard }} {{ row.unit }} (không tính phí)
                                    </div>
                                </div>
                                <button v-if="rowsEditable" type="button" class="shrink-0 px-2 py-1 text-xs font-semibold text-coral" @click="removeRow(index)">
                                    Xóa
                                </button>
                            </div>

                            <div class="mt-2 flex items-center gap-2">
                                <span class="w-28 shrink-0 text-xs text-steel">Số lượng tính phí</span>
                                <button
                                    v-if="rowsEditable"
                                    type="button"
                                    class="flex min-h-11 min-w-11 items-center justify-center border border-gray-300"
                                    @click="row.chargeable_quantity = Math.max(0, Number(row.chargeable_quantity) - 1)"
                                >
                                    <Minus class="h-4 w-4" />
                                </button>
                                <input
                                    v-model.number="row.chargeable_quantity"
                                    type="number"
                                    min="0"
                                    :disabled="!rowsEditable"
                                    class="min-h-11 w-16 border border-gray-300 px-1 text-center text-sm"
                                />
                                <button
                                    v-if="rowsEditable"
                                    type="button"
                                    class="flex min-h-11 min-w-11 items-center justify-center border border-gray-300"
                                    @click="row.chargeable_quantity = Number(row.chargeable_quantity) + 1"
                                >
                                    <Plus class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2 text-sm">
                                <span class="text-steel">Đơn giá {{ formatCurrency(row.unit_price) }}</span>
                                <span class="font-semibold text-ink">{{ formatCurrency(lineTotalFor(row)) }}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm font-semibold">
                            <span>Tổng tính phí</span>
                            <span>{{ formatCurrency(grandTotal) }}</span>
                        </div>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-steel sm:hidden">Chưa có sản phẩm nào phát sinh.</p>

                    <div class="hidden sm:block sm:overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-steel">
                            <tr>
                                <th class="px-2 py-2 text-left">Sản phẩm</th>
                                <th class="px-2 py-2 text-center">Miễn phí theo tiêu chuẩn</th>
                                <th class="px-2 py-2 text-center">Số lượng tính phí</th>
                                <th class="px-2 py-2 text-right">Đơn giá</th>
                                <th class="px-2 py-2 text-right">Thành tiền</th>
                                <th v-if="rowsEditable" class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in rows" :key="index" class="border-t border-gray-100">
                                <td class="px-2 py-2">
                                    <div class="font-medium">{{ row.product_name }}</div>
                                    <div class="text-xs text-steel">{{ row.unit }}</div>
                                </td>
                                <td class="px-2 py-2 text-center text-steel">
                                    {{ row.complimentary_standard > 0 ? `${row.complimentary_standard} ${row.unit}` : '—' }}
                                </td>
                                <td class="px-2 py-2">
                                    <div class="flex items-center justify-center gap-1">
                                        <button v-if="rowsEditable" type="button" class="border border-gray-300 p-1" @click="row.chargeable_quantity = Math.max(0, Number(row.chargeable_quantity) - 1)">
                                            <Minus class="h-3 w-3" />
                                        </button>
                                        <input
                                            v-model.number="row.chargeable_quantity"
                                            type="number"
                                            min="0"
                                            :disabled="!rowsEditable"
                                            class="w-16 border border-gray-300 px-1 py-1 text-center text-sm"
                                        />
                                        <button v-if="rowsEditable" type="button" class="border border-gray-300 p-1" @click="row.chargeable_quantity = Number(row.chargeable_quantity) + 1">
                                            <Plus class="h-3 w-3" />
                                        </button>
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-right">{{ formatCurrency(row.unit_price) }}</td>
                                <td class="px-2 py-2 text-right font-semibold">{{ formatCurrency(lineTotalFor(row)) }}</td>
                                <td v-if="rowsEditable" class="px-2 py-2 text-right">
                                    <button type="button" class="text-coral hover:underline" @click="removeRow(index)">Xóa</button>
                                </td>
                            </tr>
                            <tr v-if="rows.length === 0">
                                <td :colspan="rowsEditable ? 6 : 5" class="px-2 py-6 text-center text-steel">Chưa có sản phẩm nào phát sinh.</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="rows.length > 0">
                            <tr class="border-t border-gray-200 font-semibold">
                                <td class="px-2 py-2" colspan="4">Tổng tính phí</td>
                                <td class="px-2 py-2 text-right">{{ formatCurrency(grandTotal) }}</td>
                                <td v-if="rowsEditable"></td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>

                    <div class="mt-4">
                        <label class="block text-xs font-semibold uppercase tracking-wide text-steel">Ghi chú</label>
                        <textarea v-model="note" rows="2" :disabled="!rowsEditable" class="mt-1 w-full border border-gray-300 px-3 py-2 text-sm" />
                    </div>

                    <p v-if="errorMessage" class="mt-3 text-sm text-coral">{{ errorMessage }}</p>
                </div>

                <!-- Footer sits outside the scrollable content area (fixed height, not sticky)
                     so it's always reachable and never scrolls out of view or under a keyboard. -->
                <div v-if="!isCompleted" class="flex flex-col gap-2 border-t border-gray-200 p-4 sm:flex-row sm:flex-wrap sm:justify-end">
                    <button type="button" class="min-h-11 border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" :disabled="processing" @click="saveDraft">
                        Lưu nháp
                    </button>
                    <button type="button" class="min-h-11 border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" :disabled="processing" @click="complete(true)">
                        Xác nhận không phát sinh
                    </button>
                    <button type="button" class="min-h-11 border border-pine bg-pine px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="processing" @click="complete(false)">
                        Hoàn tất kiểm đồ
                    </button>
                </div>
                <div v-else-if="editingCompleted" class="flex flex-col gap-2 border-t border-gray-200 p-4 sm:flex-row sm:flex-wrap sm:justify-end">
                    <button type="button" class="min-h-11 border border-gray-300 px-3 py-2 text-sm font-semibold text-steel hover:text-ink" :disabled="processing" @click="cancelEditCompleted">
                        Hủy
                    </button>
                    <button type="button" class="min-h-11 border border-pine bg-pine px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="processing" @click="saveCompletedEdit">
                        Lưu thay đổi
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
