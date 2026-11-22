/**
 * Ausklappmenüs im Kopfbereich (Katalog, Sprachen) nach dem Disclosure-Muster:
 * Button mit aria-expanded, Esc schließt und setzt den Fokus zurück, Klick daneben schließt.
 */
export function initDisclosures(root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-disclosure]').forEach((wrapper) => {
    const button = wrapper.querySelector<HTMLButtonElement>('[data-disclosure-toggle]')
    const panel = wrapper.querySelector<HTMLElement>('[data-disclosure-panel]')
    if (!button || !panel) return

    const isOpen = () => button.getAttribute('aria-expanded') === 'true'
    const set = (open: boolean) => {
      button.setAttribute('aria-expanded', String(open))
      panel.hidden = !open
    }

    button.addEventListener('click', () => set(!isOpen()))
    wrapper.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isOpen()) {
        set(false)
        button.focus()
      }
    })
    wrapper.addEventListener('focusout', (event) => {
      if (!wrapper.contains(event.relatedTarget as Node | null)) set(false)
    })
    document.addEventListener('click', (event) => {
      if (isOpen() && !wrapper.contains(event.target as Node)) set(false)
    })
  })
}
