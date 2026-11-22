import './styles/admin.css'
import { createApp } from 'vue'

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

async function initReviewApp(): Promise<void> {
  const el = document.getElementById('review-app')
  if (!el) return
  const { default: ReviewApp } = await import('./admin/ReviewApp.vue')
  createApp(ReviewApp, JSON.parse(el.dataset.props || '{}')).mount(el)
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initAttributeGroups()
    initReviewApp()
  })
} else {
  initAttributeGroups()
  initReviewApp()
}
