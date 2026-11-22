<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Icon from './Icon.vue'
import SearchBox from './SearchBox.vue'
import { t } from '../lib/i18n'
import { trapFocus } from '../lib/focus'

interface NavLink { label: string; url: string; icon: string; active?: boolean; count?: number | null }

defineProps<{
  title: string
  catalog: { label: string; items: NavLink[]; active: boolean }
  links: NavLink[]
  watchlist: { label: string; url: string }
  languages: { label: string; url: string; current: string }
  search: { endpoint: string; catalogUrl: string; placeholder: string }
}>()

const open = ref(false)
const panel = ref<HTMLElement | null>(null)
let release: (() => void) | null = null

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') open.value = false
}

watch(open, async (value) => {
  document.documentElement.style.overflow = value ? 'hidden' : ''
  if (value) {
    window.addEventListener('keydown', onKeydown)
    await nextTick()
    if (panel.value) release = trapFocus(panel.value)
  } else {
    window.removeEventListener('keydown', onKeydown)
    release?.()
    release = null
  }
})

onBeforeUnmount(() => {
  document.documentElement.style.overflow = ''
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <button type="button" class="btn-icon" :aria-expanded="open" aria-haspopup="dialog" :aria-label="t('menu')" @click="open = true">
    <Icon name="menu" class="size-6" />
  </button>

  <Teleport to="body">
    <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
      <div v-if="open" class="fixed inset-0 z-50 bg-ink/40 backdrop-blur-[2px]" aria-hidden="true" @click="open = false" />
    </Transition>
    <Transition enter-from-class="translate-x-full rtl:-translate-x-full" enter-active-class="transition duration-300 ease-out" leave-to-class="translate-x-full rtl:-translate-x-full" leave-active-class="transition duration-200 ease-in">
      <div
        v-if="open"
        ref="panel"
        class="fixed inset-y-0 end-0 z-50 flex w-[min(90vw,400px)] flex-col bg-paper shadow-lift"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
      >
        <div class="flex h-[4.5rem] items-center justify-between border-b border-line px-4">
          <span class="truncate font-display text-lg">{{ title }}</span>
          <button type="button" class="btn-icon -me-2" :aria-label="t('close')" @click="open = false">
            <Icon name="x" class="size-6" />
          </button>
        </div>

        <div class="flex-1 overflow-y-auto px-4 py-5">
          <SearchBox v-bind="search" />

          <nav class="mt-6" :aria-label="catalog.label">
            <p class="mb-2 px-3 text-sm font-bold tracking-wide text-muted uppercase">{{ catalog.label }}</p>
            <ul class="space-y-1">
              <li v-for="item in catalog.items" :key="item.url">
                <a :href="item.url" class="flex min-h-12 items-center gap-3 rounded-xl px-3 py-2 text-base font-semibold transition hover:bg-sand">
                  <span class="grid size-9 place-items-center rounded-lg bg-brand-50 text-ink"><Icon :name="item.icon" class="size-5" /></span>
                  <span class="flex-1">{{ item.label }}</span>
                  <span v-if="item.count !== null && item.count !== undefined" class="text-sm text-muted tabular-nums">{{ item.count }}</span>
                </a>
              </li>
            </ul>
          </nav>

          <ul class="mt-6 space-y-1 border-t border-line pt-4">
            <li v-for="link in links" :key="link.url">
              <a
                :href="link.url"
                class="flex min-h-12 items-center gap-3 rounded-xl px-3 py-2 text-base font-semibold transition"
                :class="link.active ? 'bg-white ring-1 ring-line' : 'hover:bg-sand'"
                :aria-current="link.active ? 'page' : undefined"
              >
                <Icon :name="link.icon" class="size-5" />
                {{ link.label }}
              </a>
            </li>
          </ul>
        </div>

        <div class="flex items-center gap-2 border-t border-line p-4">
          <a :href="watchlist.url" class="btn btn-secondary flex-1">
            <Icon name="heart" class="size-5 text-heart" /> {{ watchlist.label }}
            <span data-watch-count class="hidden rounded-full bg-heart px-1.5 text-[11px] leading-5 font-bold text-white" />
          </a>
          <a :href="languages.url" class="btn btn-secondary px-4" :aria-label="languages.label">
            <Icon name="globe" class="size-5" /> <span class="uppercase">{{ languages.current }}</span>
          </a>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
