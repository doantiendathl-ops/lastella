<script setup>
// User request (2026-08-18 chat) — "tất cả các phần điền số tiền... khi điền
// và khi hiển thị đều có số phân chia hàng nghìn": a drop-in replacement for
// `<input type="number">` on every money field site-wide. v-model carries
// the RAW numeric value (or null when empty) — exactly what every existing
// form already sends to the backend — while the input itself always shows
// the vi-VN thousand-separated string as the user types, matching
// Support/format.js's formatMoney() convention used for display everywhere
// else. Attribute fallthrough (id/required/disabled/placeholder/class) works
// the same as a plain <input>, so this can replace one 1:1 in most forms.
import { parseMoneyInput } from '@/Support/format';
import { computed, nextTick } from 'vue';

const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
});

const emit = defineEmits(['update:modelValue']);

const displayValue = computed(() => {
    if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') return '';

    return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(props.modelValue) || 0);
});

// Reformatting on every keystroke re-inserts "." separators, which shifts
// where the caret should sit — count digits typed before the caret (not raw
// characters, so a "." doesn't throw the count off) and restore the caret
// after the same digit count post-format, instead of letting it default to
// the end of the field.
function onInput(event) {
    const el = event.target;
    const caretPos = el.selectionStart ?? el.value.length;
    const digitsBeforeCaret = el.value.slice(0, caretPos).replace(/\D/g, '').length;

    const raw = parseMoneyInput(el.value);
    emit('update:modelValue', raw);

    nextTick(() => {
        const formatted = raw === null ? '' : new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(raw);
        el.value = formatted;

        let digitCount = 0;
        let caret = formatted.length;
        for (let i = 0; i < formatted.length; i++) {
            if (/\d/.test(formatted[i])) digitCount++;
            if (digitCount === digitsBeforeCaret) {
                caret = i + 1;
                break;
            }
        }
        el.setSelectionRange(caret, caret);
    });
}
</script>

<template>
    <input
        type="text"
        inputmode="numeric"
        autocomplete="off"
        :value="displayValue"
        @input="onInput"
    >
</template>
