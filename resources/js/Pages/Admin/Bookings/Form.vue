<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { labelFor } from '@/Support/vietnameseLabels';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';

const props = defineProps({
    booking: { type: Object, default: null },
    action: { type: String, required: true },
    method: { type: String, required: true },
    options: { type: Object, required: true },
});

const form = useForm({
    customer_name: props.booking?.customer_name ?? '',
    customer_phone: props.booking?.customer_phone ?? '',
    customer_email: props.booking?.customer_email ?? '',
    customer_type: props.booking?.customer_type ?? 'INDIVIDUAL',
    booking_type: props.booking?.booking_type ?? 'OVERNIGHT',
    checkin_at: props.booking?.checkin_at ?? '',
    checkout_at: props.booking?.checkout_at ?? '',
    adults: props.booking?.adults ?? 1,
    children_under_6: props.booking?.children_under_6 ?? 0,
    children_over_6: props.booking?.children_over_6 ?? 0,
    booking_color: props.booking?.booking_color ?? '#196251',
    sales_user_id: props.booking?.sales_user_id ?? '',
    note: props.booking?.note ?? '',
    internal_note: props.booking?.internal_note ?? '',
});

const submit = () => form[props.method](props.action);
</script>

<template>
    <Head :title="booking ? 'Sửa đặt phòng' : 'Tạo đặt phòng'" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-lg font-semibold">{{ booking ? 'Sửa đặt phòng' : 'Tạo đặt phòng' }}</h1>
                <Link :href="booking ? `/admin/bookings/${booking.id}` : '/admin/bookings'" class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-steel hover:text-ink">
                    <ArrowLeft class="h-4 w-4" />
                    Quay lại
                </Link>
            </div>
        </template>

        <form class="max-w-5xl border border-gray-200 bg-white p-5 shadow-sm" @submit.prevent="submit">
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium">Tên khách hàng</label>
                    <input v-model="form.customer_name" type="text" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <p v-if="form.errors.customer_name" class="mt-1 text-sm text-coral">{{ form.errors.customer_name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Số điện thoại</label>
                    <input v-model="form.customer_phone" type="text" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Email khách hàng</label>
                    <input v-model="form.customer_email" type="email" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <p v-if="form.errors.customer_email" class="mt-1 text-sm text-coral">{{ form.errors.customer_email }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Loại khách hàng</label>
                    <select v-model="form.customer_type" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                        <option v-for="option in options.customerTypes" :key="option.value" :value="option.value">{{ labelFor('customerType', option.value) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Loại đặt phòng</label>
                    <select v-model="form.booking_type" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                        <option v-for="option in options.bookingTypes" :key="option.value" :value="option.value">{{ labelFor('bookingType', option.value) }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Màu đặt phòng</label>
                    <input v-model="form.booking_color" type="color" class="mt-2 h-10 w-full border border-gray-300 px-2 py-1">
                    <p v-if="form.errors.booking_color" class="mt-1 text-sm text-coral">{{ form.errors.booking_color }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Thời gian nhận phòng</label>
                    <input v-model="form.checkin_at" type="datetime-local" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <p v-if="form.errors.checkin_at" class="mt-1 text-sm text-coral">{{ form.errors.checkin_at }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Thời gian trả phòng</label>
                    <input v-model="form.checkout_at" type="datetime-local" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                    <p v-if="form.errors.checkout_at" class="mt-1 text-sm text-coral">{{ form.errors.checkout_at }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium">Người lớn</label>
                    <input v-model="form.adults" type="number" min="1" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Trẻ dưới 6 tuổi</label>
                    <input v-model="form.children_under_6" type="number" min="0" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Trẻ trên 6 tuổi</label>
                    <input v-model="form.children_over_6" type="number" min="0" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                </div>
                <div>
                    <label class="block text-sm font-medium">Nhân viên kinh doanh</label>
                    <select v-model="form.sales_user_id" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine">
                        <option value="">Chưa phân công</option>
                        <option v-for="user in options.salesUsers" :key="user.value" :value="user.value">{{ user.label }}</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium">Ghi chú</label>
                    <textarea v-model="form.note" rows="3" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium">Ghi chú nội bộ</label>
                    <textarea v-model="form.internal_note" rows="3" class="mt-2 w-full border border-gray-300 px-3 py-2 text-sm focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-5">
                <Link :href="booking ? `/admin/bookings/${booking.id}` : '/admin/bookings'" class="inline-flex items-center border border-gray-300 px-4 py-2 text-sm font-semibold text-steel hover:text-ink">
                    Hủy
                </Link>
                <button type="submit" class="inline-flex items-center gap-2 bg-pine px-4 py-2 text-sm font-semibold text-white hover:bg-ink" :disabled="form.processing">
                    <Save class="h-4 w-4" />
                    Lưu
                </button>
            </div>
        </form>
    </AppLayout>
</template>
