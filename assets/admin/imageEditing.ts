import { createApp } from 'vue'
import type { CropRect } from './api'

/**
 * Objekt-Formular, Tab „Bilder“ (templates/admin/form/theme.html.twig): Bild drehen und zuschneiden.
 *
 * Die Vorschau zeigt das Ergebnis sofort, die versteckten Felder „rotate“ und „cropChange“ merken es sich.
 * Beim Speichern schneidet ItemCrudController::processImages() erst zu und dreht dann. Der Zuschnitt bezieht
 * sich deshalb auf das Bild vor der neuen Drehung, der Rahmen im Dialog zeigt es aber gedreht.
 * Eine neu gewählte Datei erscheint gleich in der Vorschau und lässt sich vor dem Speichern bearbeiten.
 * Ereignis-Delegation, damit auch neu hinzugefügte Einträge funktionieren.
 */

const RESET = 'reset'

interface EditState {
  /** Drehung im Uhrzeigersinn: 0, 90, 180 oder 270 */
  rotation: number
  /** Ausschnitt des Bildes vor der Drehung, RESET hebt den gespeicherten Zuschnitt auf */
  crop: CropRect | typeof RESET | null
}

const normalize = (degrees: number) => (((Math.round(degrees / 90) * 90) % 360) + 360) % 360
const round = (v: number) => Math.max(0, Math.round(v * 1e6) / 1e6)

/** Ausschnitt des um `degrees` gedrehten Bildes → Ausschnitt des ungedrehten (wie CropBox::unrotate()) */
export function unrotateRect(r: CropRect, degrees: number): CropRect {
  const turned = (() => {
    switch (normalize(degrees)) {
      case 90: return { x: r.y, y: 1 - r.x - r.w, w: r.h, h: r.w }
      case 180: return { x: 1 - r.x - r.w, y: 1 - r.y - r.h, w: r.w, h: r.h }
      case 270: return { x: 1 - r.y - r.h, y: r.x, w: r.h, h: r.w }
      default: return { ...r }
    }
  })()
  return { x: round(turned.x), y: round(turned.y), w: round(turned.w), h: round(turned.h) }
}

/** Umkehrung von unrotateRect(): Ausschnitt des ungedrehten Bildes → Ausschnitt des gedrehten */
export const rotateRect = (r: CropRect, degrees: number): CropRect => unrotateRect(r, 360 - normalize(degrees))

function parts(entry: HTMLElement) {
  return {
    box: entry.querySelector<HTMLElement>('[data-image-box]'),
    empty: entry.querySelector<HTMLElement>('[data-image-empty]'),
    link: entry.querySelector<HTMLAnchorElement>('[data-image-link]'),
    preview: entry.querySelector<HTMLImageElement>('[data-image-preview]'),
    rotate: entry.querySelector<HTMLInputElement>('input[data-image-rotate]'),
    crop: entry.querySelector<HTMLInputElement>('input[data-image-crop]'),
    file: entry.querySelector<HTMLInputElement>('input[type="file"]'),
    hint: entry.querySelector<HTMLElement>('[data-image-hint]'),
    cropButton: entry.querySelector<HTMLButtonElement>('[data-crop-start]'),
    resetButton: entry.querySelector<HTMLButtonElement>('[data-crop-reset]'),
  }
}

function readState(entry: HTMLElement): EditState {
  const { rotate, crop } = parts(entry)
  const rotation = normalize(Number(rotate?.value) || 0)
  const raw = crop?.value ?? ''
  if (raw === RESET) return { rotation, crop: RESET }
  try {
    const rect = raw ? (JSON.parse(raw) as CropRect) : null
    return { rotation, crop: rect && [rect.x, rect.y, rect.w, rect.h].every(Number.isFinite) ? rect : null }
  } catch {
    return { rotation, crop: null }
  }
}

/** Neu gewählte Datei (noch nicht gespeichert) */
const newFile = (entry: HTMLElement) => parts(entry).file?.files?.[0] ?? null
/** Bild, auf das sich Drehung und Zuschnitt beziehen: die neue Datei oder das gespeicherte Bild (800 px) */
const sourceOf = (entry: HTMLElement) => entry.dataset.source || entry.dataset.src || ''

function loadImage(src: string): Promise<HTMLImageElement> {
  const image = new Image()
  image.src = src
  return image.decode().then(() => image)
}

/** Erst zuschneiden, dann drehen – wie MediaStorage::store() */
function render(image: HTMLImageElement, crop: CropRect | null, rotation: number): HTMLCanvasElement {
  const sx = crop ? Math.round(crop.x * image.naturalWidth) : 0
  const sy = crop ? Math.round(crop.y * image.naturalHeight) : 0
  const sw = Math.max(1, crop ? Math.round(crop.w * image.naturalWidth) : image.naturalWidth)
  const sh = Math.max(1, crop ? Math.round(crop.h * image.naturalHeight) : image.naturalHeight)
  const quarter = rotation % 180 !== 0
  const canvas = document.createElement('canvas')
  canvas.width = quarter ? sh : sw
  canvas.height = quarter ? sw : sh
  const ctx = canvas.getContext('2d')
  if (ctx) {
    ctx.translate(canvas.width / 2, canvas.height / 2)
    ctx.rotate((rotation * Math.PI) / 180)
    ctx.drawImage(image, sx, sy, sw, sh, -sw / 2, -sh / 2, sw, sh)
  }
  return canvas
}

function canvasUrl(canvas: HTMLCanvasElement): Promise<string> {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(URL.createObjectURL(blob)) : reject(new Error('Vorschau nicht möglich'))), 'image/jpeg', 0.9)
  })
}

/** Vorschau-URLs je Eintrag, damit alte Blobs freigegeben werden */
const previewUrls = new WeakMap<HTMLElement, string>()
/** Zähler je Eintrag: Nur die letzte Änderung setzt die Vorschau (bei schnellem Klicken) */
const renders = new WeakMap<HTMLElement, number>()

function describe(state: EditState): string {
  const steps: string[] = []
  if (state.crop === RESET) steps.push('der Zuschnitt aufgehoben (wieder das ganze Foto)')
  else if (state.crop) steps.push('zugeschnitten')
  if (state.rotation) steps.push(`um ${state.rotation}° gedreht`)
  return steps.length ? `Wird beim Speichern ${steps.join(' und ')}.` : ''
}

/** Vorschau, Hinweis und Knöpfe an Drehung und Zuschnitt anpassen */
async function update(entry: HTMLElement): Promise<void> {
  const { box, empty, link, preview, hint, cropButton, resetButton } = parts(entry)
  const state = readState(entry)
  const source = sourceOf(entry)
  const canReset = entry.dataset.cropped === '1' && !newFile(entry)

  if (box) box.hidden = !source
  if (empty) empty.hidden = !!source
  if (hint) hint.textContent = describe(state)
  entry.classList.toggle('is-edited', !!state.rotation || state.crop !== null)
  if (resetButton) {
    resetButton.hidden = !canReset
    resetButton.setAttribute('aria-pressed', String(state.crop === RESET))
    resetButton.title = state.crop === RESET ? 'Zuschnitt doch behalten' : 'Zuschnitt aufheben (ganzes Foto)'
  }
  if (cropButton) {
    cropButton.disabled = state.crop === RESET
    cropButton.title = state.crop === RESET ? 'Erst speichern, dann neu zuschneiden' : 'Zuschneiden'
  }
  if (link) link.href = newFile(entry) ? source : entry.dataset.full || '#'
  if (!preview || !source) return

  const run = (renders.get(entry) ?? 0) + 1
  renders.set(entry, run)
  const crop = state.crop === RESET ? null : state.crop
  let url = source
  if (crop || state.rotation) {
    try {
      url = await canvasUrl(render(await loadImage(source), crop, state.rotation))
    } catch {
      url = source
    }
  }
  if (renders.get(entry) !== run) {
    if (url !== source) URL.revokeObjectURL(url)
    return
  }
  const old = previewUrls.get(entry)
  if (old && old !== url) URL.revokeObjectURL(old)
  if (url.startsWith('blob:') && url !== source) previewUrls.set(entry, url)
  else previewUrls.delete(entry)
  preview.src = url
}

function setState(entry: HTMLElement, state: EditState): void {
  const { rotate, crop } = parts(entry)
  if (rotate) rotate.value = String(state.rotation)
  if (crop) crop.value = state.crop === null ? '' : state.crop === RESET ? RESET : JSON.stringify(state.crop)
  void update(entry)
}

/** Zuschneiden im Dialog: zeigt das Bild mit der gewählten Drehung, der bisherige Ausschnitt ist vorausgewählt */
async function openCropDialog(entry: HTMLElement, trigger: HTMLElement): Promise<void> {
  const state = readState(entry)
  const source = sourceOf(entry)
  if (!source || state.crop === RESET) return
  const [{ default: CropEditor }, image] = await Promise.all([import('./CropEditor.vue'), loadImage(source)])
  const shown = state.rotation ? await canvasUrl(render(image, null, state.rotation)) : source
  const quarter = state.rotation % 180 !== 0

  const dialog = document.createElement('dialog')
  dialog.className = 'image-crop-dialog'
  dialog.setAttribute('aria-labelledby', 'image-crop-title')
  const title = document.createElement('h2')
  title.id = 'image-crop-title'
  title.textContent = 'Bild zuschneiden'
  const mount = document.createElement('div')
  dialog.append(title, mount)
  document.body.append(dialog)

  const app = createApp(CropEditor, {
    src: shown,
    width: quarter ? image.naturalHeight : image.naturalWidth,
    height: quarter ? image.naturalWidth : image.naturalHeight,
    initial: state.crop ? rotateRect(state.crop, state.rotation) : null,
    applyLabel: 'Übernehmen',
    areaHeight: 'min(52vh, 560px)',
    hint: 'Rahmen an Ecken und Kanten ziehen oder die Ränder einstellen. Alles außerhalb wird beim Speichern abgeschnitten, auch für die KI-Erkennung.',
    onApply: (rect: CropRect) => {
      setState(entry, { rotation: state.rotation, crop: unrotateRect(rect, state.rotation) })
      dialog.close()
    },
    onCancel: () => dialog.close(),
  })
  dialog.addEventListener('close', () => {
    app.unmount()
    dialog.remove()
    if (shown !== source) URL.revokeObjectURL(shown)
    trigger.focus()
  }, { once: true })
  // Klick auf den abgedunkelten Hintergrund schließt wie „Abbrechen“
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close()
  })
  app.mount(mount)
  dialog.showModal()
}

export function initImageEditing(): void {
  // Knöpfe einrichten; nach einem Validierungsfehler stehen Drehung und Zuschnitt schon in den Feldern
  document.querySelectorAll<HTMLElement>('[data-image-entry]').forEach((entry) => void update(entry))

  document.addEventListener('click', (event) => {
    const target = event.target as Element | null
    const button = target?.closest<HTMLButtonElement>('[data-rotate-step], [data-crop-start], [data-crop-reset]')
    const entry = button?.closest<HTMLElement>('[data-image-entry]')
    if (!button || !entry) return
    const state = readState(entry)
    if (button.dataset.rotateStep !== undefined) {
      setState(entry, { ...state, rotation: normalize(state.rotation + Number(button.dataset.rotateStep)) })
    } else if (button.hasAttribute('data-crop-reset')) {
      setState(entry, { ...state, crop: state.crop === RESET ? null : RESET })
    } else {
      void openCropDialog(entry, button)
    }
  })

  document.addEventListener('change', (event) => {
    const input = event.target
    if (!(input instanceof HTMLInputElement) || input.type !== 'file') return
    const entry = input.closest<HTMLElement>('[data-image-entry]')
    if (!entry) return
    if (entry.dataset.source?.startsWith('blob:')) URL.revokeObjectURL(entry.dataset.source)
    const file = input.files?.[0]
    entry.dataset.source = file ? URL.createObjectURL(file) : ''
    // Drehung und Zuschnitt galten dem bisherigen Bild
    setState(entry, { rotation: 0, crop: null })
  })
}
