<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { Save } from 'lucide-vue-next'

interface SettingEntry {
    value: string
    type: string
    description: string
    updated_by: string | null
    updated_at: string | null
}

const props = defineProps<{
    settings: Record<string, SettingEntry>
}>()

const groups: Record<string, string[]> = {
    'Ngày kế toán & Kiểm toán đêm': [
        'business_date_offset_hours',
        'night_audit_window_start',
        'night_audit_window_end',
        'night_audit_require_sequential',
    ],
    'Phí nhận/trả phòng': [
        'late_checkout_grace_minutes',
        'early_checkin_grace_minutes',
    ],
    'Tiền tệ': [
        'currency_code',
        'currency_precision',
    ],
}

const labels: Record<string, string> = {
    business_date_offset_hours:     'Giờ offset ngày kế toán',
    night_audit_window_start:       'Cửa sổ kiểm toán — bắt đầu',
    night_audit_window_end:         'Cửa sổ kiểm toán — kết thúc',
    night_audit_require_sequential: 'Yêu cầu tuần tự',
    late_checkout_grace_minutes:    'Ân hạn trả phòng muộn (phút)',
    early_checkin_grace_minutes:    'Ân hạn nhận phòng sớm (phút)',
    currency_code:                  'Mã tiền tệ',
    currency_precision:             'Số thập phân tiền tệ',
}

const initialSettings = Object.entries(props.settings).map(([key, entry]) => ({
    key,
    value: entry.value,
}))

const form = useForm({ settings: initialSettings })

const submit = () => {
    form.patch(route('admin.hotel-settings.update'), {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout>
        <Head title="Cài đặt khách sạn" />

        <div class="mx-auto max-w-3xl px-4 py-8">
            <h1 class="mb-6 text-2xl font-bold text-gray-900">Cài đặt khách sạn</h1>

            <form @submit.prevent="submit" class="space-y-8">
                <div v-for="(keys, groupName) in groups" :key="groupName" class="rounded border border-gray-200 bg-white p-6">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ groupName }}</h2>

                    <div class="space-y-4">
                        <div v-for="(item, index) in form.settings.filter(s => keys.includes(s.key))" :key="item.key">
                            <label class="block text-sm font-medium text-gray-700">
                                {{ labels[item.key] ?? item.key }}
                            </label>
                            <p class="mb-1 text-xs text-gray-400">{{ settings[item.key]?.description }}</p>
                            <input
                                v-model="item.value"
                                type="text"
                                class="mt-1 w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                            />
                            <p v-if="settings[item.key]?.updated_by" class="mt-1 text-xs text-gray-400">
                                Cập nhật bởi {{ settings[item.key].updated_by }} lúc {{ settings[item.key].updated_at }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="flex items-center gap-2 rounded bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                    >
                        <Save :size="16" />
                        Lưu cài đặt
                    </button>
                </div>

                <p v-if="form.errors['settings']" class="text-sm text-red-600">{{ form.errors['settings'] }}</p>
            </form>
        </div>
    </AppLayout>
</template>
