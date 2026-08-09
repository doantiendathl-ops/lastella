<script setup>
import { Link } from '@inertiajs/vue3';
import { MoreHorizontal, X } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    actions: { type: Array, required: true }, // pre-filtered to visible:true by the caller
    bookingCode: { type: String, required: true },
    isMobile: { type: Boolean, required: true },
    open: { type: Boolean, required: true },
});

const emit = defineEmits(['open', 'close']);

const triggerRef = ref(null);
const menuRef = ref(null);
const position = ref({ top: 0, left: 0 });

function toggle() {
    emit(props.open ? 'close' : 'open');
}

function runAction(action) {
    if (action.disabled) {
        return;
    }
    emit('close');
    action.handler?.();
}

async function updatePosition() {
    if (!triggerRef.value) {
        return;
    }

    await nextTick(); // let the menu render first so menuRef has real dimensions

    const trigger = triggerRef.value.getBoundingClientRect();
    const menuWidth = menuRef.value?.offsetWidth ?? 220;
    const menuHeight = menuRef.value?.offsetHeight ?? 200;
    const margin = 8;

    let left = trigger.left;
    if (left + menuWidth > window.innerWidth - margin) {
        left = window.innerWidth - menuWidth - margin;
    }
    left = Math.max(margin, left);

    // Fixed positioning (not absolute) — getBoundingClientRect() is already
    // viewport-relative, so no scrollX/scrollY math is needed. The menu closes
    // on scroll (below) instead of tracking the trigger, which keeps this simple
    // and avoids desync while the table's own horizontal scroll container moves.
    let top = trigger.bottom + 4;
    if (top + menuHeight > window.innerHeight - margin) {
        top = trigger.top - menuHeight - 4;
    }

    position.value = { top: Math.max(margin, top), left };
}

function closeOnScroll() {
    emit('close');
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

function handleClickOutside(event) {
    if (menuRef.value?.contains(event.target) || triggerRef.value?.contains(event.target)) {
        return;
    }
    emit('close');
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        if (!props.isMobile) {
            updatePosition();
            window.addEventListener('scroll', closeOnScroll, true);
            window.addEventListener('resize', closeOnScroll);
        }
        document.addEventListener('keydown', handleKeydown);
        document.addEventListener('mousedown', handleClickOutside);
    } else {
        window.removeEventListener('scroll', closeOnScroll, true);
        window.removeEventListener('resize', closeOnScroll);
        document.removeEventListener('keydown', handleKeydown);
        document.removeEventListener('mousedown', handleClickOutside);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', closeOnScroll, true);
    window.removeEventListener('resize', closeOnScroll);
    document.removeEventListener('keydown', handleKeydown);
    document.removeEventListener('mousedown', handleClickOutside);
});
</script>

<template>
    <div class="inline-block">
        <button
            ref="triggerRef"
            type="button"
            class="inline-flex h-11 w-11 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine sm:h-8 sm:w-8"
            :aria-label="`Mở thao tác booking ${bookingCode}`"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="toggle"
        >
            <MoreHorizontal class="h-4 w-4" />
        </button>

        <Teleport to="body">
            <!-- Mobile: bottom sheet -->
            <div v-if="open && isMobile" class="fixed inset-0 z-50">
                <div class="absolute inset-0 bg-black/40" @click="emit('close')" />
                <div
                    ref="menuRef"
                    role="menu"
                    :aria-label="`Thao tác booking ${bookingCode}`"
                    class="absolute inset-x-0 bottom-0 flex max-h-[80vh] flex-col border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)] shadow-xl"
                >
                    <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-4 py-3">
                        <span class="text-sm font-semibold text-ink">Thao tác booking {{ bookingCode }}</span>
                        <button type="button" class="flex h-11 w-11 items-center justify-center text-steel hover:text-ink" aria-label="Đóng" @click="emit('close')">
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto py-2">
                        <template v-for="action in actions" :key="action.key">
                            <Link
                                v-if="!action.disabled && action.href"
                                :href="action.href"
                                role="menuitem"
                                class="flex min-h-11 items-center gap-3 px-4 py-3 text-sm"
                                :class="[action.danger ? 'border-t border-gray-100 mt-1 pt-3 text-coral' : 'text-ink']"
                                @click="emit('close')"
                            >
                                <component :is="action.icon" class="h-4 w-4 shrink-0" />
                                {{ action.label }}
                            </Link>
                            <button
                                v-else-if="!action.disabled"
                                type="button"
                                role="menuitem"
                                class="flex min-h-11 w-full items-center gap-3 px-4 py-3 text-left text-sm"
                                :class="[action.danger ? 'border-t border-gray-100 mt-1 pt-3 text-coral' : 'text-ink']"
                                @click="runAction(action)"
                            >
                                <component :is="action.icon" class="h-4 w-4 shrink-0" />
                                {{ action.label }}
                            </button>
                            <div
                                v-else
                                role="menuitem"
                                aria-disabled="true"
                                class="flex min-h-11 items-center gap-3 px-4 py-3 text-sm text-gray-300"
                                :class="[action.danger && 'border-t border-gray-100 mt-1 pt-3']"
                                :title="action.disabledReason"
                                :aria-label="action.disabledReason"
                            >
                                <component :is="action.icon" class="h-4 w-4 shrink-0" />
                                {{ action.label }}
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Desktop: floating dropdown, positioned near the trigger -->
            <div
                v-else-if="open"
                ref="menuRef"
                role="menu"
                :aria-label="`Thao tác booking ${bookingCode}`"
                class="fixed z-50 min-w-[220px] border border-gray-200 bg-white py-1 shadow-lg"
                :style="{ top: position.top + 'px', left: position.left + 'px' }"
            >
                <template v-for="action in actions" :key="action.key">
                    <Link
                        v-if="!action.disabled && action.href"
                        :href="action.href"
                        role="menuitem"
                        class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50"
                        :class="[action.danger ? 'border-t border-gray-100 mt-1 pt-2 text-coral' : 'text-ink']"
                        @click="emit('close')"
                    >
                        <component :is="action.icon" class="h-4 w-4 shrink-0" />
                        {{ action.label }}
                    </Link>
                    <button
                        v-else-if="!action.disabled"
                        type="button"
                        role="menuitem"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-50"
                        :class="[action.danger ? 'border-t border-gray-100 mt-1 pt-2 text-coral' : 'text-ink']"
                        @click="runAction(action)"
                    >
                        <component :is="action.icon" class="h-4 w-4 shrink-0" />
                        {{ action.label }}
                    </button>
                    <div
                        v-else
                        role="menuitem"
                        aria-disabled="true"
                        class="flex items-center gap-2 px-3 py-2 text-sm text-gray-300"
                        :class="[action.danger && 'border-t border-gray-100 mt-1 pt-2']"
                        :title="action.disabledReason"
                        :aria-label="action.disabledReason"
                    >
                        <component :is="action.icon" class="h-4 w-4 shrink-0" />
                        {{ action.label }}
                    </div>
                </template>
            </div>
        </Teleport>
    </div>
</template>
