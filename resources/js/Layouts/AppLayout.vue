<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import {
    BedDouble,
    Brush,
    CalendarCheck,
    ClipboardCheck,
    ClipboardList,
    DollarSign,
    Gauge,
    KeyRound,
    Layers,
    LayoutGrid,
    LogOut,
    Menu,
    Moon,
    PackagePlus,
    Scale,
    Search,
    Settings,
    Shield,
    Tags,
    TrendingUp,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage();

const user = computed(() => page.props.auth.user);
const permissions = computed(() => new Set(user.value?.permissions ?? []));

const can = (permission) => permissions.value.has(permission);

const navItems = computed(() => [
    { label: 'Tổng quan', href: '/dashboard', icon: Gauge, show: true },
    { label: 'Đặt phòng', href: '/admin/bookings', icon: CalendarCheck, show: can('booking.create') || can('booking.update') || can('booking.cancel') || can('report.view') },
    { label: 'Kiểm tra phòng', href: '/admin/room-availability', icon: Search, show: can('room_availability.view') },
    { label: 'Sơ đồ thao tác', href: '/admin/room-operations', icon: LayoutGrid, show: can('room.assign') || can('stay.checkin') || can('stay.checkout') || can('housekeeping.view') || can('checkout_inspection.view') },
    { label: 'Dọn phòng', href: '/admin/housekeeping', icon: Brush, show: can('housekeeping.view') },
    { label: 'Kiểm đồ trả phòng', href: '/admin/checkout-inspections', icon: ClipboardCheck, show: can('checkout_inspection.view') },
    { label: 'Night Audit', href: '/admin/night-audit', icon: Moon, show: can('night_audit.view') },
    { label: 'Doanh thu', href: '/admin/reports/revenue', icon: TrendingUp, show: can('revenue.view') },
    { label: 'Đối soát', href: '/admin/reconciliation', icon: Scale, show: can('reconciliation.view') },
    { label: 'Người dùng', href: '/users', icon: Users, show: can('users.manage') },
    { label: 'Vai trò', href: '/roles', icon: Shield, show: can('roles.manage') },
    { label: 'Quyền', href: '/permissions', icon: KeyRound, show: can('roles.manage') },
    { label: 'Tầng', href: '/floors', icon: Layers, show: can('rooms.manage') },
    { label: 'Loại phòng', href: '/room-types', icon: Tags, show: can('room_types.manage') },
    { label: 'Phòng', href: '/rooms', icon: BedDouble, show: can('rooms.manage') },
    { label: 'Giá phòng', href: '/room-rates', icon: DollarSign, show: can('rates.manage') },
    { label: 'Dịch vụ & Yêu cầu', href: '/admin/services', icon: DollarSign, show: can('services.manage') },
    { label: 'Gói dịch vụ', href: '/admin/service-packages', icon: DollarSign, show: can('service_packages.manage') },
    { label: 'Sản phẩm/Dịch vụ', href: '/product-services', icon: PackagePlus, show: can('product_services.manage') },
    { label: 'Cài đặt', href: '/settings', icon: Settings, show: can('settings.manage') },
    { label: 'Nhật ký', href: '/audit-logs', icon: ClipboardList, show: can('report.view') },
].filter((item) => item.show));

const currentPath = computed(() => new URL(page.url, window.location.origin).pathname);

// Mobile nav: the sidebar is `hidden lg:block`, so below lg there was previously no way
// to navigate at all on a phone. This drawer is purely additive (lg:hidden trigger, only
// rendered when opened) and does not alter desktop layout.
const mobileNavOpen = ref(false);
watch(() => page.url, () => { mobileNavOpen.value = false; });
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-ink">
        <aside class="fixed inset-y-0 left-0 hidden w-64 border-r border-gray-200 bg-white lg:block">
            <div class="flex h-16 items-center border-b border-gray-200 px-6">
                <div>
                    <div class="text-base font-semibold">Lastella PMS</div>
                    <div class="text-xs uppercase tracking-wide text-steel">Hệ thống lõi</div>
                </div>
            </div>

            <nav class="space-y-1 px-3 py-4">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 px-3 py-2 text-sm font-medium transition"
                    :class="currentPath.startsWith(item.href) ? 'bg-linen text-pine' : 'text-steel hover:bg-gray-50 hover:text-ink'"
                >
                    <component :is="item.icon" class="h-4 w-4" />
                    <span>{{ item.label }}</span>
                </Link>
            </nav>
        </aside>

        <!-- Mobile nav drawer (lg:hidden trigger below) — desktop is untouched -->
        <div v-if="mobileNavOpen" class="fixed inset-0 z-40 lg:hidden">
            <div class="fixed inset-0 bg-black/40" @click="mobileNavOpen = false" />
            <aside class="fixed inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-white shadow-xl">
                <div class="flex h-16 shrink-0 items-center justify-between border-b border-gray-200 px-4">
                    <div>
                        <div class="text-base font-semibold">Lastella PMS</div>
                        <div class="text-xs uppercase tracking-wide text-steel">Hệ thống lõi</div>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-11 w-11 items-center justify-center text-steel hover:text-ink"
                        aria-label="Đóng menu"
                        @click="mobileNavOpen = false"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="flex min-h-11 items-center gap-3 px-3 py-2.5 text-sm font-medium transition"
                        :class="currentPath.startsWith(item.href) ? 'bg-linen text-pine' : 'text-steel hover:bg-gray-50 hover:text-ink'"
                        @click="mobileNavOpen = false"
                    >
                        <component :is="item.icon" class="h-5 w-5 shrink-0" />
                        <span>{{ item.label }}</span>
                    </Link>
                </nav>
            </aside>
        </div>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 border-b border-gray-200 bg-white">
                <div class="flex min-h-16 items-center justify-between gap-3 px-4 py-2 sm:h-16 sm:py-0 sm:px-6 lg:px-8">
                    <button
                        type="button"
                        class="inline-flex h-11 w-11 shrink-0 items-center justify-center border border-gray-200 text-steel lg:hidden"
                        aria-label="Mở menu"
                        @click="mobileNavOpen = true"
                    >
                        <Menu class="h-5 w-5" />
                    </button>

                    <div class="min-w-0 flex-1">
                        <slot name="header" />
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="hidden text-right sm:block">
                            <div class="text-sm font-medium">{{ user?.name }}</div>
                            <div class="text-xs text-steel">{{ user?.email }}</div>
                        </div>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            class="inline-flex h-9 w-9 items-center justify-center border border-gray-200 bg-white text-steel transition hover:border-coral hover:text-coral"
                            title="Đăng xuất"
                        >
                            <LogOut class="h-4 w-4" />
                        </Link>
                    </div>
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <div v-if="page.props.flash.success" class="mb-4 border-l-4 border-pine bg-white px-4 py-3 text-sm text-pine shadow-sm">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash.error" class="mb-4 border-l-4 border-coral bg-white px-4 py-3 text-sm text-coral shadow-sm">
                    {{ page.props.flash.error }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
