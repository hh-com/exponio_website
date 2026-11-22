// Merkliste: nur im localStorage des Browsers, keine Cookies, kein Server-Zustand.
const KEY = 'watchlist'
export const CHANGE_EVENT = 'watchlist:change'

export function getIds(): number[] {
  try {
    const value = JSON.parse(localStorage.getItem(KEY) || '[]')
    return Array.isArray(value) ? value.filter((id): id is number => Number.isInteger(id) && id > 0) : []
  } catch {
    return []
  }
}

function save(ids: number[]): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(ids))
  } catch {
    // privater Modus o. Ä.: Merkliste gilt nur bis zum Neuladen
  }
  window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: ids }))
}

export function toggle(id: number): boolean {
  const ids = getIds()
  const index = ids.indexOf(id)
  if (index === -1) {
    ids.unshift(id)
  } else {
    ids.splice(index, 1)
  }
  save(ids.slice(0, 200))
  return index === -1
}

export function clear(): void {
  save([])
}

export function refresh(root: ParentNode = document): void {
  const ids = new Set(getIds())
  root.querySelectorAll<HTMLElement>('[data-watch]').forEach((button) => {
    const on = ids.has(Number(button.dataset.watch))
    if (button.getAttribute('aria-pressed') !== String(on)) button.setAttribute('aria-pressed', String(on))
    const text = button.querySelector('[data-watch-text]')
    const label = (on ? button.dataset.watchedLabel : button.dataset.watchLabel) ?? ''
    // nur bei Änderung schreiben – sonst löst der MutationObserver erneut aus
    if (text && button.dataset.watchLabel && text.textContent !== label) text.textContent = label
  })
  document.querySelectorAll<HTMLElement>('[data-watch-count]').forEach((badge) => {
    if (badge.textContent !== String(ids.size)) badge.textContent = String(ids.size)
    badge.classList.toggle('hidden', ids.size === 0)
  })
}

export function initWatchlist(): void {
  document.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLElement>('[data-watch]')
    if (!button) return
    event.preventDefault()
    toggle(Number(button.dataset.watch))
  })
  window.addEventListener(CHANGE_EVENT, () => refresh())
  window.addEventListener('storage', (event) => {
    if (event.key === KEY) refresh()
  })
  // neu eingefügte Karten (Katalog-Filter, Merkliste) automatisch aktualisieren
  let scheduled = false
  new MutationObserver(() => {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(() => {
      scheduled = false
      refresh()
    })
  }).observe(document.body, { childList: true, subtree: true })
  refresh()
}
