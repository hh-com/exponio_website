<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import SearchSelect from './SearchSelect.vue'
import TopicPicker from './TopicPicker.vue'
import { ApiError, createApi, type ItemData, type MergedInfo, type Meta, type QueueItem } from './api'
import { AiProgressToast } from './aiProgress'
import { toast } from '../lib/toast'

const props = defineProps<{ api: string; csrf: string; fake: boolean }>()
const api = createApi(props.api, props.csrf)

const queue = ref<QueueItem[]>([])
const meta = shallowRef<Meta>({ areas: [], categories: [], topics: [], sides: [] })
const filter = ref<'all' | 'pending' | 'review' | 'duplicate' | 'error'>('all')
const currentId = ref<number | null>(null)
const original = ref<ItemData | null>(null)
const form = ref<ItemData | null>(null)
const busy = ref<'' | 'load' | 'save' | 'recognize' | 'scan' | 'batch' | 'duplicate'>('load')
const batch = ref({ done: 0, total: 0 })
const errors = ref<Record<string, string>>({})
const activeImage = ref(0)
const queueAside = ref<HTMLElement | null>(null)
const queueList = ref<HTMLElement | null>(null)
const editor = ref<HTMLElement | null>(null)

const STATUS: Record<string, { label: string; cls: string }> = {
  draft: { label: 'Unerkannt', cls: 'tw:bg-stone-200 tw:text-stone-700' },
  review: { label: 'Zu prüfen', cls: 'tw:bg-amber-100 tw:text-amber-800' },
  published: { label: 'Veröffentlicht', cls: 'tw:bg-emerald-100 tw:text-emerald-800' },
  archived: { label: 'Archiviert', cls: 'tw:bg-stone-700 tw:text-white' },
}

const filtered = computed(() => queue.value.filter((q) => {
  if (filter.value === 'pending') return !q.recognized && !q.error
  if (filter.value === 'review') return q.status === 'review'
  if (filter.value === 'duplicate') return q.duplicateOf !== null || q.proposal
  if (filter.value === 'error') return q.error
  return true
}))
const pending = computed(() => queue.value.filter((q) => !q.recognized && !q.error))
/** Stelle des geöffneten Objekts in der (gefilterten) Liste, -1 wenn nicht enthalten */
const position = computed(() => filtered.value.findIndex((q) => q.id === currentId.value))
const area = computed(() => meta.value.areas.find((a) => a.id === form.value?.areaId) ?? null)
const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== JSON.stringify(original.value))
const confidence = computed(() => form.value?.ai.confidence ?? null)

/** Meldung als Toast (assets/lib/toast.ts) */
function flash(type: 'success' | 'danger' | 'info', text: string) {
  toast(type === 'danger' ? 'error' : type, text)
}

function setItem(item: ItemData) {
  original.value = JSON.parse(JSON.stringify(item))
  form.value = JSON.parse(JSON.stringify(item))
  errors.value = {}
  activeImage.value = 0
  const entry = queue.value.find((q) => q.id === item.id)
  if (entry) {
    Object.assign(entry, {
      title: item.title ?? '',
      status: item.status,
      confidence: item.ai.confidence,
      recognized: !!item.ai.recognizedAt,
      error: !!item.ai.error,
      thumb: item.images[0]?.thumb ?? null,
      duplicateOf: item.duplicate?.id ?? null,
      proposal: item.proposals.length > 0,
    })
  }
}

/** Objekt aus der Warteschlange nehmen und das nächste öffnen */
async function dropFromQueue(id: number) {
  const next = nextId(id)
  queue.value = queue.value.filter((q) => q.id !== id)
  if (id !== currentId.value) return
  if (next) await select(next, true)
  else { form.value = null; original.value = null; currentId.value = null }
}

const mergedText = (m: MergedInfo) => `Duplikat: Der Upload gehört jetzt zu #${m.id} „${m.title}“ (${m.summary}, ${m.uploadCount}× hochgeladen).`

async function loadQueue(selectFirst = true) {
  busy.value = 'load'
  try {
    const data = await api.queue()
    queue.value = data.items
    meta.value = data.meta
    if (selectFirst && (currentId.value === null || !queue.value.some((q) => q.id === currentId.value))) {
      const first = queue.value[0]
      if (first) await select(first.id, true)
      else { currentId.value = null; form.value = null }
    }
  } catch (e) {
    flash('danger', (e as Error).message)
  } finally {
    busy.value = ''
  }
}

async function select(id: number, force = false) {
  if (!force && dirty.value && !confirm('Ungespeicherte Änderungen verwerfen?')) return
  currentId.value = id
  try {
    const data = await api.item(id)
    // inzwischen ein anderes Objekt gewählt (Anfragen laufen parallel): veraltete Antwort verwerfen
    if (currentId.value === id) setItem(data.item)
  } catch (e) {
    flash('danger', (e as Error).message)
  }
}

/** vorheriges/nächstes Objekt der Liste öffnen (Pfeilknöpfe, Alt+↑/↓) */
function step(offset: number) {
  const target = filtered.value[position.value + offset]
  if (target) select(target.id)
}

/** Geöffnetes Objekt in der Liste sichtbar machen – nur die Liste scrollt (mobil waagrecht, sonst senkrecht), nicht die Seite */
function revealActive() {
  const entry = queueList.value?.querySelector<HTMLElement>('[aria-current="true"]')
  if (!entry) return
  const inner = entry.getBoundingClientRect()
  for (const box of [queueList.value, queueAside.value]) {
    if (!box) continue
    const outer = box.getBoundingClientRect()
    if (box.scrollWidth > box.clientWidth) box.scrollLeft += Math.min(0, inner.left - outer.left - 8) + Math.max(0, inner.right - outer.right + 8)
    if (box.scrollHeight > box.clientHeight) box.scrollTop += Math.min(0, inner.top - outer.top - 8) + Math.max(0, inner.bottom - outer.bottom + 8)
  }
}
watch([currentId, filter], revealActive, { flush: 'post' })

// Nach dem Wechsel (z. B. „Freigeben & weiter“ ganz unten) beim neuen Objekt oben anfangen – bei den Fotos
watch(() => form.value?.id, () => {
  if (editor.value && editor.value.getBoundingClientRect().top < 0) editor.value.scrollIntoView({ block: 'start' })
}, { flush: 'post' })

function nextId(after: number): number | null {
  const list = filtered.value
  const index = list.findIndex((q) => q.id === after)
  return list[index + 1]?.id ?? list[index - 1]?.id ?? null
}

async function save(action: 'save' | 'publish' | 'archive' = 'save') {
  if (!form.value) return
  busy.value = 'save'
  errors.value = {}
  const id = form.value.id
  try {
    const data = await api.save(id, { ...form.value, action })
    if (action === 'save') {
      setItem(data.item)
      flash('success', 'Gespeichert.')
    } else {
      const next = nextId(id)
      queue.value = queue.value.filter((q) => q.id !== id)
      flash('success', action === 'publish' ? `„${data.item.title}“ veröffentlicht.` : 'Archiviert.')
      if (next) await select(next, true)
      else { form.value = null; original.value = null; currentId.value = null }
    }
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = (e.data.errors as Record<string, string>) ?? {}
      flash('danger', 'Bitte die markierten Felder prüfen.')
    } else {
      flash('danger', (e as Error).message)
    }
  } finally {
    busy.value = ''
  }
}

/**
 * Erkennt ein Objekt. Mit progress zeigt der Toast den Zwischenstand vom Server; ohne quiet wird er
 * am Ende zur Ergebnis-Meldung (im Stapel meldet recognizeAll() das Gesamtergebnis).
 */
async function recognize(id: number, quiet = false, progress?: AiProgressToast): Promise<'ok' | 'merged' | 'failed'> {
  const report = (type: 'success' | 'danger' | 'info', text: string) => {
    if (quiet) return
    if (progress) progress.finish(type === 'danger' ? 'error' : type, text)
    else flash(type, text)
  }
  try {
    const data = await api.recognize(id, progress?.id)
    meta.value = data.meta
    if (data.merged) {
      await dropFromQueue(id)
      report('success', mergedText(data.merged))
      return 'merged'
    }
    const item = data.item
    if (!item) {
      report('danger', 'Erkennung fehlgeschlagen: leere Antwort.')
      return 'failed'
    }
    if (id === currentId.value) setItem(item)
    else {
      const entry = queue.value.find((q) => q.id === id)
      if (entry) Object.assign(entry, { title: item.title, status: item.status, confidence: item.ai.confidence, recognized: !!item.ai.recognizedAt, error: !!item.ai.error, duplicateOf: item.duplicate?.id ?? null })
    }
    if (!data.ok) report('danger', `Erkennung fehlgeschlagen: ${data.error}`)
    else if (item.duplicate) report('info', `KI-Vorschlag übernommen. Mögliches Duplikat von #${item.duplicate.id} – bitte entscheiden.`)
    else report('success', 'KI-Vorschlag übernommen – bitte prüfen.')
    return data.ok ? 'ok' : 'failed'
  } catch (e) {
    report('danger', (e as Error).message)
    return 'failed'
  }
}

async function recognizeCurrent() {
  if (!form.value) return
  if (dirty.value && !confirm('Die KI überschreibt die Felder. Ungespeicherte Änderungen gehen verloren. Fortfahren?')) return
  busy.value = 'recognize'
  await recognize(form.value.id, false, new AiProgressToast('KI-Erkennung läuft'))
  busy.value = ''
}

async function recognizeAll() {
  const ids = pending.value.map((q) => q.id)
  if (!ids.length) return
  busy.value = 'batch'
  batch.value = { done: 0, total: ids.length }
  const progress = new AiProgressToast(`KI-Erkennung: 1 von ${ids.length}`)
  let failed = 0
  let merged = 0
  for (const [n, id] of ids.entries()) {
    if (n > 0) {
      progress.next('Anfrage wird gesendet …')
      progress.toast.update({ title: `KI-Erkennung: ${n + 1} von ${ids.length}` })
    }
    const result = await recognize(id, true, progress)
    if (result === 'failed') failed++
    if (result === 'merged') merged++
    batch.value.done++
  }
  busy.value = ''
  progress.finish(failed ? 'error' : 'success', `${ids.length - failed} von ${ids.length} erkannt${merged ? `, davon ${merged} als Duplikat einem vorhandenen Objekt zugeordnet` : ''}${failed ? `, ${failed} fehlgeschlagen (Filter „Fehler“)` : ''}.`)
}

async function decideDuplicate(action: 'keep' | 'replace' | 'separate') {
  if (!form.value?.duplicate) return
  if (dirty.value && !confirm('Ungespeicherte Änderungen gehen verloren. Fortfahren?')) return
  const id = form.value.id
  busy.value = 'duplicate'
  try {
    const data = await api.duplicate(id, action)
    if (data.merged) {
      flash('success', mergedText(data.merged))
      await dropFromQueue(id)
    } else if (data.item) {
      setItem(data.item)
      flash('success', 'Als eigenes Objekt bestätigt.')
    }
  } catch (e) {
    flash('danger', (e as Error).message)
  } finally {
    busy.value = ''
  }
}

async function decideProposal(uploadId: number, action: 'accept' | 'reject') {
  if (!form.value) return
  if (dirty.value && !confirm('Ungespeicherte Änderungen gehen verloren. Fortfahren?')) return
  const id = form.value.id
  busy.value = 'duplicate'
  try {
    const data = await api.proposal(id, uploadId, action)
    setItem(data.item)
    flash('success', action === 'accept' ? 'Neue Fotos übernommen. Die bisherigen bleiben als „früher“ erhalten.' : 'Vorschlag abgelehnt. Die Fotos bleiben, wie sie sind.')
    // veröffentlichte Objekte ohne weitere offene Punkte verlassen die Warteschlange
    const item = data.item
    if (!['draft', 'review'].includes(item.status) && !item.proposals.length && !item.duplicate) await dropFromQueue(id)
  } catch (e) {
    flash('danger', (e as Error).message)
  } finally {
    busy.value = ''
  }
}

async function scan() {
  busy.value = 'scan'
  const status = toast('progress', 'Neue Upload-Ordner werden importiert …', { title: 'Ordner scannen' })
  try {
    const result = await api.scan()
    const failed = Object.entries(result.failed)
    status.update({ type: failed.length ? 'error' : 'success', title: null, message: `${result.imported} neue Ordner importiert.${failed.length ? ' Fehler: ' + failed.map(([f, m]) => `${f}: ${m}`).join('; ') : ''}` })
    busy.value = ''
    await loadQueue(form.value === null)
  } catch (e) {
    status.update({ type: 'error', title: null, message: (e as Error).message })
    busy.value = ''
  }
}

async function moveImage(index: number, step: number) {
  if (!form.value) return
  const ids = form.value.images.map((i) => i.id)
  const target = index + step
  if (target < 0 || target >= ids.length) return
  ;[ids[index], ids[target]] = [ids[target], ids[index]]
  const data = await api.images(form.value.id, ids, [])
  form.value.images = data.item.images
  if (original.value) original.value.images = data.item.images
  activeImage.value = target
}

async function deleteImage(id: number) {
  if (!form.value || !confirm('Bild endgültig löschen?')) return
  const data = await api.images(form.value.id, form.value.images.map((i) => i.id), [id])
  form.value.images = data.item.images
  if (original.value) original.value.images = data.item.images
  activeImage.value = 0
}

async function createCategory(label: string) {
  if (!form.value) return
  const parent = meta.value.categories.find((c) => c.id === form.value?.categoryId)
  const asChild = parent && confirm(`„${label}“ unter „${parent.label}“ anlegen? (Abbrechen = als oberstes Land)`)
  const created = await api.createCategory(label, asChild ? parent.id : null)
  meta.value = { ...meta.value, categories: [...meta.value.categories, created].sort((a, b) => a.label.localeCompare(b.label, 'de')) }
  form.value.categoryId = created.id
}

async function createTopic(name: string) {
  if (!form.value) return
  const created = await api.createTopic(name)
  if (!meta.value.topics.some((t) => t.id === created.id)) meta.value = { ...meta.value, topics: [...meta.value.topics, created] }
  form.value.topicIds = [...form.value.topicIds, created.id]
}

function onKeydown(event: KeyboardEvent) {
  const mod = event.ctrlKey || event.metaKey
  // wie die Knöpfe: nicht speichern, solange eine andere Aktion läuft (z. B. „Alle erkennen“)
  if (mod && event.key === 'Enter') { event.preventDefault(); if (!busy.value) save('publish') }
  else if (mod && event.key.toLowerCase() === 's') { event.preventDefault(); if (!busy.value) save('save') }
  else if (event.altKey && (event.key === 'ArrowDown' || event.key === 'ArrowUp') && currentId.value !== null) {
    event.preventDefault()
    step(event.key === 'ArrowDown' ? 1 : -1)
  }
}
function onBeforeUnload(event: BeforeUnloadEvent) {
  if (dirty.value) event.preventDefault()
}

onMounted(() => {
  loadQueue()
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('beforeunload', onBeforeUnload)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', onBeforeUnload)
})

const pct = (v: number | null) => (v === null ? '–' : `${Math.round(v * 100)} %`)
const confidenceColor = (v: number | null) => (v === null ? 'tw:bg-stone-300' : v >= 0.85 ? 'tw:bg-emerald-500' : v >= 0.6 ? 'tw:bg-amber-500' : 'tw:bg-red-500')
</script>

<template>
  <div class="tw:text-[14px]">
    <!-- Werkzeugleiste -->
    <div class="tw:mb-4 tw:flex tw:flex-wrap tw:items-center tw:gap-2">
      <button type="button" class="btn btn-default btn-outline" :disabled="!!busy" @click="scan">
        <i class="fa fa-folder-open" /> {{ busy === 'scan' ? 'Scanne …' : 'Ordner scannen' }}
      </button>
      <button type="button" class="btn btn-primary btn-solid" :disabled="!!busy || !pending.length" @click="recognizeAll">
        <i class="fa fa-wand-magic-sparkles" />
        <template v-if="busy === 'batch'">Erkenne {{ batch.done }} / {{ batch.total }} …</template>
        <template v-else>Alle unerkannten erkennen ({{ pending.length }})</template>
      </button>
      <div class="tw:ml-auto tw:flex tw:flex-wrap tw:gap-1">
        <button
          v-for="f in ([['all', 'Alle'], ['pending', 'Unerkannt'], ['review', 'Zu prüfen'], ['duplicate', 'Duplikate'], ['error', 'Fehler']] as const)"
          :key="f[0]"
          type="button"
          class="tw:rounded-full tw:px-3 tw:py-1 tw:text-[13px] tw:font-medium tw:ring-1 tw:ring-line"
          :class="filter === f[0] ? 'tw:bg-brand-700 tw:text-white tw:ring-brand-700' : 'tw:bg-white tw:hover:bg-soft'"
          @click="filter = f[0]"
        >
          {{ f[1] }}
        </button>
      </div>
    </div>

    <div v-if="fake" class="alert alert-warning tw:mb-4">
      Testmodus: <code>AI_PROVIDER=fake</code> – es werden Beispieldaten statt echter KI-Ergebnisse erzeugt.
    </div>

    <div v-if="busy === 'load' && !queue.length" class="tw:py-10 tw:text-center tw:opacity-60">Lädt …</div>

    <div v-else-if="!queue.length" class="tw:rounded-2xl tw:border tw:border-dashed tw:border-line tw:bg-white tw:px-6 tw:py-14 tw:text-center">
      <p class="tw:text-lg tw:font-semibold">Alles erledigt 🎉</p>
      <p class="tw:mt-1 tw:opacity-70">Keine Objekte zu prüfen. Neue Fotos mit der App hochladen und „Ordner scannen“ klicken.</p>
    </div>

    <!-- grid-cols-1 = minmax(0, 1fr): sonst macht die waagrechte Objektliste die Seite mobil so breit wie alle Einträge zusammen -->
    <div v-else class="tw:grid tw:grid-cols-1 tw:gap-5 tw:lg:grid-cols-[260px_minmax(0,1fr)]">
      <!-- Warteschlange: mobil ein waagrechter Streifen, ab lg eine Spalte -->
      <aside ref="queueAside" class="tw:min-w-0 tw:lg:sticky tw:lg:top-4 tw:lg:max-h-[calc(100vh-2rem)] tw:lg:self-start tw:lg:overflow-y-auto">
        <div class="tw:mb-2 tw:flex tw:items-center tw:justify-between tw:gap-2">
          <p class="tw:m-0 tw:text-[12px] tw:font-semibold tw:tracking-wide tw:uppercase tw:opacity-60">
            {{ position >= 0 ? `Objekt ${position + 1} von ${filtered.length}` : `${filtered.length} Objekte` }}
          </p>
          <div class="tw:flex tw:gap-1 tw:lg:hidden">
            <button type="button" class="btn btn-default btn-outline btn-sm" title="Vorheriges Objekt" aria-label="Vorheriges Objekt" :disabled="position <= 0" @click="step(-1)">
              <i class="fa fa-chevron-left" />
            </button>
            <button type="button" class="btn btn-default btn-outline btn-sm" title="Nächstes Objekt" aria-label="Nächstes Objekt" :disabled="position >= filtered.length - 1" @click="step(1)">
              <i class="fa fa-chevron-right" />
            </button>
          </div>
        </div>
        <ul ref="queueList" class="tw:m-0 tw:flex tw:snap-x tw:list-none tw:gap-2 tw:overflow-x-auto tw:overscroll-x-contain tw:p-0 tw:pb-2 tw:lg:flex-col tw:lg:overflow-visible">
          <li v-for="entry in filtered" :key="entry.id" class="tw:w-56 tw:shrink-0 tw:snap-start tw:lg:w-auto">
            <button
              type="button"
              class="tw:flex tw:w-full tw:items-center tw:gap-3 tw:rounded-xl tw:border tw:p-2 tw:text-left tw:transition"
              :class="entry.id === currentId ? 'tw:border-brand-500 tw:bg-brand-50 tw:shadow-sm' : 'tw:border-line tw:bg-white tw:hover:border-brand-200'"
              :aria-current="entry.id === currentId ? 'true' : undefined"
              @click="select(entry.id)"
            >
              <span class="tw:grid tw:size-12 tw:shrink-0 tw:place-items-center tw:overflow-hidden tw:rounded-lg tw:bg-soft">
                <img v-if="entry.thumb" :src="entry.thumb" alt="" class="tw:size-full tw:object-contain tw:p-0.5">
              </span>
              <span class="tw:min-w-0 tw:flex-1">
                <span class="tw:block tw:truncate tw:font-medium">#{{ entry.id }} {{ entry.title }}</span>
                <span class="tw:mt-1 tw:flex tw:flex-wrap tw:items-center tw:gap-1.5 tw:whitespace-nowrap">
                  <span class="tw:rounded-full tw:px-1.5 tw:py-px tw:text-[11px] tw:font-semibold" :class="entry.error ? 'tw:bg-red-100 tw:text-red-700' : STATUS[entry.status]?.cls">
                    {{ entry.error ? 'Fehler' : STATUS[entry.status]?.label }}
                  </span>
                  <span v-if="entry.duplicateOf" class="tw:rounded-full tw:bg-violet-100 tw:px-1.5 tw:py-px tw:text-[11px] tw:font-semibold tw:text-violet-800">Duplikat?</span>
                  <span v-if="entry.proposal" class="tw:rounded-full tw:bg-sky-100 tw:px-1.5 tw:py-px tw:text-[11px] tw:font-semibold tw:text-sky-800">Neue Fotos</span>
                  <span v-if="entry.confidence !== null" class="tw:h-1.5 tw:min-w-8 tw:flex-1 tw:overflow-hidden tw:rounded-full tw:bg-stone-200">
                    <span class="tw:block tw:h-full" :class="confidenceColor(entry.confidence)" :style="{ width: pct(entry.confidence).replace(' ', '') }" />
                  </span>
                </span>
              </span>
            </button>
          </li>
        </ul>
      </aside>

      <!-- Editor -->
      <section v-if="form" ref="editor" class="tw:min-w-0 tw:scroll-mt-4">
        <div class="tw:mb-4 tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3">
          <div class="tw:min-w-0">
            <h2 class="tw:m-0 tw:text-xl tw:font-semibold tw:wrap-break-word">#{{ form.id }} {{ form.title }}</h2>
            <p class="tw:m-0 tw:mt-1 tw:text-[13px] tw:opacity-70">
              <span class="tw:rounded-full tw:px-2 tw:py-0.5 tw:text-[11px] tw:font-semibold" :class="STATUS[form.status]?.cls">{{ STATUS[form.status]?.label }}</span>
              <template v-if="form.sourceFolder"> · Ordner {{ form.sourceFolder }}</template>
              <template v-if="form.uploadCount > 1"> · {{ form.uploadCount }}× hochgeladen</template>
              <template v-if="form.ai.model"> · {{ form.ai.model }}</template>
            </p>
          </div>
          <div class="tw:flex tw:flex-wrap tw:gap-2">
            <button type="button" class="btn btn-default btn-outline" :disabled="!!busy || !form.images.length" @click="recognizeCurrent">
              <i class="fa fa-wand-magic-sparkles" /> {{ busy === 'recognize' ? 'Erkenne …' : (form.ai.recognizedAt ? 'Erneut erkennen' : 'Jetzt erkennen') }}
            </button>
            <a :href="form.urls.edit" class="btn btn-default btn-ghost"><i class="fa fa-pen" /> Vollständig bearbeiten</a>
            <a :href="form.urls.public" target="_blank" class="btn btn-default btn-ghost"><i class="fa fa-eye" /> Vorschau</a>
          </div>
        </div>

        <!-- Mögliches Duplikat -->
        <section v-if="form.duplicate" class="tw:mb-5 tw:rounded-2xl tw:border tw:border-violet-300 tw:bg-violet-50 tw:p-4" aria-labelledby="duplicate-title">
          <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3">
            <div class="tw:min-w-0 tw:flex-1">
              <h3 id="duplicate-title" class="tw:m-0 tw:text-[15px] tw:font-semibold">
                <i class="fa fa-clone" /> Mögliches Duplikat von
                <a :href="form.duplicate.urls.detail" target="_blank">#{{ form.duplicate.id }} {{ form.duplicate.title }}</a>
              </h3>
              <p class="tw:m-0 tw:mt-1 tw:text-[13px] tw:opacity-80">
                Sicherheit {{ pct(form.duplicate.confidence) }}
                <template v-if="form.duplicate.specimen"> · {{ form.duplicate.specimen }}</template>
                <template v-if="form.duplicate.condition"> · {{ form.duplicate.condition }}</template>
                <template v-if="form.duplicate.photos"> · {{ form.duplicate.photos }}</template>
                · bisher {{ form.duplicate.uploadCount }}× hochgeladen · {{ STATUS[form.duplicate.status]?.label }}
              </p>
              <p v-if="form.duplicate.reason" class="tw:m-0 tw:mt-2 tw:whitespace-pre-line">{{ form.duplicate.reason }}</p>
              <p v-if="form.duplicate.better" class="tw:m-0 tw:mt-2 tw:font-medium">Die KI hält diesen Upload für besser (Erhaltung zuerst, dann Fotoqualität).</p>
            </div>
            <div class="tw:flex tw:flex-wrap tw:gap-2">
              <a v-for="(image, i) in form.duplicate.images.slice(0, 3)" :key="image.id" :href="image.full" target="_blank" class="tw:grid tw:size-20 tw:place-items-center tw:overflow-hidden tw:rounded-xl tw:bg-white">
                <img :src="image.thumb" :alt="`Foto ${i + 1} von #${form.duplicate.id}`" class="tw:size-full tw:object-contain tw:p-1">
              </a>
            </div>
          </div>
          <!-- mobil untereinander in voller Breite, lange Beschriftungen dürfen umbrechen -->
          <div class="tw:mt-3 tw:flex tw:flex-wrap tw:gap-2 tw:max-sm:*:w-full tw:max-sm:*:whitespace-normal">
            <button type="button" class="btn btn-primary btn-solid" :disabled="!!busy" @click="decideDuplicate('keep')">
              <i class="fa fa-object-group" /> Als Duplikat zusammenführen – Bilder behalten
            </button>
            <button type="button" class="btn btn-default btn-outline" :disabled="!!busy" @click="decideDuplicate('replace')">
              <i class="fa fa-images" /> Als Duplikat zusammenführen – Bilder ersetzen
            </button>
            <button type="button" class="btn btn-default btn-ghost" :disabled="!!busy" @click="decideDuplicate('separate')">
              <i class="fa fa-code-branch" /> Ist ein eigenes Objekt
            </button>
          </div>
          <p class="tw:m-0 tw:mt-2 tw:text-[12px] tw:opacity-70">
            Beim Zusammenführen verschwindet dieser Entwurf und der Upload wird bei #{{ form.duplicate.id }} gezählt.
            Ersetzte Bilder bleiben als „früher“ erhalten und lassen sich in der Upload-Historie des Objekts zurückholen.
          </p>
        </section>

        <!-- Vorgeschlagene bessere Fotos (veröffentlichte Objekte) -->
        <section v-for="proposal in form.proposals" :key="proposal.id" class="tw:mb-5 tw:rounded-2xl tw:border tw:border-sky-300 tw:bg-sky-50 tw:p-4" :aria-labelledby="`proposal-${proposal.id}`">
          <div class="tw:flex tw:flex-wrap tw:items-start tw:justify-between tw:gap-3">
            <div class="tw:min-w-0 tw:flex-1">
              <h3 :id="`proposal-${proposal.id}`" class="tw:m-0 tw:text-[15px] tw:font-semibold">
                <i class="fa fa-images" /> Bessere Fotos vorgeschlagen<template v-if="proposal.folder"> (Upload {{ proposal.folder }})</template>
              </h3>
              <p class="tw:m-0 tw:mt-1 tw:text-[13px] tw:opacity-80">
                Das Objekt ist veröffentlicht – neue Fotos werden erst nach deiner Bestätigung gezeigt.
                <template v-if="proposal.specimen"> · {{ proposal.specimen }}</template>
              </p>
              <p v-if="proposal.reason" class="tw:m-0 tw:mt-2 tw:whitespace-pre-line">{{ proposal.reason }}</p>
            </div>
            <div class="tw:flex tw:flex-wrap tw:gap-2">
              <a v-for="(image, i) in proposal.images.slice(0, 4)" :key="image.id" :href="image.full" target="_blank" class="tw:grid tw:size-20 tw:place-items-center tw:overflow-hidden tw:rounded-xl tw:bg-white">
                <img :src="image.thumb" :alt="`Vorgeschlagenes Foto ${i + 1}`" class="tw:size-full tw:object-contain tw:p-1">
              </a>
            </div>
          </div>
          <div class="tw:mt-3 tw:flex tw:flex-wrap tw:gap-2 tw:max-sm:*:w-full tw:max-sm:*:whitespace-normal">
            <button type="button" class="btn btn-success btn-solid" :disabled="!!busy" @click="decideProposal(proposal.id, 'accept')">
              <i class="fa fa-check" /> Bilder ersetzen
            </button>
            <button type="button" class="btn btn-default btn-outline" :disabled="!!busy" @click="decideProposal(proposal.id, 'reject')">
              <i class="fa fa-xmark" /> Bisherige Bilder behalten
            </button>
          </div>
        </section>

        <div class="tw:grid tw:gap-5 tw:xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
          <!-- Bilder + KI -->
          <div class="tw:space-y-4">
            <div class="tw:overflow-hidden tw:rounded-2xl tw:border tw:border-line tw:bg-soft">
              <a v-if="form.images[activeImage]" :href="form.images[activeImage].full" target="_blank" class="tw:block tw:aspect-square">
                <img :src="form.images[activeImage].medium" alt="" class="tw:size-full tw:object-contain tw:p-4">
              </a>
              <div v-else class="tw:grid tw:aspect-square tw:place-items-center tw:opacity-50">Keine Bilder</div>
            </div>
            <div v-if="form.images.length" class="tw:flex tw:flex-wrap tw:gap-2">
              <div v-for="(image, i) in form.images" :key="image.id" class="tw:group tw:relative">
                <button
                  type="button"
                  class="tw:grid tw:size-20 tw:place-items-center tw:overflow-hidden tw:rounded-xl tw:border-2 tw:bg-soft"
                  :class="i === activeImage ? 'tw:border-brand-600' : 'tw:border-transparent'"
                  @click="activeImage = i"
                >
                  <img :src="image.thumb" alt="" class="tw:size-full tw:object-contain tw:p-1">
                </button>
                <span v-if="image.side" class="tw:absolute tw:top-1 tw:left-1 tw:rounded tw:bg-brand-700 tw:px-1 tw:text-[10px] tw:font-bold tw:text-white">{{ meta.sides.find((s) => s.value === image.side)?.label }}</span>
                <div class="tw:mt-1 tw:flex tw:justify-center tw:gap-1 tw:text-[12px]">
                  <button type="button" class="btn btn-default btn-ghost btn-sm" title="nach vorne" aria-label="Bild nach vorne" :disabled="i === 0" @click="moveImage(i, -1)"><i class="fa fa-arrow-left" /></button>
                  <button type="button" class="btn btn-danger btn-ghost btn-sm" title="löschen" aria-label="Bild löschen" @click="deleteImage(image.id)"><i class="fa fa-trash" /></button>
                  <button type="button" class="btn btn-default btn-ghost btn-sm" title="nach hinten" aria-label="Bild nach hinten" :disabled="i === form.images.length - 1" @click="moveImage(i, 1)"><i class="fa fa-arrow-right" /></button>
                </div>
              </div>
            </div>

            <div v-if="form.images[activeImage]" class="tw:grid tw:gap-2 tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4 tw:sm:grid-cols-[10rem_1fr]">
              <label class="tw:block">
                <span class="form-label">Seite (Bild {{ activeImage + 1 }})</span>
                <select v-model="form.images[activeImage].side" class="form-select">
                  <option :value="null">unbekannt</option>
                  <option v-for="s in meta.sides" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
              </label>
              <label class="tw:block">
                <span class="form-label">Alt-Text (für Screenreader)</span>
                <input v-model="form.images[activeImage].alt" class="form-control" maxlength="255">
              </label>
            </div>

            <div class="tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4">
              <div class="tw:flex tw:items-center tw:justify-between">
                <h3 class="tw:m-0 tw:text-[15px] tw:font-semibold">KI-Einschätzung</h3>
                <span class="tw:font-semibold tw:tabular-nums">{{ pct(confidence) }}</span>
              </div>
              <div class="tw:mt-2 tw:h-2 tw:overflow-hidden tw:rounded-full tw:bg-stone-200">
                <div class="tw:h-full tw:transition-all" :class="confidenceColor(confidence)" :style="{ width: confidence === null ? '0%' : `${Math.round(confidence * 100)}%` }" />
              </div>
              <p v-if="form.ai.error" class="tw:mt-3 tw:mb-0 tw:rounded-lg tw:bg-red-50 tw:p-2.5 tw:text-red-700">{{ form.ai.error }}</p>
              <p v-if="form.ai.isCollectible === false" class="tw:mt-3 tw:mb-0 tw:rounded-lg tw:bg-amber-50 tw:p-2.5 tw:text-amber-800">Die KI hat kein Sammelobjekt erkannt.</p>
              <p v-if="form.ai.notes" class="tw:mt-3 tw:mb-0 tw:whitespace-pre-line tw:opacity-80">{{ form.ai.notes }}</p>
              <template v-if="form.ai.alternatives.length">
                <p class="tw:mt-3 tw:mb-1 tw:text-[12px] tw:font-semibold tw:tracking-wide tw:uppercase tw:opacity-60">Alternativen</p>
                <ul class="tw:m-0 tw:list-disc tw:pl-5 tw:opacity-80">
                  <li v-for="alt in form.ai.alternatives" :key="alt">{{ alt }}</li>
                </ul>
              </template>
              <p v-if="!form.ai.recognizedAt && !form.ai.error" class="tw:mt-3 tw:mb-0 tw:opacity-60">Noch nicht erkannt.</p>
            </div>
          </div>

          <!-- Formular -->
          <form class="tw:space-y-5" @submit.prevent="save('save')">
            <fieldset class="tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4">
              <legend class="tw:float-none tw:mb-3 tw:w-auto tw:px-1 tw:text-[15px] tw:font-semibold">Einordnung</legend>
              <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                <label class="tw:block">
                  <span class="form-label">Objektart</span>
                  <select v-model="form.areaId" class="form-select">
                    <option :value="null">—</option>
                    <option v-for="a in meta.areas" :key="a.id" :value="a.id">{{ a.name }}</option>
                  </select>
                </label>
                <div>
                  <span class="form-label">Land</span>
                  <SearchSelect v-model="form.categoryId" :options="meta.categories" allow-create @create="createCategory" />
                </div>
                <div class="tw:sm:col-span-2">
                  <span class="form-label">Themen</span>
                  <TopicPicker v-model="form.topicIds" :topics="meta.topics" @create="createTopic" />
                </div>
              </div>
            </fieldset>

            <fieldset class="tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4">
              <legend class="tw:float-none tw:mb-3 tw:w-auto tw:px-1 tw:text-[15px] tw:font-semibold">Texte (Deutsch – die anderen Sprachen übersetzt die KI)</legend>
              <div class="tw:grid tw:gap-3">
                <label class="tw:block">
                  <span class="form-label">Titel *</span>
                  <input v-model="form.title" class="form-control" :class="errors.title ? 'is-invalid' : ''">
                  <span v-if="errors.title" class="invalid-feedback tw:block">{{ errors.title }}</span>
                </label>
                <label class="tw:block">
                  <span class="form-label">Beschreibung</span>
                  <textarea v-model="form.description" class="form-control" rows="5" />
                </label>
                <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                  <label class="tw:block">
                    <span class="form-label">Mängel</span>
                    <textarea v-model="form.defects" class="form-control" rows="2" placeholder="z. B. kurzer Zahn oben rechts" />
                  </label>
                  <label class="tw:block">
                    <span class="form-label">Meta-Beschreibung (Suchmaschinen)</span>
                    <textarea v-model="form.metaDescription" class="form-control" rows="2" maxlength="300" />
                  </label>
                </div>
              </div>
            </fieldset>

            <fieldset class="tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4">
              <legend class="tw:float-none tw:mb-3 tw:w-auto tw:px-1 tw:text-[15px] tw:font-semibold">Ausgabe</legend>
              <div class="tw:grid tw:gap-3 tw:sm:grid-cols-3">
                <label class="tw:block">
                  <span class="form-label">Jahr</span>
                  <input v-model.number="form.year" type="number" class="form-control" :class="errors.year ? 'is-invalid' : ''">
                </label>
                <label class="tw:block">
                  <span class="form-label">bis Jahr</span>
                  <input v-model.number="form.yearTo" type="number" class="form-control">
                </label>
                <label class="tw:block">
                  <span class="form-label">Ausgabetag</span>
                  <input v-model="form.issueDate" type="date" class="form-control">
                </label>
                <label class="tw:block tw:sm:col-span-3">
                  <span class="form-label">Serie / Ausgabe</span>
                  <input v-model="form.series" class="form-control">
                </label>
              </div>
            </fieldset>

            <fieldset v-if="area && area.definitions.length" class="tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4">
              <legend class="tw:float-none tw:mb-3 tw:w-auto tw:px-1 tw:text-[15px] tw:font-semibold">Merkmale · {{ area.name }}</legend>
              <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                <label v-for="d in area.definitions" :key="d.code" class="tw:block">
                  <span class="form-label">{{ d.label }}<template v-if="d.unit"> ({{ d.unit }})</template></span>
                  <select v-if="d.type === 'select'" v-model="form.attributes[d.code]" class="form-select">
                    <option :value="null">—</option>
                    <option v-for="o in d.options" :key="o.value" :value="o.value">{{ o.label }}</option>
                  </select>
                  <select v-else-if="d.type === 'boolean'" v-model="form.attributes[d.code]" class="form-select">
                    <option :value="null">—</option>
                    <option value="1">Ja</option>
                    <option value="0">Nein</option>
                  </select>
                  <input v-else v-model="form.attributes[d.code]" class="form-control" :inputmode="d.type === 'number' ? 'decimal' : undefined">
                </label>
              </div>
            </fieldset>

            <fieldset class="tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4">
              <legend class="tw:float-none tw:mb-3 tw:w-auto tw:px-1 tw:text-[15px] tw:font-semibold">Schätzwert & Startseite</legend>
              <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                <label class="tw:block">
                  <span class="form-label">Schätzwert der KI (€)</span>
                  <input v-model="form.catalogValue" class="form-control" inputmode="decimal">
                </label>
                <label class="tw:flex tw:cursor-pointer tw:items-center tw:gap-2 tw:self-end tw:pb-2">
                  <input v-model="form.showEstimate" class="form-check-input tw:m-0" type="checkbox">
                  <span>Schätzwert auf der Website anzeigen</span>
                </label>
                <label class="tw:flex tw:cursor-pointer tw:items-center tw:gap-2 tw:sm:col-span-2">
                  <input v-model="form.featured" class="form-check-input tw:m-0" type="checkbox">
                  <span>Auf der Startseite hervorheben</span>
                </label>
              </div>
            </fieldset>

            <fieldset class="tw:rounded-2xl tw:border tw:border-line tw:bg-white tw:p-4">
              <legend class="tw:float-none tw:mb-3 tw:w-auto tw:px-1 tw:text-[15px] tw:font-semibold">Intern</legend>
              <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2">
                <label class="tw:block">
                  <span class="form-label">Lagerort</span>
                  <input v-model="form.storageLocation" class="form-control" placeholder="z. B. Album 3, Seite 12">
                </label>
                <label class="tw:block">
                  <span class="form-label">Notizen</span>
                  <input v-model="form.internalNotes" class="form-control">
                </label>
              </div>
            </fieldset>

            <!-- Aktionen -->
            <div class="tw:sticky tw:bottom-0 tw:z-20 tw:-mx-1 tw:flex tw:flex-wrap tw:items-center tw:gap-2 tw:border-t tw:border-line tw:bg-paper/95 tw:px-1 tw:py-3 tw:backdrop-blur">
              <!-- mobil kompakt, damit alles in eine Zeile passt -->
              <button type="button" class="btn btn-danger btn-ghost" title="Archivieren" :disabled="!!busy" @click="save('archive')">
                <i class="fa fa-box-archive" /> <span class="tw:max-sm:sr-only">Archivieren</span>
              </button>
              <span class="tw:ml-auto tw:text-[12px] tw:opacity-60 tw:max-lg:hidden">Strg+S speichern · Strg+Enter freigeben · Alt+↑/↓ wechseln</span>
              <button type="submit" class="btn btn-default btn-outline tw:max-lg:ml-auto" :disabled="!!busy">
                <i class="fa fa-floppy-disk" /> Speichern<template v-if="dirty"> •</template>
              </button>
              <button type="button" class="btn btn-success btn-solid" :disabled="!!busy" @click="save('publish')">
                <i class="fa fa-check" /> <span>Freigeben<span class="tw:max-sm:hidden"> & weiter</span></span>
              </button>
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>
</template>
