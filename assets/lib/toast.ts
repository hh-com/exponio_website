import '../styles/toast.css'

/**
 * Kleine Hinweise unten rechts, die ein- und ausblenden („Toasts“) – für Info, Erfolg, Warnung, Fehler
 * und laufende Vorgänge (mit Drehsymbol und verstrichener Zeit).
 *
 *   toast('success', 'Gespeichert.')
 *   const t = toast('progress', 'Anfrage wird gesendet …', { title: 'KI-Erkennung läuft' })
 *   t.update({ message: 'Warte auf Claude …' })
 *   t.update({ type: 'success', message: 'Erkannt.' })   // wird danach automatisch ausgeblendet
 *
 * Fehler und laufende Vorgänge bleiben stehen, bis sie geschlossen bzw. abgeschlossen werden.
 * Mit der Maus über dem Hinweis pausiert das automatische Ausblenden.
 */

export type ToastType = 'info' | 'success' | 'warning' | 'error' | 'progress'

export interface ToastOptions {
  title?: string | null
  /** Anzeigedauer in ms; 0 = bis zum Schließen. Standard: Info/Erfolg 5 s, Warnung 8 s, Fehler/„läuft“ dauerhaft */
  duration?: number
}

export interface ToastChanges extends ToastOptions {
  type?: ToastType
  message?: string
}

export interface ToastHandle {
  readonly element: HTMLElement
  update(changes: ToastChanges): void
  close(): void
}

const DURATION: Record<ToastType, number> = { info: 5000, success: 5000, warning: 8000, error: 0, progress: 0 }
const ICON: Record<Exclude<ToastType, 'progress'>, string> = {
  info: 'fa-circle-info',
  success: 'fa-circle-check',
  warning: 'fa-triangle-exclamation',
  error: 'fa-circle-exclamation',
}
const MAX_VISIBLE = 5

let container: HTMLElement | null = null

function region(): HTMLElement {
  if (container?.isConnected) return container
  container = document.createElement('section')
  container.className = 'ps-toasts'
  container.setAttribute('aria-label', 'Meldungen')
  document.body.append(container)
  return container
}

function formatElapsed(ms: number): string {
  const s = Math.floor(ms / 1000)
  return s < 60 ? `${s} s` : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} min`
}

export function toast(type: ToastType, message: string, options: ToastOptions = {}): ToastHandle {
  const el = document.createElement('div')
  const icon = document.createElement('span')
  const body = document.createElement('div')
  const title = document.createElement('strong')
  const text = document.createElement('p')
  const meta = document.createElement('p')
  const close = document.createElement('button')

  icon.className = 'ps-toast__icon'
  icon.setAttribute('aria-hidden', 'true')
  body.className = 'ps-toast__body'
  title.className = 'ps-toast__title'
  text.className = 'ps-toast__message'
  meta.className = 'ps-toast__meta'
  close.type = 'button'
  close.className = 'ps-toast__close'
  close.setAttribute('aria-label', 'Meldung schließen')
  close.innerHTML = '<i class="fa fa-xmark" aria-hidden="true"></i>'
  body.append(title, text, meta)
  el.append(icon, body, close)

  let current: ToastType = type
  let hideTimer = 0
  let tick = 0
  let duration = options.duration ?? DURATION[type]
  const started = Date.now()
  let closed = false

  const schedule = () => {
    window.clearTimeout(hideTimer)
    if (duration > 0 && !closed) hideTimer = window.setTimeout(handle.close, duration)
  }

  const render = (changes: ToastChanges) => {
    if (changes.type) current = changes.type
    if (changes.message !== undefined) text.textContent = changes.message
    if (changes.title !== undefined) title.textContent = changes.title ?? ''
    title.hidden = !title.textContent
    el.className = `ps-toast ps-toast--${current}${el.classList.contains('is-visible') ? ' is-visible' : ''}`
    // Fehler sofort vorlesen, alles andere höflich
    el.setAttribute('role', current === 'error' ? 'alert' : 'status')
    icon.innerHTML = current === 'progress' ? '<span class="ps-toast__spinner"></span>' : `<i class="fa ${ICON[current]}"></i>`
    window.clearInterval(tick)
    meta.hidden = current !== 'progress'
    if (current === 'progress') {
      const update = () => { meta.textContent = formatElapsed(Date.now() - started) }
      update()
      tick = window.setInterval(update, 1000)
    }
  }

  const handle: ToastHandle = {
    element: el,
    update(changes) {
      if (closed) return
      render(changes)
      if (changes.type || changes.duration !== undefined) {
        duration = changes.duration ?? DURATION[current]
        schedule()
      }
    },
    close() {
      if (closed) return
      closed = true
      window.clearTimeout(hideTimer)
      window.clearInterval(tick)
      el.classList.remove('is-visible')
      const remove = () => el.remove()
      el.addEventListener('transitionend', remove, { once: true })
      window.setTimeout(remove, 400)
    },
  }

  render({ type, message, title: options.title ?? null })
  close.addEventListener('click', handle.close)
  el.addEventListener('mouseenter', () => window.clearTimeout(hideTimer))
  el.addEventListener('mouseleave', schedule)

  const list = region()
  list.append(el)
  // zu viele? die ältesten abgeschlossenen Meldungen zuerst ausblenden
  const old = Array.from(list.querySelectorAll<HTMLElement>('.ps-toast.is-visible:not(.ps-toast--progress)'))
  old.slice(0, Math.max(0, list.children.length - MAX_VISIBLE)).forEach((node) => node.querySelector<HTMLButtonElement>('.ps-toast__close')?.click())
  requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('is-visible')))
  schedule()

  return handle
}

export const notify = {
  info: (message: string, options?: ToastOptions) => toast('info', message, options),
  success: (message: string, options?: ToastOptions) => toast('success', message, options),
  warning: (message: string, options?: ToastOptions) => toast('warning', message, options),
  error: (message: string, options?: ToastOptions) => toast('error', message, options),
}

/** Bezeichnungen aus addFlash() bzw. Bootstrap auf die Toast-Typen abbilden */
export function toastType(label: string): ToastType {
  if (label === 'danger' || label === 'error') return 'error'
  if (label === 'success' || label === 'warning') return label
  return 'info'
}

/** Meldungen aus addFlash() anzeigen (templates/bundles/EasyAdminBundle/flash_messages.html.twig) */
export function showFlashToasts(): void {
  const source = document.getElementById('flash-toasts')
  if (!source) return
  source.remove()
  try {
    const list = JSON.parse(source.textContent || '[]') as { type: string; title: string | null; message: string }[]
    list.forEach((item) => toast(toastType(item.type), item.message, { title: item.title }))
  } catch {
    // ungültige Daten: nichts anzeigen
  }
}
