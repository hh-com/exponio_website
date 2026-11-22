const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Hält den Tastaturfokus in einem Dialog (WCAG 2.1.2 – keine Falle, weil Esc schließt).
 * Gibt eine Funktion zurück, die den Fokus wieder an das vorher aktive Element gibt.
 */
export function trapFocus(container: HTMLElement): () => void {
  const previous = document.activeElement as HTMLElement | null
  const items = () => Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null)

  const onKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Tab') return
    const list = items()
    if (!list.length) return
    const first = list[0]
    const last = list[list.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  container.addEventListener('keydown', onKeydown)
  ;(items()[0] ?? container).focus()

  return () => {
    container.removeEventListener('keydown', onKeydown)
    previous?.focus()
  }
}
