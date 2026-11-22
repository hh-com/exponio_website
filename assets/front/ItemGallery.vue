<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n'
import { trapFocus } from '../lib/focus'

interface GalleryImage { full: string; medium: string; thumb: string; width: number; height: number; alt: string; side: string | null }

const props = defineProps<{ images: GalleryImage[]; title: string }>()

const index = ref(0)
const lightbox = ref(false)
const zoomed = ref(false)
const origin = ref('50% 50%')
const dialog = ref<HTMLElement | null>(null)
const current = computed(() => props.images[index.value])
const many = computed(() => props.images.length > 1)
const label = (i: number) => [t('image', { n: i + 1, total: props.images.length }), props.images[i]?.side].filter(Boolean).join(': ')
let release: (() => void) | null = null

function go(step: number) {
  if (!many.value) return
  index.value = (index.value + step + props.images.length) % props.images.length
  zoomed.value = false
}

// Wischen (Touch/Stift)
let startX = 0
let startY = 0
function onPointerDown(event: PointerEvent) {
  startX = event.clientX
  startY = event.clientY
}
function onPointerUp(event: PointerEvent) {
  const dx = event.clientX - startX
  const dy = event.clientY - startY
  if (!zoomed.value && Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? 1 : -1)
}
function wasSwipe(event: MouseEvent): boolean {
  return Math.abs(event.clientX - startX) > 10 || Math.abs(event.clientY - startY) > 10
}

// Lupe: Klick zoomt an der Klickposition, Tastatur zoomt in die Mitte
function setOrigin(event: PointerEvent | MouseEvent) {
  if (event.detail === 0) {
    origin.value = '50% 50%'
    return
  }
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const x = ((event.clientX - rect.left) / rect.width) * 100
  const y = ((event.clientY - rect.top) / rect.height) * 100
  origin.value = `${Math.min(100, Math.max(0, x))}% ${Math.min(100, Math.max(0, y))}%`
}
function toggleZoom(event: MouseEvent) {
  if (!zoomed.value && wasSwipe(event)) return
  setOrigin(event)
  zoomed.value = !zoomed.value
}
function onMove(event: PointerEvent) {
  if (zoomed.value) setOrigin(event)
}

function openLightbox(event: MouseEvent) {
  if (wasSwipe(event)) return
  lightbox.value = true
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    if (zoomed.value) zoomed.value = false
    else lightbox.value = false
  }
  if (event.key === 'ArrowRight') go(document.documentElement.dir === 'rtl' ? -1 : 1)
  if (event.key === 'ArrowLeft') go(document.documentElement.dir === 'rtl' ? 1 : -1)
}

watch(lightbox, async (open) => {
  document.documentElement.style.overflow = open ? 'hidden' : ''
  zoomed.value = false
  if (open) {
    window.addEventListener('keydown', onKeydown)
    await nextTick()
    if (dialog.value) release = trapFocus(dialog.value)
  } else {
    window.removeEventListener('keydown', onKeydown)
    release?.()
    release = null
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.documentElement.style.overflow = ''
})
</script>

<template>
  <section v-if="images.length" :aria-label="t('gallery')">
    <!-- Bühne -->
    <figure class="group relative">
      <button
        type="button"
        class="mount aspect-square w-full cursor-zoom-in touch-pan-y rounded-3xl p-6 ring-1 ring-line/70 sm:p-10"
        :aria-label="`${t('zoom')}: ${current.alt}`"
        @pointerdown="onPointerDown"
        @pointerup="onPointerUp"
        @click="openLightbox"
      >
        <img
          :key="current.medium"
          :src="current.medium"
          :srcset="`${current.medium} 800w, ${current.full} 2000w`"
          sizes="(min-width: 1024px) 55vw, 100vw"
          :width="current.width"
          :height="current.height"
          :alt="current.alt"
          class="animate-fade-in select-none"
          draggable="false"
        >
        <span class="absolute end-4 bottom-4 grid size-11 place-items-center rounded-full bg-white text-ink shadow-sm ring-1 ring-line" aria-hidden="true">
          <Icon name="zoom-in" class="size-5" />
        </span>
      </button>
      <figcaption v-if="many || current.side" class="mt-2 text-center text-sm text-muted" aria-live="polite">{{ label(index) }}</figcaption>
      <template v-if="many">
        <button type="button" class="absolute top-[calc(50%-1rem)] start-3 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white shadow-sm ring-1 ring-line transition hover:bg-soft" :aria-label="t('prev')" @click="go(-1)">
          <Icon name="chevron-left" class="flip-rtl size-5" />
        </button>
        <button type="button" class="absolute top-[calc(50%-1rem)] end-3 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white shadow-sm ring-1 ring-line transition hover:bg-soft" :aria-label="t('next')" @click="go(1)">
          <Icon name="chevron-right" class="flip-rtl size-5" />
        </button>
      </template>
    </figure>

    <!-- Vorschaubilder -->
    <ul v-if="many" class="mt-3 flex gap-2 overflow-x-auto pb-1 scrollbar-none">
      <li v-for="(image, i) in images" :key="image.thumb">
        <button
          type="button"
          class="mount size-18 shrink-0 rounded-xl p-1.5 ring-2 transition sm:size-20"
          :class="i === index ? 'ring-ink' : 'ring-transparent hover:ring-line'"
          :aria-label="label(i)"
          :aria-current="i === index"
          @click="index = i"
        >
          <img :src="image.thumb" alt="" loading="lazy">
        </button>
      </li>
    </ul>

    <!-- Lightbox -->
    <Teleport to="body">
      <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
        <div v-if="lightbox" ref="dialog" class="fixed inset-0 z-[60] flex flex-col bg-paper" role="dialog" aria-modal="true" :aria-label="`${title} – ${label(index)}`">
          <div class="flex items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <p class="text-sm text-muted">{{ label(index) }} · {{ t('zoom_hint') }}</p>
            <button type="button" class="btn-icon bg-white ring-1 ring-line" :aria-label="t('close')" @click="lightbox = false">
              <Icon name="x" class="size-6" />
            </button>
          </div>
          <button
            type="button"
            class="relative flex-1 overflow-hidden"
            :class="zoomed ? 'cursor-zoom-out touch-none' : 'cursor-zoom-in'"
            :aria-pressed="zoomed"
            :aria-label="`${t('zoom')}: ${current.alt}`"
            @pointerdown="onPointerDown"
            @pointerup="onPointerUp"
            @pointermove="onMove"
            @click="toggleZoom"
          >
            <img
              :key="current.full"
              :src="current.full"
              :alt="current.alt"
              draggable="false"
              class="absolute inset-0 m-auto max-h-full max-w-full object-contain p-4 drop-shadow-[0_10px_24px_rgb(26_42_67/0.18)] transition-transform duration-200 select-none sm:p-10"
              :style="{ transform: zoomed ? 'scale(2.6)' : 'none', transformOrigin: origin }"
            >
          </button>
          <div v-if="many" class="flex items-center justify-center gap-3 pt-2 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <button type="button" class="btn-icon bg-white ring-1 ring-line" :aria-label="t('prev')" @click.stop="go(-1)">
              <Icon name="chevron-left" class="flip-rtl size-5" />
            </button>
            <span class="text-sm text-muted tabular-nums" aria-live="polite">{{ index + 1 }} / {{ images.length }}</span>
            <button type="button" class="btn-icon bg-white ring-1 ring-line" :aria-label="t('next')" @click.stop="go(1)">
              <Icon name="chevron-right" class="flip-rtl size-5" />
            </button>
          </div>
        </div>
      </Transition>
    </Teleport>
  </section>
</template>
