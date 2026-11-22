<script setup lang="ts">
import { computed } from 'vue'
import { icons } from '../lib/icons'

const props = defineProps<{ name: string }>()
const paths = computed(() => icons[props.name] ?? icons.box)
</script>

<template>
  <!-- eslint-disable vue/no-v-html -- feste SVG-Pfade aus lib/icons.ts -->
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    v-html="paths"
  />
  <!-- eslint-enable vue/no-v-html -->
</template>
