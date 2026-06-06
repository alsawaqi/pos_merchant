<script lang="ts">
// Module-scope body scroll-lock state, shared across every modal instance so
// stacked modals restore the original overflow exactly once.
let lockCount = 0;
let previousOverflow = '';
</script>

<script setup lang="ts">
/**
 * The single source of truth for every popup in the portal.
 *
 * Fixes the historical hand-rolled-modal problems in one place:
 *  - Teleported to <body>, so a `transform`/`filter` on any ancestor can't
 *    knock the fixed overlay off-screen (the "covers half the page" bug).
 *  - Perfectly centered; the panel is capped at max-h-[90vh] and the BODY
 *    scrolls internally while the header + footer stay pinned — tall forms
 *    never push the title or action buttons off-screen.
 *  - Backdrop blur, Escape + backdrop-to-close (both suppressed while
 *    `loading`), body scroll-lock, and a subtle scale + fade enter/leave.
 *
 * Parents keep their existing `v-if` mount + `@close` pattern. A user-initiated
 * close animates out first, then emits `close`; a parent that unmounts on
 * success (sets its v-if false) simply skips the leave animation.
 *
 * Slots: `icon` (leading header icon), `header` (replaces the title node),
 * default (scrollable body), `footer` (pinned action bar).
 */
import { X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        title?: string;
        size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl';
        /** Blocks Escape / backdrop / close-button dismissal mid-request. */
        loading?: boolean;
        hideClose?: boolean;
        closeOnBackdrop?: boolean;
        closeOnEsc?: boolean;
        /** Body padding/classes — pass '' for a full-bleed body. */
        bodyClass?: string;
    }>(),
    {
        title: undefined,
        size: 'md',
        loading: false,
        hideClose: false,
        closeOnBackdrop: true,
        closeOnEsc: true,
        bodyClass: 'px-6 py-5',
    },
);

const emit = defineEmits<{ (e: 'close'): void }>();

const SIZES: Record<NonNullable<typeof props.size>, string> = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    '3xl': 'max-w-3xl',
    '4xl': 'max-w-4xl',
};
const maxWidthClass = computed(() => SIZES[props.size ?? 'md']);

// `visible` drives the enter/leave transitions: it starts false so the first
// paint is the "from" state, then flips true on mount to animate in.
const visible = ref(false);

function requestClose(): void {
    if (props.loading) {
        return;
    }
    visible.value = false; // leave animation runs; `close` emits on @after-leave
}

function onBackdrop(): void {
    if (props.closeOnBackdrop) {
        requestClose();
    }
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && props.closeOnEsc) {
        requestClose();
    }
}

onMounted(() => {
    visible.value = true;

    if (lockCount === 0) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    }
    lockCount += 1;

    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    lockCount = Math.max(0, lockCount - 1);
    if (lockCount === 0) {
        document.body.style.overflow = previousOverflow;
    }
});
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50">
            <!-- Backdrop -->
            <Transition
                enter-active-class="transition-opacity duration-150 ease-out"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150 ease-in"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="visible"
                    class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm"
                    @click="onBackdrop"
                />
            </Transition>

            <!-- Panel -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 scale-95 translate-y-2"
                enter-to-class="opacity-100 scale-100 translate-y-0"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 scale-100 translate-y-0"
                leave-to-class="opacity-0 scale-95 translate-y-2"
                @after-leave="emit('close')"
            >
                <div v-show="visible" class="pointer-events-none fixed inset-0 flex items-center justify-center p-4">
                    <div
                        class="pointer-events-auto flex max-h-[90vh] w-full flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-950/5"
                        :class="maxWidthClass"
                        role="dialog"
                        aria-modal="true"
                    >
                        <header
                            v-if="title || $slots.header || !hideClose"
                            class="flex shrink-0 items-center gap-3 border-b border-slate-200 px-6 py-4"
                        >
                            <slot name="icon" />
                            <div class="min-w-0 flex-1">
                                <slot name="header">
                                    <h2 class="truncate text-base font-semibold text-slate-900">{{ title }}</h2>
                                </slot>
                            </div>
                            <button
                                v-if="!hideClose"
                                type="button"
                                class="-mr-1.5 grid size-9 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50"
                                :disabled="loading"
                                aria-label="Close"
                                @click="requestClose"
                            >
                                <X class="size-5" />
                            </button>
                        </header>

                        <div class="min-h-0 flex-1 overflow-y-auto" :class="bodyClass">
                            <slot />
                        </div>

                        <footer
                            v-if="$slots.footer"
                            class="shrink-0 border-t border-slate-200 bg-slate-50 px-6 py-4"
                        >
                            <slot name="footer" />
                        </footer>
                    </div>
                </div>
            </Transition>
        </div>
    </Teleport>
</template>
