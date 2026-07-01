<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    entry: {
        id: number
        description: string
    }
    bookingId: number
}>()

const emit = defineEmits<{
    close: []
}>()

const voidReason = ref('')
const isSubmitting = ref(false)

const canConfirm = () => voidReason.value.trim().length >= 5 && !isSubmitting.value

const confirm = () => {
    if (!canConfirm()) return
    isSubmitting.value = true
    router.patch(
        `/admin/bookings/${props.bookingId}/folio/entries/${props.entry.id}`,
        { void_reason: voidReason.value },
        {
            preserveScroll: true,
            onSuccess: () => emit('close'),
            onFinish: () => { isSubmitting.value = false },
        },
    )
}
</script>

<template>
    <div class="mt-2 border border-coral/30 bg-coral/5 p-3">
        <div class="mb-2 text-xs font-semibold text-coral">Huỷ mục phí: {{ entry.description }}</div>
        <textarea
            v-model="voidReason"
            rows="2"
            placeholder="Lý do huỷ (tối thiểu 5 ký tự)..."
            class="w-full border border-gray-300 px-2 py-1.5 text-sm"
        />
        <div class="mt-2 flex justify-end gap-2">
            <button type="button" class="border border-gray-300 px-3 py-1.5 text-xs font-semibold text-steel hover:text-ink" @click="emit('close')">Hủy</button>
            <button
                type="button"
                class="inline-flex items-center gap-1 border border-coral bg-coral px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                :disabled="!canConfirm()"
                @click="confirm"
            >
                <XCircle class="h-3.5 w-3.5" />
                Xác nhận huỷ
            </button>
        </div>
    </div>
</template>
