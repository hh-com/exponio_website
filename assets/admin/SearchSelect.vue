<script setup lang="ts">
import { computed, ref } from 'vue'

const props = defineProps<{
  options: { id: number; label: string }[]
  placeholder?: string
  allowCreate?: boolean
}>()
const model = defineModel<number | null>({ required: true })
const emit = defineEmits<{ create: [label: string] }>()

const open = ref(false)
const query = ref('')
const selected = computed(() => props.options.find((o) => o.id === model.value) ?? null)
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  const list = q ? props.options.filter((o) => o.label.toLowerCase().includes(q)) : props.options
  return list.slice(0, 60)
})

function choose(id: number | null) {
  model.value = id
  open.value = false
  query.value = ''
}

function onBlur(event: FocusEvent) {
  const root = (event.currentTarget as HTMLElement)
  if (!root.contains(event.relatedTarget as Node | null)) open.value = false
}
</script>

<template>
  <div class="tw:relative" @focusout="onBlur">
    <button
      type="button"
      class="form-select tw:w-full tw:text-left"
      @click="open = !open"
    >
      <span v-if="selected">{{ selected.label }}</span>
      <span v-else class="tw:opacity-60">{{ placeholder ?? '— auswählen —' }}</span>
    </button>
    <div
      v-if="open"
      class="tw:absolute tw:z-30 tw:mt-1 tw:w-full tw:overflow-hidden tw:rounded-lg tw:border tw:border-line tw:bg-white tw:shadow-xl"
    >
      <input
        v-model="query"
        type="search"
        class="form-control tw:rounded-none tw:border-0 tw:border-b tw:border-line"
        placeholder="Suchen …"
        autofocus
        @keydown.enter.prevent="filtered[0] ? choose(filtered[0].id) : (allowCreate && query.trim() && emit('create', query.trim()))"
        @keydown.escape="open = false"
      >
      <ul class="tw:m-0 tw:max-h-72 tw:list-none tw:overflow-y-auto tw:p-0 tw:py-1 tw:text-sm">
        <li>
          <button type="button" class="tw:w-full tw:border-0 tw:bg-transparent tw:px-3 tw:py-1.5 tw:text-left tw:opacity-60 tw:hover:bg-soft" @click="choose(null)">— keine Angabe —</button>
        </li>
        <li v-for="option in filtered" :key="option.id">
          <button
            type="button"
            class="tw:w-full tw:border-0 tw:bg-transparent tw:px-3 tw:py-1.5 tw:text-left tw:hover:bg-brand-50"
            :class="option.id === model ? 'tw:bg-brand-50 tw:font-semibold' : ''"
            @click="choose(option.id)"
          >
            {{ option.label }}
          </button>
        </li>
        <li v-if="allowCreate && query.trim()">
          <button type="button" class="tw:w-full tw:border-t tw:border-line tw:px-3 tw:py-2 tw:text-left tw:font-semibold tw:text-brand-700 tw:hover:bg-brand-50" @click="emit('create', query.trim()); open = false; query = ''">
            + „{{ query.trim() }}“ neu anlegen
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>
