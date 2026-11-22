<script setup lang="ts">
import { ref } from 'vue'
import Icon from '../Icon.vue'
import { t } from '../../lib/i18n'
import type { RegionNode } from './types'

defineOptions({ name: 'RegionTree' })

const props = defineProps<{ nodes: RegionNode[]; selected: number[]; depth?: number }>()
const emit = defineEmits<{ toggle: [id: number] }>()

const contains = (node: RegionNode): boolean =>
  props.selected.includes(node.id) || node.children.some(contains)

// Zweige mit Auswahl sind von Anfang an aufgeklappt
const expanded = ref<Set<number>>(new Set(props.nodes.filter((n) => n.children.some(contains)).map((n) => n.id)))

function toggleExpand(id: number) {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}
</script>

<template>
  <ul :class="(depth ?? 0) > 0 ? 'mt-0.5 ms-[11px] border-s border-line ps-2' : ''">
    <li v-for="node in nodes" :key="node.id">
      <div class="flex items-center gap-1">
        <label class="flex min-h-10 min-w-0 flex-1 cursor-pointer items-center gap-2.5 rounded-lg px-1.5 py-1.5 text-sm transition hover:bg-sand">
          <input
            type="checkbox"
            class="size-5 shrink-0 rounded border-line accent-ink"
            :checked="selected.includes(node.id)"
            @change="emit('toggle', node.id)"
          >
          <span class="min-w-0 flex-1 truncate" :class="selected.includes(node.id) ? 'font-semibold' : ''">{{ node.label }}</span>
          <span class="text-xs text-muted tabular-nums">{{ node.count }}</span>
        </label>
        <button
          v-if="node.children.length"
          type="button"
          class="grid size-10 shrink-0 place-items-center rounded-md text-muted hover:bg-sand hover:text-ink"
          :aria-expanded="expanded.has(node.id)"
          :aria-label="t('expand', { name: node.label })"
          @click="toggleExpand(node.id)"
        >
          <Icon name="chevron-down" class="size-4 transition" :class="expanded.has(node.id) ? 'rotate-180' : ''" />
        </button>
      </div>
      <RegionTree
        v-if="node.children.length && expanded.has(node.id)"
        :nodes="node.children"
        :selected="selected"
        :depth="(depth ?? 0) + 1"
        @toggle="(id: number) => emit('toggle', id)"
      />
    </li>
  </ul>
</template>
