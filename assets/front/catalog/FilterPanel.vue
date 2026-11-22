<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Icon from '../Icon.vue'
import RegionTree from './RegionTree.vue'
import { t } from '../../lib/i18n'
import type { CatalogState, Facets } from './types'

const props = defineProps<{ state: CatalogState; facets: Facets }>()
const emit = defineEmits<{ change: [patch: Partial<CatalogState>, debounce?: boolean] }>()

const TOPIC_LIMIT = 8
const showAllTopics = ref(false)
const topics = computed(() => (showAllTopics.value ? props.facets.topics : props.facets.topics.slice(0, TOPIC_LIMIT)))

// Eingabefelder lokal halten, damit Tippen nicht durch Server-Antworten überschrieben wird
const q = ref(props.state.q)
const from = ref(props.state.from?.toString() ?? '')
const to = ref(props.state.to?.toString() ?? '')
watch(() => props.state.q, (v) => { if (v !== q.value.trim()) q.value = v })
watch(() => props.state.from, (v) => { from.value = v?.toString() ?? '' })
watch(() => props.state.to, (v) => { to.value = v?.toString() ?? '' })

const yearValue = (value: string): number | null => {
  const n = Number(value)
  return value !== '' && Number.isInteger(n) && n >= 1000 && n <= 2200 ? n : null
}

function toggleInList(list: number[], id: number): number[] {
  return list.includes(id) ? list.filter((x) => x !== id) : [...list, id]
}

function toggleAttr(code: string, value: string) {
  const current = props.state.attr[code] ?? []
  const next = current.includes(value) ? current.filter((v) => v !== value) : [...current, value]
  const attr = { ...props.state.attr, [code]: next }
  if (!next.length) delete attr[code]
  emit('change', { attr })
}
</script>

<template>
  <div class="space-y-7">
    <!-- Suche -->
    <section>
      <label class="label" for="catalog-q">{{ t('search') }}</label>
      <div class="relative">
        <Icon name="search" class="pointer-events-none absolute top-1/2 start-3 size-4 -translate-y-1/2 text-muted" />
        <input
          id="catalog-q"
          v-model="q"
          type="search"
          enterkeyhint="search"
          :placeholder="t('search_in_results')"
          class="input h-11 ps-9 text-sm"
          @input="emit('change', { q }, true)"
        >
      </div>
    </section>

    <!-- Objektart -->
    <section v-if="facets.areas.length > 1 || state.area !== null">
      <h3 class="label">{{ t('filter_area') }}</h3>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="area in facets.areas"
          :key="area.id"
          type="button"
          class="chip min-h-9 px-3 py-1 text-[13px]"
          :class="state.area === area.id ? 'chip-active' : ''"
          :aria-pressed="state.area === area.id"
          @click="emit('change', { area: state.area === area.id ? null : area.id })"
        >
          <Icon :name="area.icon" class="size-3.5" />
          {{ area.label }}
          <span class="text-xs tabular-nums opacity-70">{{ area.count }}</span>
        </button>
      </div>
    </section>

    <!-- Merkmale der gewählten Objektart -->
    <fieldset v-for="facet in facets.attributes" :key="facet.code">
      <legend class="label">{{ facet.label }}</legend>
      <div class="space-y-0.5">
        <label
          v-for="option in facet.options"
          :key="option.value"
          class="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg px-1.5 py-1.5 text-sm transition hover:bg-sand"
        >
          <input
            type="checkbox"
            class="size-5 rounded border-line accent-ink"
            :checked="(state.attr[facet.code] ?? []).includes(option.value)"
            @change="toggleAttr(facet.code, option.value)"
          >
          <span class="flex-1">{{ option.label }}</span>
          <span class="text-xs text-muted tabular-nums">{{ option.count }}</span>
        </label>
      </div>
    </fieldset>

    <!-- Länder -->
    <section v-if="facets.regions.length">
      <h3 class="label">{{ t('filter_region') }}</h3>
      <RegionTree
        :nodes="facets.regions"
        :selected="state.regions"
        @toggle="(id: number) => emit('change', { regions: toggleInList(state.regions, id) })"
      />
    </section>

    <!-- Themen -->
    <section v-if="facets.topics.length">
      <h3 class="label">{{ t('filter_topic') }}</h3>
      <div class="space-y-0.5">
        <label
          v-for="topic in topics"
          :key="topic.id"
          class="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg px-1.5 py-1.5 text-sm transition hover:bg-sand"
        >
          <input
            type="checkbox"
            class="size-5 rounded border-line accent-ink"
            :checked="state.topics.includes(topic.id)"
            @change="emit('change', { topics: toggleInList(state.topics, topic.id) })"
          >
          <span class="flex-1 truncate">{{ topic.label }}</span>
          <span class="text-xs text-muted tabular-nums">{{ topic.count }}</span>
        </label>
      </div>
      <button
        v-if="facets.topics.length > TOPIC_LIMIT"
        type="button"
        class="mt-1 min-h-9 px-1.5 text-sm font-semibold text-info hover:underline"
        @click="showAllTopics = !showAllTopics"
      >
        {{ showAllTopics ? t('less') : t('more') }}
      </button>
    </section>

    <!-- Zeitraum -->
    <section v-if="facets.years.min !== null || state.from !== null || state.to !== null">
      <h3 class="label">{{ t('filter_year') }}</h3>
      <div class="flex items-center gap-2">
        <input
          v-model="from"
          type="number"
          inputmode="numeric"
          :min="1000" :max="2200"
          :placeholder="facets.years.min?.toString() ?? t('year_from')"
          :aria-label="t('year_from')"
          class="input h-10 text-sm tabular-nums"
          @input="emit('change', { from: yearValue(from) }, true)"
        >
        <span class="text-muted">–</span>
        <input
          v-model="to"
          type="number"
          inputmode="numeric"
          :min="1000" :max="2200"
          :placeholder="facets.years.max?.toString() ?? t('year_to')"
          :aria-label="t('year_to')"
          class="input h-10 text-sm tabular-nums"
          @input="emit('change', { to: yearValue(to) }, true)"
        >
      </div>
    </section>

  </div>
</template>
