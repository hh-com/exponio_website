import { toast, toastType, type ToastAction, type ToastHandle, type ToastType } from '../lib/toast'

const POLL_MS = 1000
const active = new Set<AiProgressToast>()

function randomId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  // ohne HTTPS gibt es randomUUID() nicht
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('')
}

function progressUrl(): string | null {
  return document.querySelector<HTMLMetaElement>('meta[name="ai-progress-url"]')?.content || null
}

/**
 * Toast „KI arbeitet …“ mit Zwischenstand vom Server (App\Ai\AiProgress).
 *
 * Die ID (id bzw. next()) geht mit der Anfrage mit – als Header X-Progress-Id oder Formularfeld _progress.
 * Solange die Anfrage läuft, holt der Toast jede Sekunde den letzten Schritt ab.
 */
export class AiProgressToast {
  readonly toast: ToastHandle
  id = ''
  private timer = 0
  private lastAt = 0
  private stopped = false

  constructor(title: string, message = 'Anfrage wird gesendet …') {
    this.toast = toast('progress', message, { title })
    active.add(this)
    this.next()
  }

  /** Neue ID für die nächste Anfrage (z. B. im Stapel); der Toast bleibt stehen. */
  next(message?: string): string {
    this.id = randomId()
    this.lastAt = 0
    if (message) this.toast.update({ message })
    this.schedule()
    return this.id
  }

  /** Abfragen beenden und den Toast in eine Ergebnis-Meldung verwandeln (optional mit Button). */
  finish(type: Exclude<ToastType, 'progress'>, message: string, title: string | null = null, action: ToastAction | null = null): void {
    this.halt()
    this.toast.update({ type, message, title, action })
  }

  /** Abfragen beenden und den Toast schließen. */
  stop(): void {
    this.halt()
    this.toast.close()
  }

  private halt(): void {
    this.stopped = true
    window.clearTimeout(this.timer)
    active.delete(this)
  }

  private schedule(): void {
    if (this.stopped || !progressUrl()) return
    window.clearTimeout(this.timer)
    this.timer = window.setTimeout(() => this.poll(), POLL_MS)
  }

  private async poll(): Promise<void> {
    const url = progressUrl()
    const id = this.id
    if (!url || this.stopped) return
    try {
      const response = await fetch(`${url}?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      if (response.ok) {
        const data = await response.json() as { steps?: { message: string; at: number }[] }
        const last = data.steps?.at(-1)
        if (!this.stopped && id === this.id && last && last.at !== this.lastAt) {
          this.lastAt = last.at
          this.toast.update({ message: last.message })
        }
      }
    } catch {
      // Netzwerk kurz weg: beim nächsten Mal wieder versuchen
    }
    this.schedule()
  }
}

const SEVERITY: Record<string, number> = { error: 3, warning: 2, success: 1, info: 0 }

interface BackgroundResult {
  redirect: string | null
  messages: { type: string; message: string }[]
}

/**
 * Schickt eine Backend-Aktion im Hintergrund ab (App\EventSubscriber\BackgroundActionSubscriber antwortet mit JSON
 * statt Weiterleitung). Die Seite bleibt bedienbar; der Fortschritts-Toast wird zur Ergebnis-Meldung.
 */
async function runInBackground(form: HTMLFormElement, title: string, resultLabel: string | null): Promise<void> {
  const progress = new AiProgressToast(title)
  const buttons = Array.from(form.querySelectorAll('button'))
  buttons.forEach((button) => { button.disabled = true })
  try {
    const body = new FormData(form)
    body.set('_progress', progress.id)
    const response = await fetch(form.action, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      // Weiterleitungen nicht still folgen: Die Zielseite würde die Meldungen aus der Session verbrauchen
      redirect: 'manual',
      headers: { Accept: 'application/json', 'X-Background-Action': '1', 'X-Progress-Id': progress.id },
    })
    if (response.type === 'opaqueredirect') {
      // Weiterleitung statt JSON (z. B. Server-Cache nach einem Update nicht geleert, oder Login abgelaufen):
      // Die Aktion ist gelaufen, die Meldungen liegen noch in der Session – neu laden zeigt sie an.
      progress.finish('info', 'Seite wird neu geladen …')
      window.location.reload()
      return
    }
    const data = (response.headers.get('Content-Type') ?? '').includes('json') ? await response.json() as BackgroundResult : null
    if (!response.ok || !data) {
      throw new Error(response.status === 403 ? 'Keine Berechtigung oder Sitzung abgelaufen – bitte die Seite neu laden.' : `Fehler ${response.status} – bitte die Seite neu laden und erneut versuchen.`)
    }
    // die wichtigste Meldung ersetzt den Fortschritt, weitere erscheinen darunter
    const messages = [...data.messages].sort((a, b) => (SEVERITY[toastType(b.type)] ?? 0) - (SEVERITY[toastType(a.type)] ?? 0))
    const [main, ...rest] = messages
    const action = resultLabel && data.redirect ? { label: resultLabel, href: data.redirect } : null
    const mainType = main ? toastType(main.type) : 'success'
    progress.finish(mainType === 'progress' ? 'info' : mainType, main?.message ?? 'Fertig.', null, action)
    rest.forEach((message) => toast(toastType(message.type), message.message))
  } catch (e) {
    progress.finish('error', (e as Error).message || 'Verbindung unterbrochen – bitte erneut versuchen.')
  } finally {
    buttons.forEach((button) => { button.disabled = false })
  }
}

/**
 * Backend-Aktionen mit data-ai-progress="Titel" (z. B. „KI-Erkennung“, „Jetzt übersetzen“, „KI-Verbindung testen“)
 * laufen im Hintergrund mit Fortschritts-Toast. data-ai-result="Ergebnis anzeigen" fügt dem Ergebnis einen Button
 * zur Seite mit dem Ergebnis hinzu (sonst bleibt man einfach, wo man ist).
 */
export function initAiProgressForms(): void {
  document.addEventListener('submit', (event) => {
    if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return
    const form = event.target
    const trigger = event.submitter?.closest<HTMLElement>('[data-ai-progress]') ?? form.querySelector<HTMLElement>('[data-ai-progress]')
    const title = trigger?.dataset.aiProgress
    if (!title) return
    event.preventDefault()
    void runInBackground(form, title, trigger?.dataset.aiResult ?? null)
  })
}
