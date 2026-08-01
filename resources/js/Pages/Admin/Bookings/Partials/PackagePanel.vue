<script setup>
import { router, usePage, useForm } from '@inertiajs/vue3';
import { Coffee, Plus, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps({
    bookingId: { type: Number, required: true },
    packageFlags: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const page = usePage();

const packageError = computed(() => page.props.errors?.package ?? null);

const PACKAGE_LABELS = {
    BREAKFAST_PER_NIGHT: 'Ăn sáng mỗi đêm',
};

const packageLabel = (key) => PACKAGE_LABELS[key] ?? key;

const isEnrolled = (packageKey) => props.packageFlags.some((f) => f.package_key === packageKey);

const canEnrollBreakfast = computed(() => props.canManage && !isEnrolled('BREAKFAST_PER_NIGHT'));

const enrollForm = useForm({ package_key: 'BREAKFAST_PER_NIGHT' });

const enroll = () => {
    enrollForm.post(`/admin/bookings/${props.bookingId}/packages`, {
        preserveScroll: true,
    });
};

const unenroll = (packageKey) => {
    router.delete(`/admin/bookings/${props.bookingId}/packages/${packageKey}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="border border-gray-100 p-4">
        <div class="mb-3 flex items-center gap-2">
            <Coffee class="h-4 w-4 text-steel" />
            <span class="text-xs font-semibold uppercase tracking-wide text-steel">Gói dịch vụ</span>
        </div>

        <div v-if="packageError" class="mb-3 border-l-4 border-coral bg-coral/5 px-3 py-2 text-sm text-coral">
            {{ packageError }}
        </div>

        <div v-if="packageFlags.length > 0" class="mb-3 flex flex-wrap gap-2">
            <div
                v-for="flag in packageFlags"
                :key="flag.package_key"
                class="inline-flex items-center gap-1.5 border border-pine/30 bg-pine/5 px-2.5 py-1 text-sm font-medium text-pine"
            >
                <Coffee class="h-3.5 w-3.5 shrink-0" />
                {{ packageLabel(flag.package_key) }}
                <button
                    v-if="canManage"
                    type="button"
                    class="ml-0.5 inline-flex h-4 w-4 items-center justify-center rounded-full text-pine/60 hover:bg-pine/10 hover:text-pine"
                    :title="`Hủy ${packageLabel(flag.package_key)}`"
                    @click="unenroll(flag.package_key)"
                >
                    <X class="h-3 w-3" />
                </button>
            </div>
        </div>

        <p v-else class="mb-3 text-sm text-steel">Chưa đăng ký gói dịch vụ nào.</p>

        <form v-if="canEnrollBreakfast" class="flex items-center gap-2" @submit.prevent="enroll">
            <span class="text-sm text-steel">Ăn sáng mỗi đêm</span>
            <button
                type="submit"
                class="inline-flex items-center gap-1 bg-pine px-3 py-1.5 text-xs font-semibold text-white disabled:bg-gray-300"
                :disabled="enrollForm.processing"
            >
                <Plus class="h-3.5 w-3.5" />
                Đăng ký
            </button>
        </form>
    </div>
</template>
