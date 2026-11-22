<script setup lang="ts">
import { computed, ref } from 'vue'
import type { CropRect } from './api'

/**
 * Zuschneiden: Rahmen über dem Bild an Ecken und Kanten ziehen oder verschieben, oder die Ränder mit
 * Reglern einstellen (auch per Tastatur). Der Ausschnitt geht in Anteilen (0–1) des angezeigten Bildes
 * mit „apply“ hinaus. Verwendet auf „Prüfen & freigeben“ (ReviewApp.vue) und im Objekt-Formular
 * (assets/admin/imageEditing.ts).
 */
const props = withDefaults(defineProps<{
  src: string
  /** Größe des angezeigten Bildes in Pixeln (nur das Seitenverhältnis zählt) */
  width: number
  height: number
  /** Ausschnitt beim Öffnen; ohne Angabe das ganze Bild */
  initial?: CropRect | null
  busy?: boolean
  applyLabel?: string
  busyLabel?: string
  /** größte Höhe der Bildfläche als CSS-Länge; ohne Angabe ist die Fläche quadratisch */
  areaHeight?: string
  hint?: string
}>(), {
  initial: null,
  busy: false,
  applyLabel: 'Zuschneiden',
  busyLabel: 'Schneide zu …',
  areaHeight: undefined,
  hint: 'Rahmen an Ecken und Kanten ziehen oder die Ränder einstellen. Alles außerhalb wird abgeschnitten, auch für die KI-Erkennung.',
})
const emit = defineEmits<{ apply: [rect: CropRect]; cancel: [] }>()

type CropSide = 'left' | 'top' | 'right' | 'bottom'
type CropHandle = 'move' | 'tl' | 't' | 'tr' | 'r' | 'br' | 'b' | 'bl' | 'l'
/** kleinster Ausschnitt beim Ziehen: 3 % von Breite bzw. Höhe */
const MIN_CROP = 0.03
const SIDES: [CropSide, string][] = [['left', 'links'], ['right', 'rechts'], ['top', 'oben'], ['bottom', 'unten']]
const OPPOSITE: Record<CropSide, CropSide> = { left: 'right', right: 'left', top: 'bottom', bottom: 'top' }
const HANDLES: [CropHandle, string][] = [
  ['tl', 'tw:left-0 tw:top-0 tw:cursor-nwse-resize'],
  ['t', 'tw:left-1/2 tw:top-0 tw:-translate-x-1/2 tw:cursor-ns-resize'],
  ['tr', 'tw:right-0 tw:top-0 tw:cursor-nesw-resize'],
  ['r', 'tw:right-0 tw:top-1/2 tw:-translate-y-1/2 tw:cursor-ew-resize'],
  ['br', 'tw:right-0 tw:bottom-0 tw:cursor-nwse-resize'],
  ['b', 'tw:left-1/2 tw:bottom-0 tw:-translate-x-1/2 tw:cursor-ns-resize'],
  ['bl', 'tw:left-0 tw:bottom-0 tw:cursor-nesw-resize'],
  ['l', 'tw:left-0 tw:top-1/2 tw:-translate-y-1/2 tw:cursor-ew-resize'],
]

const rect = ref<CropRect>(props.initial ? { ...props.initial } : { x: 0, y: 0, w: 1, h: 1 })
const frame = ref<HTMLElement | null>(null)
let drag: { handle: CropHandle; start: CropRect; x: number; y: number } | null = null

const clamp = (v: number, min: number, max: number) => Math.min(max, Math.max(min, v))
const edgesOf = (r: CropRect) => ({ left: r.x, top: r.y, right: r.x + r.w, bottom: r.y + r.h })
const rectOf = (e: { left: number; top: number; right: number; bottom: number }): CropRect => ({ x: e.left, y: e.top, w: e.right - e.left, h: e.bottom - e.top })

const ratio = computed(() => props.width / Math.max(1, props.height))
/** Rahmen so groß wie möglich in der Fläche (Innenabstand 1rem), im Seitenverhältnis des Bildes */
const frameStyle = computed(() => ({
  width: props.areaHeight ? `min(100%, calc((${props.areaHeight} - 2rem) * ${ratio.value}))` : `${Math.min(1, ratio.value) * 100}%`,
  aspectRatio: `${props.width} / ${Math.max(1, props.height)}`,
}))

function onDown(event: PointerEvent, handle: CropHandle) {
  if (props.busy) return
  event.preventDefault()
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  drag = { handle, start: { ...rect.value }, x: event.clientX, y: event.clientY }
}
function onUp() {
  drag = null
}
function onMove(event: PointerEvent) {
  if (!drag || !frame.value) return
  const dx = (event.clientX - drag.x) / frame.value.clientWidth
  const dy = (event.clientY - drag.y) / frame.value.clientHeight
  const { handle, start } = drag
  if (handle === 'move') {
    rect.value = { ...start, x: clamp(start.x + dx, 0, 1 - start.w), y: clamp(start.y + dy, 0, 1 - start.h) }
    return
  }
  const e = edgesOf(start)
  if (handle.includes('l')) e.left = clamp(e.left + dx, 0, e.right - MIN_CROP)
  if (handle.includes('r')) e.right = clamp(e.right + dx, e.left + MIN_CROP, 1)
  if (handle.includes('t')) e.top = clamp(e.top + dy, 0, e.bottom - MIN_CROP)
  if (handle.includes('b')) e.bottom = clamp(e.bottom + dy, e.top + MIN_CROP, 1)
  rect.value = rectOf(e)
}

/** Rand in Prozent (Regler – auch per Tastatur und ohne Ziehen bedienbar) */
function margin(side: CropSide): number {
  const e = edgesOf(rect.value)
  const value = side === 'left' || side === 'top' ? e[side] : 1 - e[side]
  return Math.round(value * 1000) / 10
}
function marginMax(side: CropSide): number {
  return Math.floor((100 - margin(OPPOSITE[side]) - MIN_CROP * 100) * 2) / 2
}
function setMargin(side: CropSide, percent: number) {
  if (Number.isNaN(percent)) return
  const e = edgesOf(rect.value)
  const v = clamp(percent / 100, 0, 1)
  if (side === 'left') e.left = Math.min(v, e.right - MIN_CROP)
  if (side === 'top') e.top = Math.min(v, e.bottom - MIN_CROP)
  if (side === 'right') e.right = Math.max(1 - v, e.left + MIN_CROP)
  if (side === 'bottom') e.bottom = Math.max(1 - v, e.top + MIN_CROP)
  rect.value = rectOf(e)
}

/** Das ganze Bild ist kein Zuschnitt: dann nur schließen */
function apply() {
  const r = rect.value
  if (r.x < 0.001 && r.y < 0.001 && r.w > 0.999 && r.h > 0.999) emit('cancel')
  else emit('apply', { ...r })
}
</script>

<template>
  <div class="tw:space-y-3" @keydown.esc.stop="emit('cancel')">
    <!-- mit areaHeight passt sich die Fläche dem Bild an (höchstens areaHeight hoch), sonst quadratisch -->
    <div class="tw:grid tw:place-items-center tw:overflow-hidden tw:rounded-2xl tw:border tw:border-line tw:bg-soft tw:p-4" :class="areaHeight ? '' : 'tw:aspect-square'">
      <div
        ref="frame"
        class="tw:relative tw:touch-none tw:overflow-hidden tw:select-none"
        :style="frameStyle"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="onUp"
      >
        <img :src="src" alt="" draggable="false" class="tw:absolute tw:inset-0 tw:size-full tw:transition-opacity" :class="busy ? 'tw:opacity-40' : ''">
        <div
          class="tw:absolute tw:cursor-move tw:outline-2 tw:outline-white"
          :style="{ left: `${rect.x * 100}%`, top: `${rect.y * 100}%`, width: `${rect.w * 100}%`, height: `${rect.h * 100}%`, boxShadow: '0 0 0 9999px rgb(0 0 0 / 0.55)' }"
          aria-hidden="true"
          @pointerdown="onDown($event, 'move')"
        >
          <span
            v-for="[handle, cls] in HANDLES"
            :key="handle"
            class="tw:absolute tw:size-6 tw:rounded-sm tw:border-2 tw:border-white tw:bg-brand-700 tw:shadow-sm"
            :class="cls"
            @pointerdown.stop="onDown($event, handle)"
          />
        </div>
      </div>
    </div>
    <div class="tw:space-y-3 tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4" role="group" aria-label="Zuschneiden">
      <p class="tw:m-0 tw:text-[13px] tw:opacity-70">{{ hint }}</p>
      <div class="tw:grid tw:grid-cols-2 tw:gap-x-4 tw:gap-y-2">
        <label v-for="[side, label] in SIDES" :key="side" class="tw:block">
          <span class="form-label tw:flex tw:justify-between"><span>Rand {{ label }}</span><span>{{ margin(side).toLocaleString('de-DE') }} %</span></span>
          <input
            type="range"
            class="form-range"
            min="0"
            :max="marginMax(side)"
            step="0.5"
            :value="margin(side)"
            :aria-valuetext="`${margin(side).toLocaleString('de-DE')} Prozent`"
            :disabled="busy"
            @input="setMargin(side, ($event.target as HTMLInputElement).valueAsNumber)"
          >
        </label>
      </div>
      <div class="tw:flex tw:flex-wrap tw:gap-2">
        <button type="button" class="btn btn-primary btn-solid" :disabled="busy" @click="apply">
          <i class="fa fa-crop-simple" /> {{ busy ? busyLabel : applyLabel }}
        </button>
        <button type="button" class="btn btn-default btn-ghost" :disabled="busy" @click="emit('cancel')">Abbrechen</button>
      </div>
    </div>
  </div>
</template>
