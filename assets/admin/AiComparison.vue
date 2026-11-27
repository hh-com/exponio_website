<script setup lang="ts">
/**
 * Vergleichstabelle unter „Prüfen & freigeben“, wenn mehrere Modelle das Objekt bestimmt haben
 * (Einstellungen → KI → Modelle für die Erkennung): Zeilen = Felder, Spalten = Modelle.
 * Abweichende Werte sind markiert; „Übernehmen“ setzt den Vorschlag eines Modells ins Formular.
 */
import { computed, ref } from 'vue'
import type { RecognitionComparison, RecognitionResultData } from './api'

const props = defineProps<{ comparison: RecognitionComparison; busy: boolean }>()
const emit = defineEmits<{ apply: [resultId: number] }>()

const showLong = ref(false)
const results = computed(() => props.comparison.results)
const successful = computed(() => results.value.filter((r) => !r.error))

const normalize = (value: string | null | undefined) => (value ?? '').toLocaleLowerCase('de').replace(/\s+/g, ' ').trim()
/** Unterscheiden sich die erfolgreichen Ergebnisse in dieser Zeile? */
const differs = (key: string) => new Set(successful.value.map((r) => normalize(r.values[key]))).size > 1
/** nur Zeilen, in denen mindestens ein Modell etwas geliefert hat; lange Texte erst nach dem Umschalter */
const rows = computed(() => props.comparison.rows.filter((row) => (showLong.value || !row.long) && results.value.some((r) => r.values[row.key])))
const differing = computed(() => rows.value.filter((row) => differs(row.key)).length)
const hasLong = computed(() => props.comparison.rows.some((row) => row.long && results.value.some((r) => r.values[row.key])))

const number = new Intl.NumberFormat('de-DE')
const pct = (v: number | null) => (v === null ? '–' : `${Math.round(v * 100)} %`)
const seconds = (ms: number) => `${(ms / 1000).toLocaleString('de-DE', { maximumFractionDigits: 1 })} s`
function usage(r: RecognitionResultData): string {
  if (r.inputTokens === null && r.outputTokens === null) return ''
  return `${number.format(r.inputTokens ?? 0)} + ${number.format(r.outputTokens ?? 0)} Token`
}
/** unter einem Dollar in US-Cent, sonst in Dollar */
function cost(r: RecognitionResultData): string {
  if (r.cost === null) return ''
  return r.cost < 1
    ? `${(r.cost * 100).toLocaleString('de-DE', { maximumFractionDigits: 2, minimumFractionDigits: 2 })} ct`
    : `${r.cost.toLocaleString('de-DE', { maximumFractionDigits: 2, minimumFractionDigits: 2 })} $`
}
const confidenceColor = (v: number | null) => (v === null ? 'tw:bg-stone-300' : v >= 0.85 ? 'tw:bg-emerald-500' : v >= 0.6 ? 'tw:bg-amber-500' : 'tw:bg-red-500')
</script>

<template>
  <section class="tw:mb-5 tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4" aria-labelledby="ai-comparison-title">
    <div class="tw:flex tw:flex-wrap tw:items-baseline tw:justify-between tw:gap-x-4 tw:gap-y-1">
      <h3 id="ai-comparison-title" class="tw:m-0 tw:text-[15px] tw:font-semibold">KI-Vergleich: {{ results.length }} Modelle</h3>
      <p class="tw:m-0 tw:text-[12px] tw:opacity-70">
        <template v-if="successful.length > 1">{{ differing }} von {{ rows.length }} Feldern unterschiedlich (markiert).</template>
        Übernehmen ersetzt die Felder, die die KI ausfüllt; Hinweis, Lagerort und Notizen bleiben.
      </p>
    </div>

    <div class="tw:mt-3 tw:overflow-x-auto">
      <table class="tw:w-full tw:border-collapse tw:text-[13px]">
        <thead>
          <tr>
            <th scope="col" class="tw:w-36 tw:border-b tw:border-line tw:p-2 tw:text-left tw:align-bottom tw:text-[12px] tw:font-semibold tw:opacity-60">Feld</th>
            <th
              v-for="r in results"
              :key="r.id"
              scope="col"
              class="tw:min-w-52 tw:border-b tw:border-line tw:p-2 tw:text-left tw:align-top tw:font-normal"
              :class="r.applied ? 'tw:bg-brand-50' : ''"
            >
              <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-1.5">
                <span class="tw:font-semibold">{{ r.label }}</span>
                <span v-if="r.primary" class="tw:rounded-full tw:bg-stone-200 tw:px-1.5 tw:py-px tw:text-[11px] tw:font-semibold">Hauptzeile</span>
              </div>
              <div class="tw:text-[12px] tw:break-all tw:opacity-70">{{ r.modelUsed ?? r.model }}</div>
              <template v-if="!r.error">
                <div class="tw:mt-2 tw:flex tw:items-center tw:gap-2">
                  <span class="tw:h-1.5 tw:flex-1 tw:overflow-hidden tw:rounded-full tw:bg-stone-200" aria-hidden="true">
                    <span class="tw:block tw:h-full" :class="confidenceColor(r.confidence)" :style="{ width: r.confidence === null ? '0%' : `${Math.round(r.confidence * 100)}%` }" />
                  </span>
                  <span class="tw:text-[12px] tw:font-semibold tw:tabular-nums">Sicherheit {{ pct(r.confidence) }}</span>
                </div>
                <div class="tw:mt-1 tw:text-[12px] tw:tabular-nums tw:opacity-70">
                  {{ [seconds(r.durationMs), usage(r), cost(r)].filter(Boolean).join(' · ') }}
                </div>
                <p v-if="r.isCollectible === false" class="tw:m-0 tw:mt-1 tw:text-[12px] tw:text-amber-800">kein Sammelobjekt erkannt</p>
                <div class="tw:mt-2">
                  <span v-if="r.applied" class="tw:inline-flex tw:items-center tw:gap-1 tw:rounded-full tw:bg-brand-700 tw:px-2 tw:py-0.5 tw:text-[12px] tw:font-semibold tw:text-white">
                    <i class="fa fa-check" aria-hidden="true" /> im Formular
                  </span>
                  <button
                    v-else
                    type="button"
                    class="btn btn-default btn-outline btn-sm"
                    :disabled="busy"
                    :aria-label="`Vorschlag von ${r.label} (${r.model}) übernehmen`"
                    @click="emit('apply', r.id)"
                  >
                    <i class="fa fa-arrow-down" aria-hidden="true" /> Übernehmen
                  </button>
                </div>
              </template>
              <p v-else class="tw:m-0 tw:mt-2 tw:rounded-lg tw:bg-red-50 tw:p-2 tw:text-[12px] tw:whitespace-pre-line tw:text-red-700">{{ r.error }}</p>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.key" :class="differs(row.key) ? 'tw:bg-amber-50' : ''">
            <th scope="row" class="tw:border-b tw:border-line tw:p-2 tw:text-left tw:align-top tw:font-medium tw:opacity-80">
              {{ row.label }}
              <span v-if="differs(row.key)" class="tw:sr-only">(unterschiedlich)</span>
            </th>
            <td
              v-for="r in results"
              :key="r.id"
              class="tw:border-b tw:border-line tw:p-2 tw:align-top tw:whitespace-pre-line tw:wrap-break-word"
              :class="r.applied && !differs(row.key) ? 'tw:bg-brand-50' : ''"
            >
              <span v-if="r.error" class="tw:opacity-40">–</span>
              <template v-else>{{ r.values[row.key] ?? '–' }}</template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <button v-if="hasLong" type="button" class="btn btn-default btn-ghost btn-sm tw:mt-2" :aria-expanded="showLong" @click="showLong = !showLong">
      <i class="fa" :class="showLong ? 'fa-chevron-up' : 'fa-chevron-down'" aria-hidden="true" />
      {{ showLong ? 'Beschreibung und Notizen ausblenden' : 'Beschreibung und Notizen anzeigen' }}
    </button>
  </section>
</template>
