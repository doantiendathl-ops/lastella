<script setup>
// docs/Prompt_1.txt mục V — Excel-style Booking Color Picker.
//
// Layout mirrors Excel's color dropdown conceptually (Theme Colors row with
// vertical shades, Standard Colors row, custom color) without copying its
// pixel-perfect chrome. Palette groups/hex values come from the server
// (BookingColorService) — this component only renders + compares, it never
// invents colors itself, so the palette stays a single canonical source.
//
// No "Không tô màu / No Fill" option: booking_color is a required field
// (StoreBookingRequest/UpdateBookingRequest) — the legacy system never
// allowed an uncolored Booking either, so a Booking must stay identifiable
// on the Room Map at all times (mục V.1.C — backward-compatible choice).
import { readableTextClass } from '@/Support/colorContrast';
import { Check } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, required: true },
    themeGroups: { type: Array, required: true },
    standardColors: { type: Array, required: true },
    usedColors: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const normalize = (hex) => (hex ?? '').toUpperCase();

const usedSet = computed(() => new Set(props.usedColors.map(normalize)));
const isSelected = (hex) => normalize(hex) === normalize(props.modelValue);
const isConflicting = (hex) => usedSet.value.has(normalize(hex));

const select = (hex) => {
    if (isConflicting(hex)) return; // mục VI — ưu tiên ngăn lựa chọn cho palette có sẵn
    emit('update:modelValue', normalize(hex));
};

// mục V.4 — the checkmark icon sits directly on the swatch's own fill, so
// it needs the same auto-contrast treatment as text/icons on a booking-
// colored Room Tile: a hard-coded white check disappears on the white/
// near-white swatches this exact picker offers.
const checkmarkClass = (hex) => readableTextClass(hex);

// mục V.5 — near-white/pale shades must stay visible even when not
// selected/hovered: a fully transparent default border lets a white or
// near-white swatch disappear into the picker's own light background.
// A subtle neutral border keeps every swatch's edge readable regardless
// of how close its fill is to the surrounding surface.
const swatchClass = (hex) => {
    if (isConflicting(hex)) {
        return 'cursor-not-allowed border-gray-200 opacity-30';
    }
    return isSelected(hex)
        ? 'scale-110 border-ink shadow-sm'
        : 'border-gray-300 hover:scale-105 hover:border-gray-500';
};

// Custom color ("Màu khác...") — hex text input paired with a native
// swatch picker, per mục V.1.D. Kept in sync both ways with modelValue.
const customHex = ref(props.modelValue);
watch(() => props.modelValue, (val) => { customHex.value = val; });

const HEX_PATTERN = /^#[0-9A-Fa-f]{6}$/;
const customHexError = computed(() => (HEX_PATTERN.test(customHex.value) ? '' : 'Mã màu phải theo định dạng #RRGGBB.'));
const customHexConflict = computed(() => HEX_PATTERN.test(customHex.value) && isConflicting(customHex.value));

const applyCustomHex = () => {
    if (HEX_PATTERN.test(customHex.value)) {
        emit('update:modelValue', normalize(customHex.value));
    }
};
</script>

<template>
    <div class="space-y-4">
        <!-- A. Theme Colors -->
        <div>
            <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-steel">Theme Colors</div>
            <div class="flex gap-1.5 overflow-x-auto pb-1">
                <div v-for="group in themeGroups" :key="group.label" class="flex shrink-0 flex-col gap-1">
                    <button
                        type="button"
                        class="relative h-7 w-7 rounded-sm border-2 transition"
                        :class="swatchClass(group.base)"
                        :style="{ backgroundColor: group.base }"
                        :title="isConflicting(group.base) ? `${group.label} — đang dùng bởi booking khác cùng thời gian` : group.label"
                        :disabled="isConflicting(group.base)"
                        @click="select(group.base)"
                    >
                        <Check v-if="isSelected(group.base)" class="absolute inset-0 m-auto h-3.5 w-3.5 drop-shadow" :class="checkmarkClass(group.base)" />
                    </button>
                    <button
                        v-for="shade in group.shades"
                        :key="shade"
                        type="button"
                        class="relative h-5 w-7 border transition"
                        :class="swatchClass(shade)"
                        :style="{ backgroundColor: shade }"
                        :title="isConflicting(shade) ? 'Đang dùng bởi booking khác cùng thời gian' : shade"
                        :disabled="isConflicting(shade)"
                        @click="select(shade)"
                    >
                        <Check v-if="isSelected(shade)" class="absolute inset-0 m-auto h-3 w-3 drop-shadow" :class="checkmarkClass(shade)" />
                    </button>
                </div>
            </div>
        </div>

        <!-- B. Standard Colors -->
        <div>
            <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-steel">Standard Colors</div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="hex in standardColors"
                    :key="hex"
                    type="button"
                    class="relative h-8 w-8 rounded-sm border-2 transition"
                    :class="swatchClass(hex)"
                    :style="{ backgroundColor: hex }"
                    :title="isConflicting(hex) ? `${hex} — đang dùng bởi booking khác cùng thời gian` : hex"
                    :disabled="isConflicting(hex)"
                    @click="select(hex)"
                >
                    <Check v-if="isSelected(hex)" class="absolute inset-0 m-auto h-3.5 w-3.5 drop-shadow" :class="checkmarkClass(hex)" />
                </button>
            </div>
        </div>

        <!-- D. Màu khác... -->
        <div>
            <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-steel">Màu khác...</div>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    v-model="customHex"
                    type="color"
                    class="h-8 w-10 cursor-pointer border border-gray-300 px-1 py-0.5"
                    @change="applyCustomHex"
                >
                <input
                    v-model="customHex"
                    type="text"
                    maxlength="7"
                    placeholder="#RRGGBB"
                    class="w-28 border border-gray-300 px-2 py-1.5 font-mono text-xs uppercase focus:border-pine focus:outline-none focus:ring-1 focus:ring-pine"
                    @change="applyCustomHex"
                    @keydown.enter.prevent="applyCustomHex"
                >
                <span class="text-xs text-steel">Màu đang chọn: <span class="font-mono uppercase">{{ modelValue }}</span></span>
            </div>
            <p v-if="customHexConflict" class="mt-1 text-xs text-coral">
                Màu này đang được dùng bởi một booking khác có thời gian chiếm phòng trùng lặp. Hệ thống sẽ từ chối khi lưu — vui lòng chọn màu khác.
            </p>
        </div>
    </div>
</template>
