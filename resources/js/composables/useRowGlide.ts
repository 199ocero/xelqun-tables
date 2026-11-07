import { computed, ref } from 'vue';

/**
 * One highlight that slides from row to row, passing over the ones between, in
 * place of each row lighting up by itself. It sits behind the rows, so the table
 * itself must have no background.
 */
export function useRowGlide() {
    const top = ref(0);
    const height = ref(0);
    const width = ref(0);
    const isVisible = ref(false);
    const isGliding = ref(false);

    function hideGlide(): void {
        isVisible.value = false;
        isGliding.value = false;
    }

    function glideTo(event: Event): void {
        const row = (event.target as HTMLElement).closest<HTMLElement>('[data-xq-table-row]');
        const area = event.currentTarget as HTMLElement;

        if (!row) {
            hideGlide();

            return;
        }

        // The first row of a visit appears where it is, rather than sliding in from the last visit.
        isGliding.value = isVisible.value;
        isVisible.value = true;
        top.value = row.getBoundingClientRect().top - area.getBoundingClientRect().top;
        height.value = row.getBoundingClientRect().height;
        width.value = area.scrollWidth;
    }

    const glideClass = computed(() => [
        'bg-xq-muted pointer-events-none absolute top-0 left-0',
        isGliding.value
            ? 'transition-[transform,height,opacity] duration-(--xq-duration-base) ease-(--xq-ease-out)'
            : 'transition-opacity duration-(--xq-duration-fast)',
    ]);

    const glideStyle = computed(() => ({
        transform: `translateY(${top.value}px)`,
        height: `${height.value}px`,
        width: `${width.value}px`,
        opacity: isVisible.value ? 1 : 0,
    }));

    return { glideClass, glideStyle, glideTo, hideGlide };
}
