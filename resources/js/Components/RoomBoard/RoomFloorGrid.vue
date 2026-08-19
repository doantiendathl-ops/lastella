<script setup>
// docs/yeucaumoi.txt mục 6 — shared floor/grid layout for the 4 Room Map
// screens, extracted verbatim from the Sơ đồ thao tác board (the reference
// layout the other 3 screens are being unified onto): ONE horizontal-scroll
// container per screen (never per floor), each floor a sticky-labeled row of
// flex-nowrap RoomTile-sized cards. Sibling of Components/RoomBoard/
// RoomBoardGrid.vue (the wrapping-grid layout used by Housekeeping/Rooms —
// left untouched, those aren't part of the 4 Room Map screens being unified).
//
// User request (2026-08-19 chat) — "phóng to thu nhỏ trong khung của sơ đồ,
// đặc biệt trên Mobile": pinch-to-zoom + +/-/100% buttons, scoped to this
// grid only (never the whole page). Since ALL 4 screens already render
// through this one component, the zoom lands on every Room Map screen from
// a single change here — no per-screen wiring needed.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    floors: { type: Array, default: () => [] },
    getKey: { type: Function, default: (room) => room.id },
    emptyMessage: { type: String, default: 'Không có phòng nào.' },
});

// User request (2026-08-20 chat) — lowered from 0.5 so more of a busy floor
// fits on screen at once, especially useful on mobile.
const MIN_SCALE = 0.3;
const MAX_SCALE = 2;
const SCALE_STEP = 0.1;

const scale = ref(1);
const viewportEl = ref(null);
const contentEl = ref(null);
const naturalWidth = ref(0);
const naturalHeight = ref(0);

function clampScale(value) {
    return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value));
}

function zoomIn() {
    scale.value = clampScale(scale.value + SCALE_STEP);
}

function zoomOut() {
    scale.value = clampScale(scale.value - SCALE_STEP);
}

function resetZoom() {
    scale.value = 1;
}

// The spacer establishes the SCALED footprint so the viewport's native
// overflow-auto scrollbars/touch-pan stay correct at every zoom level — a
// CSS transform alone never changes an element's layout size, only its
// paint, so without this the browser would never let you scroll to the
// zoomed-in edges of the grid. contentEl is pinned to its true natural
// width so scaling it down never triggers a reflow/wrap of the floor rows
// (a block element would otherwise shrink to fit a narrower parent).
// Before the first measurement (naturalWidth still 0) both styles are
// empty, so the grid renders exactly like the pre-zoom layout — no
// flash of a collapsed 0×0 box while mounting.
const spacerStyle = computed(() => (naturalWidth.value > 0 ? {
    position: 'relative',
    width: `${naturalWidth.value * scale.value}px`,
    height: `${naturalHeight.value * scale.value}px`,
} : {}));

const contentStyle = computed(() => (naturalWidth.value > 0 ? {
    position: 'absolute',
    top: '0',
    left: '0',
    transform: `scale(${scale.value})`,
    transformOrigin: 'top left',
    width: `${naturalWidth.value}px`,
} : {}));

async function measureNatural() {
    // Measure at scale 1 so scrollWidth/scrollHeight reflect the grid's
    // true unscaled size regardless of whatever zoom level is active right
    // now — re-scaling AFTER measuring, not before.
    const previousScale = scale.value;
    scale.value = 1;
    await nextTick();
    if (contentEl.value) {
        naturalWidth.value = contentEl.value.scrollWidth;
        naturalHeight.value = contentEl.value.scrollHeight;
    }
    scale.value = previousScale;
}

// Re-measure whenever the room list itself changes shape (search/filter on
// Sơ đồ thao tác and Sơ đồ Check phòng can shrink/grow the grid) and on
// viewport resize/orientation change (mobile rotate).
watch(() => props.floors, () => { measureNatural(); });

function onWindowResize() {
    measureNatural();
}

onMounted(() => {
    measureNatural();
    window.addEventListener('resize', onWindowResize);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onWindowResize);
});

// ---- Pinch-to-zoom (2-finger touch) ----------------------------------
// touch-action stays "pan-x pan-y" (not "none") so ordinary 1-finger
// scrolling keeps using the browser's own native, inertial scroll — only a
// genuine 2-finger gesture is intercepted here, and only THAT touchmove
// calls preventDefault(), which is what stops the browser's own whole-page
// pinch-zoom (app.blade.php's viewport tag allows it) from firing at the
// same time as this container-scoped one.
let pinchStartDistance = 0;
let pinchStartScale = 1;

function touchDistance(touches) {
    const [a, b] = touches;
    return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
}

function onTouchStart(event) {
    if (event.touches.length === 2) {
        pinchStartDistance = touchDistance(event.touches);
        pinchStartScale = scale.value;
    }
}

function onTouchMove(event) {
    if (event.touches.length === 2 && pinchStartDistance > 0) {
        event.preventDefault();
        const ratio = touchDistance(event.touches) / pinchStartDistance;
        scale.value = clampScale(pinchStartScale * ratio);
    }
}

function onTouchEnd(event) {
    if (event.touches.length < 2) {
        pinchStartDistance = 0;
    }
}
</script>

<template>
    <div class="room-board-zoom-wrapper relative">
        <div class="zoom-toolbar sticky left-0 top-0 z-20 mb-1.5 flex w-fit items-center gap-0.5 rounded border border-gray-200 bg-white/95 px-1 py-1 shadow-sm backdrop-blur-sm">
            <button
                type="button"
                class="flex h-7 w-7 items-center justify-center rounded text-base font-semibold leading-none text-gray-600 hover:bg-gray-100 disabled:opacity-30"
                :disabled="scale <= MIN_SCALE"
                title="Thu nhỏ sơ đồ"
                aria-label="Thu nhỏ sơ đồ"
                @click="zoomOut"
            >
                −
            </button>
            <button
                type="button"
                class="min-w-12 rounded px-1 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100"
                title="Về 100%"
                aria-label="Đặt lại về 100%"
                @click="resetZoom"
            >
                {{ Math.round(scale * 100) }}%
            </button>
            <button
                type="button"
                class="flex h-7 w-7 items-center justify-center rounded text-base font-semibold leading-none text-gray-600 hover:bg-gray-100 disabled:opacity-30"
                :disabled="scale >= MAX_SCALE"
                title="Phóng to sơ đồ"
                aria-label="Phóng to sơ đồ"
                @click="zoomIn"
            >
                +
            </button>
        </div>

        <div
            ref="viewportEl"
            class="room-board-overflow-x overflow-auto pb-2"
            style="touch-action: pan-x pan-y;"
            @touchstart="onTouchStart"
            @touchmove="onTouchMove"
            @touchend="onTouchEnd"
            @touchcancel="onTouchEnd"
        >
            <div :style="spacerStyle">
                <div ref="contentEl" :style="contentStyle">
                    <div class="room-board-content inline-flex min-w-full flex-col gap-3">
                        <section v-for="floor in floors" :key="floor.id" class="floor-row flex items-start gap-3">
                            <div class="floor-label sticky left-0 z-10 w-16 shrink-0 rounded bg-white/95 py-2 text-sm font-semibold text-gray-700 backdrop-blur-sm">
                                {{ floor.name }}
                            </div>
                            <div class="rooms-nowrap flex flex-nowrap gap-2">
                                <slot name="card" v-for="room in floor.rooms" :key="getKey(room)" :room="room" :floor="floor" />
                            </div>
                        </section>
                    </div>
                    <p v-if="floors.length === 0" class="text-sm text-gray-500">{{ emptyMessage }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
