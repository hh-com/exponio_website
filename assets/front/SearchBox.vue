<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n'

interface SuggestItem { title: string; meta: string; url: string; thumb: string | null }
interface SuggestLink { label: string; url: string }
interface Suggestions { items: SuggestItem[]; regions: SuggestLink[]; topics: SuggestLink[]; total: number }

const props = withDefaults(defineProps<{
  endpoint: string
  catalogUrl: string
  placeholder?: string
  size?: 'md' | 'lg'
  autofocus?: boolean
  label?: string
}>(), { placeholder: '', size: 'md', autofocus: false, label: '' })

const initial = new URLSearchParams(location.search).get('q') ?? ''
const query = ref(location.pathname === props.catalogUrl ? initial : '')
const open = ref(false)
const loading = ref(false)
const active = ref(-1)
const data = ref<Suggestions | null>(null)
const listId = useId()
let timer: number | undefined
let controller: AbortController | null = null

const options = computed(() => {
  if (!data.value) return []
  return [
    ...data.value.items.map((i) => ({ type: 'item' as const, url: i.url, label: i.title, meta: i.meta, thumb: i.thumb })),
    ...data.value.regions.map((r) => ({ type: 'region' as const, url: r.url, label: r.label, meta: '', thumb: null })),
    ...data.value.topics.map((r) => ({ type: 'topic' as const, url: r.url, label: r.label, meta: '', thumb: null })),
  ]
})

watch(query, (value) => {
  window.clearTimeout(timer)
  active.value = -1
  if (value.trim().length < 2) {
    data.value = null
    return
  }
  timer = window.setTimeout(() => load(value.trim()), 180)
})

async function load(q: string) {
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  try {
    const response = await fetch(`${props.endpoint}?q=${encodeURIComponent(q)}`, { signal: controller.signal, headers: { Accept: 'application/json' } })
    if (response.ok) {
      data.value = await response.json()
      open.value = true
    }
  } catch {
    // abgebrochen oder offline – Vorschläge sind optional
  } finally {
    loading.value = false
  }
}

function submit() {
  const option = options.value[active.value]
  if (option) {
    location.href = option.url
    return
  }
  const q = query.value.trim()
  location.href = q ? `${props.catalogUrl}?q=${encodeURIComponent(q)}` : props.catalogUrl
}

function onKeydown(event: KeyboardEvent) {
  const count = options.value.length
  if (event.key === 'ArrowDown' && count) {
    event.preventDefault()
    open.value = true
    active.value = (active.value + 1) % count
  } else if (event.key === 'ArrowUp' && count) {
    event.preventDefault()
    active.value = (active.value - 1 + count) % count
  } else if (event.key === 'Escape') {
    open.value = false
    active.value = -1
  }
}

function onFocusOut(event: FocusEvent) {
  const root = event.currentTarget as HTMLElement
  if (!root.contains(event.relatedTarget as Node | null)) open.value = false
}

onBeforeUnmount(() => {
  window.clearTimeout(timer)
  controller?.abort()
})

const sectionTitle = { item: 'suggest_items', region: 'suggest_regions', topic: 'suggest_topics' } as const
</script>

<template>
  <form role="search" class="relative" :aria-label="label || t('search')" @submit.prevent="submit" @focusout="onFocusOut">
    <label class="sr-only" :for="`${listId}-input`">{{ label || t('search') }}</label>
    <div class="relative">
      <Icon
        name="search"
        class="pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted"
        :class="size === 'lg' ? 'start-5 size-5' : 'start-4 size-[18px]'"
      />
      <input
        :id="`${listId}-input`"
        v-model="query"
        type="search"
        autocomplete="off"
        enterkeyhint="search"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open && !!data"
        :aria-controls="listId"
        :aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined"
        :autofocus="autofocus"
        :placeholder="placeholder"
        class="input [&::-webkit-search-cancel-button]:hidden"
        :class="size === 'lg' ? 'h-14 rounded-full ps-12 pe-32 text-base shadow-card' : 'h-11 rounded-full ps-11 text-sm'"
        @focus="open = !!data"
        @keydown="onKeydown"
      >
      <button
        v-if="size === 'lg'"
        type="submit"
        class="btn btn-primary absolute top-1/2 end-1.5 -translate-y-1/2"
      >
        {{ t('search') }}
      </button>
      <span v-else-if="loading" class="absolute top-1/2 end-4 size-4 -translate-y-1/2 animate-spin rounded-full border-2 border-brand-200 border-t-ink" />
    </div>

    <div
      v-if="open && data"
      :id="listId"
      role="listbox"
      class="card absolute inset-x-0 top-full z-50 mt-2 max-h-[70vh] min-w-72 animate-fade-in overflow-y-auto p-2"
    >
      <p v-if="!options.length" class="px-3 py-4 text-sm text-muted">{{ t('suggest_none') }}</p>
      <template v-for="(option, index) in options" :key="option.url">
        <p
          v-if="index === 0 || options[index - 1].type !== option.type"
          class="px-3 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-muted uppercase"
        >
          {{ t(sectionTitle[option.type]) }}
        </p>
        <a
          :id="`${listId}-${index}`"
          :href="option.url"
          role="option"
          :aria-selected="active === index"
          class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition"
          :class="active === index ? 'bg-brand-50' : 'hover:bg-sand'"
          @mouseenter="active = index"
        >
          <span v-if="option.type === 'item'" class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg bg-soft ring-1 ring-line">
            <img v-if="option.thumb" :src="option.thumb" alt="" class="size-full object-contain p-0.5">
            <Icon v-else name="stamp" class="size-5 text-muted" />
          </span>
          <Icon v-else :name="option.type === 'region' ? 'globe' : 'tag'" class="size-4 shrink-0" />
          <span class="min-w-0">
            <span class="block truncate font-medium">{{ option.label }}</span>
            <span v-if="option.meta" class="block truncate text-xs text-muted">{{ option.meta }}</span>
          </span>
        </a>
      </template>
      <button
        v-if="data.total > data.items.length"
        type="submit"
        class="mt-1 flex min-h-11 w-full items-center justify-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-semibold text-info hover:bg-brand-50"
        @click="active = -1"
      >
        {{ t('suggest_all', { count: data.total }) }} <Icon name="arrow-right" class="flip-rtl size-4" />
      </button>
    </div>
  </form>
</template>
