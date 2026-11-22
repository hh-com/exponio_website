<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue'
import Icon from './Icon.vue'
import FilterPanel from './catalog/FilterPanel.vue'
import { t } from '../lib/i18n'
import { trapFocus } from '../lib/focus'
import { fromSearchParams, toSearchParams, type CatalogState, type Facets, type RegionNode } from './catalog/types'

const props = defineProps<{
  endpoint: string
  state: CatalogState
  facets: Facets
  total: number
  sorts: { value: string; label: string }[]
  initialHtml?: string
}>()

const state = reactive<CatalogState>(JSON.parse(JSON.stringify(props.state)))
const facets = shallowRef<Facets>(props.facets)
const total = ref(props.total)
const html = ref(props.initialHtml ?? '')
const loading = ref(false)
const sheetOpen = ref(false)
const resultsEl = ref<HTMLElement | null>(null)

// Bezeichnungen bleiben bekannt, auch wenn eine Facette gerade 0 Treffer hat
const labels = reactive({ regions: new Map<number, string>(), topics: new Map<number, string>(), areas: new Map<number, string>(), attr: new Map<string, string>() })
function rememberLabels(f: Facets) {
  const walk = (nodes: RegionNode[]) => nodes.forEach((n) => { labels.regions.set(n.id, n.label); walk(n.children) })
  walk(f.regions)
  f.topics.forEach((x) => labels.topics.set(x.id, x.label))
  f.areas.forEach((x) => labels.areas.set(x.id, x.label))
  f.attributes.forEach((a) => a.options.forEach((o) => labels.attr.set(`${a.code}:${o.value}`, o.label)))
}
rememberLabels(props.facets)

let controller: AbortController | null = null
let timer: number | undefined

async function load(push = true) {
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  const query = toSearchParams(state).toString()
  const url = props.endpoint + (query ? `?${query}` : '')
  try {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal })
    if (!response.ok) throw new Error(String(response.status))
    const data = await response.json()
    html.value = data.html
    total.value = data.total
    facets.value = data.facets
    rememberLabels(data.facets)
    state.page = data.state.page
    if (push) history.pushState({ catalog: true }, '', url)
  } catch (error) {
    if ((error as Error).name !== 'AbortError') location.href = url // Fallback: normale Seite laden
  } finally {
    loading.value = false
  }
}

function change(patch: Partial<CatalogState>, debounce = false) {
  if (patch.area !== undefined && patch.area !== state.area) state.attr = {}
  Object.assign(state, patch)
  if (patch.page === undefined) state.page = 1
  window.clearTimeout(timer)
  timer = window.setTimeout(() => load(), debounce ? 400 : 0)
}

function resetAll() {
  change({ q: '', area: null, regions: [], topics: [], from: null, to: null, attr: {} })
}

function onResultsClick(event: MouseEvent) {
  const link = (event.target as Element).closest<HTMLAnchorElement>('a[data-page]')
  if (!link || event.metaKey || event.ctrlKey || event.shiftKey) return
  event.preventDefault()
  change({ page: Number(link.dataset.page) })
  resultsEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function onPopState() {
  Object.assign(state, fromSearchParams(location.search, state.fixed))
  load(false)
}

const chips = computed(() => {
  const list: { key: string; label: string; remove: () => void }[] = []
  if (state.q.trim()) list.push({ key: 'q', label: `„${state.q.trim()}“`, remove: () => change({ q: '' }) })
  if (state.area !== null && state.fixed.area === null) list.push({ key: 'area', label: labels.areas.get(state.area) ?? `#${state.area}`, remove: () => change({ area: null }) })
  for (const [code, values] of Object.entries(state.attr)) {
    for (const value of values) {
      list.push({
        key: `attr-${code}-${value}`,
        label: labels.attr.get(`${code}:${value}`) ?? value,
        remove: () => {
          const attr = { ...state.attr, [code]: state.attr[code].filter((v) => v !== value) }
          if (!attr[code].length) delete attr[code]
          change({ attr })
        },
      })
    }
  }
  for (const id of state.regions) list.push({ key: `r${id}`, label: labels.regions.get(id) ?? `#${id}`, remove: () => change({ regions: state.regions.filter((x) => x !== id) }) })
  for (const id of state.topics) list.push({ key: `t${id}`, label: labels.topics.get(id) ?? `#${id}`, remove: () => change({ topics: state.topics.filter((x) => x !== id) }) })
  if (state.from !== null || state.to !== null) list.push({ key: 'years', label: `${state.from ?? '…'} – ${state.to ?? '…'}`, remove: () => change({ from: null, to: null }) })
  return list
})

const sheet = ref<HTMLElement | null>(null)
let releaseFocus: (() => void) | null = null
watch(sheetOpen, async (open) => {
  document.documentElement.style.overflow = open ? 'hidden' : ''
  if (open) {
    await nextTick()
    if (sheet.value) releaseFocus = trapFocus(sheet.value)
  } else {
    releaseFocus?.()
    releaseFocus = null
  }
})
const onKeydown = (e: KeyboardEvent) => { if (e.key === 'Escape') sheetOpen.value = false }

onMounted(() => {
  history.replaceState({ catalog: true }, '')
  window.addEventListener('popstate', onPopState)
  window.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  window.removeEventListener('popstate', onPopState)
  window.removeEventListener('keydown', onKeydown)
  document.documentElement.style.overflow = ''
})
</script>

<template>
  <div class="lg:grid lg:grid-cols-[16.5rem_minmax(0,1fr)] lg:gap-10">
    <!-- Filter Desktop -->
    <aside class="hidden lg:block">
      <div class="sticky top-24 max-h-[calc(100dvh-7rem)] overflow-y-auto pe-2 pb-6 scrollbar-none">
        <div class="mb-5 flex items-center justify-between">
          <h2 class="font-sans text-base font-semibold tracking-normal">{{ t('filters') }}</h2>
          <button v-if="chips.length" type="button" class="text-sm font-medium text-info hover:underline" @click="resetAll">{{ t('reset') }}</button>
        </div>
        <FilterPanel :state="state" :facets="facets" @change="change" />
      </div>
    </aside>

    <div ref="resultsEl" class="min-w-0 scroll-mt-24">
      <!-- Werkzeugleiste -->
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <button type="button" class="btn btn-secondary px-4 lg:hidden" @click="sheetOpen = true">
          <Icon name="filter" class="size-4" /> {{ t('filters') }}
          <span v-if="chips.length" class="grid size-5 place-items-center rounded-full bg-ink text-[11px] text-white">{{ chips.length }}</span>
        </button>
        <p class="text-sm text-muted tabular-nums" aria-live="polite">
          <span v-if="loading" class="inline-block size-3.5 animate-spin rounded-full border-2 border-brand-200 border-t-ink align-[-2px]" />
          {{ t('results', { count: total }) }}
        </p>
        <label class="ml-auto flex items-center gap-2 text-sm">
          <span class="sr-only text-muted sm:not-sr-only">{{ t('sort') }}</span>
          <select
            class="input h-11 w-auto cursor-pointer py-0 pe-9 ps-3 text-sm"
            :value="state.sort"
            @change="change({ sort: ($event.target as HTMLSelectElement).value })"
          >
            <option v-for="sort in sorts" :key="sort.value" :value="sort.value">{{ sort.label }}</option>
          </select>
        </label>
      </div>

      <!-- aktive Filter -->
      <ul v-if="chips.length" class="mb-5 flex flex-wrap gap-2" :aria-label="t('active_filters')">
        <li v-for="chip in chips" :key="chip.key">
          <button type="button" class="chip min-h-9 bg-brand-50 py-1 pe-2 text-[13px]" :aria-label="`${t('remove_filter')}: ${chip.label}`" @click="chip.remove()">
            {{ chip.label }} <Icon name="x" class="size-3.5" />
          </button>
        </li>
        <li>
          <button type="button" class="min-h-9 px-2 py-1 text-[13px] font-semibold text-muted hover:text-ink" @click="resetAll">{{ t('reset') }}</button>
        </li>
      </ul>

      <!-- Ergebnisse (serverseitig gerendert) -->
      <!-- eslint-disable vue/no-v-html -- HTML kommt vom eigenen Server (Twig, escaped) -->
      <div
        class="transition-opacity duration-200"
        :class="loading ? 'pointer-events-none opacity-50' : ''"
        @click="onResultsClick"
        v-html="html"
      />
      <!-- eslint-enable vue/no-v-html -->
    </div>
  </div>

  <!-- Filter mobil: Bottom-Sheet -->
  <Teleport to="body">
    <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
      <div v-if="sheetOpen" class="fixed inset-0 z-50 bg-ink/25 backdrop-blur-[2px] lg:hidden" @click="sheetOpen = false" />
    </Transition>
    <Transition enter-from-class="translate-y-full" enter-active-class="transition duration-300 ease-out" leave-to-class="translate-y-full" leave-active-class="transition duration-200 ease-in">
      <div
        v-if="sheetOpen"
        ref="sheet"
        class="fixed inset-x-0 bottom-0 z-50 flex max-h-[88dvh] flex-col rounded-t-3xl bg-paper shadow-lift lg:hidden"
        role="dialog"
        aria-modal="true"
        :aria-label="t('filters')"
      >
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
          <h2 class="font-sans text-base font-semibold tracking-normal">{{ t('filters') }}</h2>
          <div class="flex items-center gap-2">
            <button v-if="chips.length" type="button" class="text-sm font-semibold text-info" @click="resetAll">{{ t('reset') }}</button>
            <button type="button" class="btn-icon -me-2" :aria-label="t('close')" @click="sheetOpen = false">
              <Icon name="x" class="size-5" />
            </button>
          </div>
        </div>
        <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-5">
          <FilterPanel :state="state" :facets="facets" @change="change" />
        </div>
        <div class="border-t border-line p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
          <button type="button" class="btn btn-primary w-full py-3" @click="sheetOpen = false">
            {{ t('show_results', { count: total }) }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
