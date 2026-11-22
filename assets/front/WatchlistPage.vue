<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n'
import { CHANGE_EVENT, clear, getIds } from '../lib/watchlist'

const props = defineProps<{ endpoint: string; catalogUrl: string }>()

const ids = ref<number[]>(getIds())
const html = ref('')
const loading = ref(true)

async function load() {
  ids.value = getIds()
  if (!ids.value.length) {
    html.value = ''
    loading.value = false
    return
  }
  loading.value = true
  try {
    const response = await fetch(`${props.endpoint}?ids=${ids.value.join(',')}`)
    html.value = response.ok ? await response.text() : ''
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  window.addEventListener(CHANGE_EVENT, load)
})
onBeforeUnmount(() => window.removeEventListener(CHANGE_EVENT, load))
</script>

<template>
  <div aria-live="polite">
    <div v-if="!loading && !ids.length" class="card flex flex-col items-center px-6 py-16 text-center">
      <span class="mb-5 grid size-16 place-items-center rounded-2xl bg-soft text-heart"><Icon name="heart" class="size-8" /></span>
      <h2 class="text-xl">{{ t('watch_empty') }}</h2>
      <p class="mt-2 max-w-sm text-muted">{{ t('watch_empty_hint') }}</p>
      <a :href="catalogUrl" class="btn btn-primary mt-6">{{ t('browse') }} <Icon name="arrow-right" class="flip-rtl size-4" /></a>
    </div>

    <div v-else>
      <div class="mb-6 flex flex-wrap items-center gap-3">
        <button type="button" class="btn btn-secondary" @click="clear()"><Icon name="trash" class="size-4" /> {{ t('watch_clear') }}</button>
        <span v-if="loading" class="text-sm text-muted">{{ t('loading') }}</span>
      </div>
      <!-- eslint-disable-next-line vue/no-v-html -- Karten vom eigenen Server -->
      <div :class="loading ? 'opacity-50' : ''" v-html="html" />
    </div>
  </div>
</template>
