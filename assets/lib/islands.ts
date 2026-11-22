import { createApp, type Component } from 'vue'

export type IslandLoader = () => Promise<{ default: Component }> | { default: Component }

/**
 * Hängt Vue-Komponenten an Elemente mit data-island="name" und data-props='{…}'.
 * Der serverseitig gerenderte Inhalt bleibt bis zum Mount sichtbar (SEO, kein Layout-Sprung).
 */
export function mountIslands(registry: Record<string, IslandLoader>, root: ParentNode = document): void {
  root.querySelectorAll<HTMLElement>('[data-island]').forEach(async (el) => {
    const loader = registry[el.dataset.island ?? '']
    if (!loader || el.dataset.mounted) return
    el.dataset.mounted = '1'

    let props: Record<string, unknown> = {}
    try {
      props = el.dataset.props ? JSON.parse(el.dataset.props) : {}
    } catch (error) {
      console.error('Island props', el.dataset.island, error)
    }
    const ssr = el.querySelector<HTMLElement>('[data-ssr-results]')
    if (ssr) props.initialHtml = ssr.innerHTML

    const module = await loader()
    createApp(module.default, props).mount(el)
  })
}
