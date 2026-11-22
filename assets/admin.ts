import './styles/admin.css'
import { createApp } from 'vue'
import { initAiProgressForms } from './admin/aiProgress'
import { showFlashToasts } from './lib/toast'

/**
 * Objekt-Formular: Merkmalsgruppen passend zur gewählten Objektart ein-/ausblenden.
 */
function initAttributeGroups(): void {
  const groups = document.querySelectorAll<HTMLElement>('[data-attr-area]')
  const select = document.querySelector<HTMLSelectElement>('select[name$="[area]"]')
  if (!groups.length) return

  const update = () => {
    const areaId = select?.value ?? ''
    let visible = 0
    groups.forEach((group) => {
      const show = group.dataset.attrArea === areaId
      group.hidden = !show
      if (show) visible++
    })
    const hint = document.getElementById('attr-area-hint')
    if (hint) hint.hidden = visible > 0
  }

  if (!document.getElementById('attr-area-hint')) {
    const hint = document.createElement('p')
    hint.id = 'attr-area-hint'
    hint.className = 'form-help'
    hint.textContent = 'Für die gewählte Objektart sind keine Merkmale definiert (Sammlung → Objektarten).'
    groups[0].parentElement?.prepend(hint)
  }
  select?.addEventListener('change', update)
  update()
}

/**
 * Objekt-Formular, Tab „Bilder“ (templates/admin/form/theme.html.twig): Bild drehen.
 * Die Vorschau dreht sofort, das versteckte Feld „rotate“ merkt sich die Drehung; beim Speichern dreht
 * ItemCrudController::processImages() die Dateien. Eine neu gewählte Datei erscheint gleich in der Vorschau
 * und lässt sich vor dem Speichern drehen. Ereignis-Delegation, damit auch neu hinzugefügte Einträge funktionieren.
 */
function initImageRotation(): void {
  const parts = (entry: HTMLElement) => ({
    box: entry.querySelector<HTMLElement>('[data-image-box]'),
    link: entry.querySelector<HTMLAnchorElement>('[data-image-link]'),
    preview: entry.querySelector<HTMLImageElement>('[data-image-preview]'),
    input: entry.querySelector<HTMLInputElement>('input[data-image-rotate]'),
    hint: entry.querySelector<HTMLElement>('[data-rotate-hint]'),
  })

  const setRotation = (entry: HTMLElement, degrees: number) => {
    const { preview, input, hint } = parts(entry)
    if (input) input.value = String(degrees)
    if (preview) preview.style.transform = degrees ? `rotate(${degrees}deg)` : ''
    if (hint) hint.textContent = degrees ? `Wird beim Speichern um ${degrees}° gedreht.` : ''
  }

  // nach einem Validierungsfehler steht die gewählte Drehung schon im Feld – Vorschau und Hinweis angleichen
  document.querySelectorAll<HTMLElement>('[data-image-entry]').forEach((entry) => {
    const degrees = Number(parts(entry).input?.value) || 0
    if (degrees) setRotation(entry, degrees)
  })

  document.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-rotate-step]')
    const entry = button?.closest<HTMLElement>('[data-image-entry]')
    if (!button || !entry) return
    const current = Number(parts(entry).input?.value) || 0
    setRotation(entry, (((current + Number(button.dataset.rotateStep)) % 360) + 360) % 360)
  })

  document.addEventListener('change', (event) => {
    const fileInput = event.target
    if (!(fileInput instanceof HTMLInputElement) || fileInput.type !== 'file') return
    const entry = fileInput.closest<HTMLElement>('[data-image-entry]')
    if (!entry) return
    const { box, link, preview } = parts(entry)
    if (!box || !link || !preview) return
    if (preview.src.startsWith('blob:')) URL.revokeObjectURL(preview.src)
    const file = fileInput.files?.[0]
    const src = file ? URL.createObjectURL(file) : preview.dataset.original ?? ''
    if (src) preview.src = src
    else preview.removeAttribute('src')
    link.href = file ? src : link.dataset.original || '#'
    box.hidden = !src
    setRotation(entry, 0)
  })
}

async function initReviewApp(): Promise<void> {
  const el = document.getElementById('review-app')
  if (!el) return
  const { default: ReviewApp } = await import('./admin/ReviewApp.vue')
  createApp(ReviewApp, JSON.parse(el.dataset.props || '{}')).mount(el)
}

function init(): void {
  showFlashToasts()
  initAiProgressForms()
  initAttributeGroups()
  initImageRotation()
  initReviewApp()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init)
} else {
  init()
}
