<script setup lang="ts">
import { computed, ref } from 'vue'

const props = defineProps<{ topics: { id: number; name: string }[] }>()
const model = defineModel<number[]>({ required: true })
const emit = defineEmits<{ create: [name: string] }>()

const query = ref('')
const selected = computed(() => model.value.map((id) => props.topics.find((t) => t.id === id)).filter((t) => t !== undefined))
const suggestions = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return []
  return props.topics.filter((t) => !model.value.includes(t.id) && t.name.toLowerCase().includes(q)).slice(0, 8)
})

function add(id: number) {
  if (!model.value.includes(id)) model.value = [...model.value, id]
  query.value = ''
}
function remove(id: number) {
  model.value = model.value.filter((x) => x !== id)
}
function onEnter() {
  if (suggestions.value[0]) add(suggestions.value[0].id)
  else if (query.value.trim()) {
    emit('create', query.value.trim())
    query.value = ''
  }
}
</script>

<template>
  <div>
    <div class="tw:flex tw:flex-wrap tw:gap-1.5">
      <span
        v-for="topic in selected"
        :key="topic.id"
        class="tw:inline-flex tw:items-center tw:gap-1 tw:rounded-full tw:bg-brand-50 tw:py-1 tw:pr-1.5 tw:pl-3 tw:text-sm tw:text-brand-800 tw:ring-1 tw:ring-brand-100"
      >
        {{ topic.name }}
        <button type="button" class="tw:grid tw:size-5 tw:place-items-center tw:rounded-full tw:border-0 tw:bg-transparent tw:p-0 tw:leading-none tw:text-inherit tw:hover:bg-brand-100" :aria-label="`${topic.name} entfernen`" @click="remove(topic.id)">×</button>
      </span>
      <span v-if="!selected.length" class="tw:text-sm tw:opacity-60">Keine Themen</span>
    </div>
    <div class="tw:relative tw:mt-2">
      <input v-model="query" type="search" class="form-control" placeholder="Thema suchen oder neu anlegen (Enter) …" @keydown.enter.prevent="onEnter">
      <ul v-if="suggestions.length" class="tw:m-0 tw:list-none tw:absolute tw:z-30 tw:mt-1 tw:w-full tw:overflow-hidden tw:rounded-lg tw:border tw:border-line tw:bg-white tw:py-1 tw:text-sm tw:shadow-xl">
        <li v-for="topic in suggestions" :key="topic.id">
          <button type="button" class="tw:w-full tw:px-3 tw:py-1.5 tw:text-left tw:hover:bg-brand-50" @click="add(topic.id)">{{ topic.name }}</button>
        </li>
      </ul>
    </div>
  </div>
</template>
