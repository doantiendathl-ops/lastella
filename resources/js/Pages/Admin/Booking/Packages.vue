<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ChevronLeft, Info, Package } from 'lucide-vue-next'
import { computed, ref } from 'vue'

interface EnrollmentStatus {
    enrolled: boolean
    quantity: number
    enrolled_at: string | null
    enrolled_by: string | null
}

interface AvailablePackage {
    key: string
    label: string
    charge_label: string
    current_rate: number | null
}

interface AuditLog {
    job_class: string
    result: string
    posted_at: string
}

const props = defineProps<{
    booking: {
        id: number
        booking_code: string
        customer_name: string
        status: string
    }
    enrollments: Record<string, EnrollmentStatus>
    available_packages: AvailablePackage[]
    last_audit_logs: AuditLog[]
    city_tax_enabled: boolean
    can: { manage_packages: boolean }
}>()

const page = usePage()

const BOOKING_STATUS_LABELS: Record<string, string> = {
    DRAFT: 'Nháp',
    PENDING_ASSIGNMENT: 'Chờ phân phòng',
    PARTIALLY_ASSIGNED: 'Phân một phần',
    FULLY_ASSIGNED: 'Đã phân đủ',
    HELD: 'Giữ chỗ',
    DEPOSITED: 'Đã đặt cọc',
    PARTIALLY_CHECKED_IN: 'Nhận phòng một phần',
    CHECKED_IN: 'Đang lưu trú',
    PARTIALLY_CHECKED_OUT: 'Trả phòng một phần',
    CHECKED_OUT: 'Đã trả phòng',
    CANCELLED: 'Đã hủy',
    NO_SHOW: 'Không đến',
}

const RESULT_CLASSES: Record<string, string> = {
    POSTED:        'bg-pine/10 text-pine',
    ALREADY_POSTED: 'bg-gray-100 text-gray-600',
    SKIPPED:       'bg-amber-50 text-amber-700',
    FAILED:        'bg-coral/10 text-coral',
}

const RESULT_LABELS: Record<string, string> = {
    POSTED:        'Đã ghi phí',
    ALREADY_POSTED: 'Đã ghi trước đó',
    SKIPPED:       'Bỏ qua',
    FAILED:        'Lỗi',
}

const JOB_CLASS_FOR_PACKAGE: Record<string, string> = {
    BREAKFAST_PER_NIGHT:    'App\\Services\\Posting\\BreakfastPostingJob',
    EXTRA_PERSON_PER_NIGHT: 'App\\Services\\Posting\\ExtraPersonPostingJob',
    EXTRA_BED_PER_NIGHT:    'App\\Services\\Posting\\ExtraBedPostingJob',
}

const formatCurrency = (value: number | null): string => {
    if (value === null) return 'Chưa có giá'
    return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(value)} đ`
}

const quantities = ref<Record<string, number>>(
    Object.fromEntries(props.available_packages.map((pkg) => [pkg.key, 1]))
)

const flashSuccess = computed(() => (page.props.flash as Record<string, string> | null)?.success ?? null)
const packageError  = computed(() => (page.props.errors as Record<string, string> | null)?.package ?? null)

const auditLogFor = (packageKey: string): AuditLog | undefined => {
    const jobClass = JOB_CLASS_FOR_PACKAGE[packageKey]
    return props.last_audit_logs.find((log) => log.job_class === jobClass)
}

const quantityEditable = (packageKey: string): boolean =>
    packageKey === 'EXTRA_PERSON_PER_NIGHT' || packageKey === 'EXTRA_BED_PER_NIGHT'

const enroll = (packageKey: string): void => {
    router.post(
        route('admin.bookings.packages.enroll', props.booking.id),
        { package_key: packageKey, quantity: quantities.value[packageKey] ?? 1 },
        { preserveScroll: true }
    )
}

const unenroll = (packageKey: string): void => {
    router.delete(
        route('admin.bookings.packages.unenroll', { booking: props.booking.id, packageKey }),
        { preserveScroll: true }
    )
}
</script>

<template>
    <AppLayout>
        <Head :title="`Gói dịch vụ – ${booking.booking_code}`" />

        <div class="mx-auto max-w-4xl">
            <!-- Back link + header -->
            <div class="mb-6">
                <div class="mb-1">
                    <Link
                        :href="route('admin.bookings.show', booking.id)"
                        class="inline-flex items-center gap-1 text-sm text-steel hover:text-ink"
                    >
                        <ChevronLeft class="h-4 w-4" aria-hidden="true" />
                        Đặt phòng
                    </Link>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">Gói dịch vụ</h1>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-steel">
                    <span class="font-mono font-semibold text-ink">{{ booking.booking_code }}</span>
                    <span>·</span>
                    <span>{{ booking.customer_name }}</span>
                    <span>·</span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                        {{ BOOKING_STATUS_LABELS[booking.status] ?? booking.status }}
                    </span>
                </div>
            </div>

            <!-- Read-only permission notice -->
            <div
                v-if="!can.manage_packages"
                class="mb-5 flex items-center gap-2 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800"
            >
                <Info class="h-4 w-4 flex-shrink-0" aria-hidden="true" />
                Chỉ xem — không có quyền chỉnh sửa gói dịch vụ
            </div>

            <!-- Flash success -->
            <div
                v-if="flashSuccess"
                class="mb-5 rounded border border-pine/30 bg-pine/5 px-4 py-3 text-sm font-medium text-pine"
            >
                {{ flashSuccess }}
            </div>

            <!-- Package error (unenroll guard) -->
            <div
                v-if="packageError"
                class="mb-5 rounded border border-coral/30 bg-coral/5 px-4 py-3 text-sm font-medium text-coral"
            >
                {{ packageError }}
            </div>

            <!-- Package cards -->
            <div class="space-y-4">
                <div
                    v-for="pkg in available_packages"
                    :key="pkg.key"
                    class="rounded border border-gray-200 bg-white"
                >
                    <!-- Card header -->
                    <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <Package class="h-5 w-5 shrink-0 text-steel" aria-hidden="true" />
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-900">{{ pkg.label }}</div>
                                <div class="mt-0.5 text-xs text-steel">{{ pkg.charge_label }}</div>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div
                                class="text-sm font-semibold"
                                :class="pkg.current_rate !== null ? 'text-ink' : 'text-gray-400'"
                            >
                                {{ formatCurrency(pkg.current_rate) }}
                            </div>
                            <div v-if="pkg.current_rate !== null" class="mt-0.5 text-xs text-steel">/ đêm</div>
                        </div>
                    </div>

                    <!-- Card body -->
                    <div class="px-5 py-4">
                        <template v-if="enrollments[pkg.key]?.enrolled">
                            <!-- Enrolled state -->
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-pine">
                                    <span class="inline-block h-2 w-2 rounded-full bg-pine" />
                                    Đã đăng ký
                                </span>
                                <span class="text-sm text-gray-700">
                                    {{ enrollments[pkg.key].quantity }}
                                    {{ pkg.key === 'EXTRA_PERSON_PER_NIGHT' ? 'người' : pkg.key === 'EXTRA_BED_PER_NIGHT' ? 'giường' : '' }}
                                </span>
                                <span v-if="enrollments[pkg.key].enrolled_at" class="text-xs text-steel">
                                    Đăng ký: {{ enrollments[pkg.key].enrolled_at }}
                                </span>
                            </div>

                            <div v-if="enrollments[pkg.key].enrolled_by" class="mt-1.5 text-xs text-steel">
                                Đăng ký bởi: <span class="font-medium text-gray-700">{{ enrollments[pkg.key].enrolled_by }}</span>
                            </div>

                            <!-- Last audit log -->
                            <div v-if="auditLogFor(pkg.key)" class="mt-2.5 flex items-center gap-2 text-xs text-steel">
                                <span>Lần ghi phí cuối:</span>
                                <span class="font-mono text-gray-700">{{ auditLogFor(pkg.key)!.posted_at }}</span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="RESULT_CLASSES[auditLogFor(pkg.key)!.result] ?? 'bg-gray-100 text-gray-600'"
                                >
                                    {{ RESULT_LABELS[auditLogFor(pkg.key)!.result] ?? auditLogFor(pkg.key)!.result }}
                                </span>
                            </div>

                            <!-- Unenroll button -->
                            <div v-if="can.manage_packages" class="mt-4">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 border border-gray-300 px-3 py-1.5 text-xs font-semibold text-steel hover:border-coral hover:text-coral"
                                    @click="unenroll(pkg.key)"
                                >
                                    Hủy đăng ký
                                </button>
                            </div>
                        </template>

                        <template v-else>
                            <!-- Not enrolled state -->
                            <div class="flex items-center gap-1.5 text-sm text-steel">
                                <span class="inline-block h-2 w-2 rounded-full bg-gray-300" />
                                Chưa đăng ký
                            </div>

                            <!-- Enroll form -->
                            <div v-if="can.manage_packages" class="mt-4 flex items-center gap-3">
                                <template v-if="quantityEditable(pkg.key)">
                                    <label class="flex items-center gap-2 text-xs text-steel">
                                        <span class="font-semibold uppercase tracking-wide">Số lượng</span>
                                        <input
                                            v-model.number="quantities[pkg.key]"
                                            type="number"
                                            min="1"
                                            max="4"
                                            class="w-16 border border-gray-300 px-2 py-1 text-sm text-center focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                                        />
                                    </label>
                                </template>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 bg-pine px-3 py-1.5 text-xs font-semibold text-white hover:bg-pine/90 disabled:cursor-not-allowed disabled:bg-gray-300"
                                    :disabled="pkg.current_rate === null"
                                    :title="pkg.current_rate === null ? 'Chưa có biểu giá cho gói này' : undefined"
                                    @click="enroll(pkg.key)"
                                >
                                    Đăng ký
                                </button>
                                <span v-if="pkg.current_rate === null" class="text-xs text-amber-600">
                                    Chưa có biểu giá — không thể đăng ký
                                </span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- City tax informational section -->
            <div class="mt-6 rounded border border-gray-200 bg-gray-50 px-5 py-4">
                <div class="flex items-start gap-3">
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-steel" aria-hidden="true" />
                    <div>
                        <div class="text-sm font-semibold text-gray-800">Thuế du lịch</div>
                        <div class="mt-1 text-xs text-steel">
                            Tự động áp dụng theo cài đặt khách sạn.
                            Không thể điều chỉnh theo từng đặt phòng.
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-xs text-steel">Trạng thái:</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="city_tax_enabled ? 'bg-pine/10 text-pine' : 'bg-gray-100 text-gray-500'"
                            >
                                {{ city_tax_enabled ? 'Đang bật' : 'Tắt' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
