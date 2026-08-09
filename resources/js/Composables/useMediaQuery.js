import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Reactive `window.matchMedia` wrapper. Used to switch between desktop/mobile
 * presentation (e.g. dropdown vs. bottom sheet) without duplicating logic per
 * component — one source of truth for "is this a mobile viewport" reused
 * anywhere a component needs it.
 */
export function useMediaQuery(query) {
    const matches = ref(false);
    let mql = null;

    const update = () => {
        matches.value = mql.matches;
    };

    onMounted(() => {
        mql = window.matchMedia(query);
        update();
        mql.addEventListener('change', update);
    });

    onBeforeUnmount(() => {
        mql?.removeEventListener('change', update);
    });

    return matches;
}
