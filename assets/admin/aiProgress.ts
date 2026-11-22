import { toast, type ToastHandle, type ToastType } from '../lib/toast'

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

  /** Abfragen beenden und den Toast in eine Ergebnis-Meldung verwandeln. */
  finish(type: Exclude<ToastType, 'progress'>, message: string, title: string | null = null): void {
    this.halt()
    this.toast.update({ type, message, title })
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

/**
 * Backend-Aktionen mit data-ai-progress="Titel" (z. B. „KI-Erkennung“, „Jetzt übersetzen“, „KI-Verbindung testen“):
 * Beim Absenden erscheint der Fortschritts-Toast; die Seite lädt danach mit der Ergebnis-Meldung neu.
 */
export function initAiProgressForms(): void {
  document.addEventListener('submit', (event) => {
    if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return
    const form = event.target
    const trigger = event.submitter?.closest<HTMLElement>('[data-ai-progress]') ?? form.querySelector<HTMLElement>('[data-ai-progress]')
    const title = trigger?.dataset.aiProgress
    if (!title) return

    const progress = new AiProgressToast(title)
    let field = form.querySelector<HTMLInputElement>('input[name="_progress"]')
    if (!field) {
      field = document.createElement('input')
      field.type = 'hidden'
      field.name = '_progress'
      form.append(field)
    }
    field.value = progress.id
    // Doppelklick verhindern – erst nach dem Absenden, sonst fehlt der Button in den Formulardaten
    window.setTimeout(() => form.querySelectorAll('button').forEach((button) => { button.disabled = true }))
  })

  // Zurück-Taste: Die Seite kommt evtl. aus dem Browser-Cache – alten Fortschritt entfernen, Buttons freigeben
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return
    active.forEach((progress) => progress.stop())
    document.querySelectorAll<HTMLInputElement>('input[name="_progress"]').forEach((field) => {
      field.form?.querySelectorAll('button').forEach((button) => { button.disabled = false })
    })
  })
}
