export interface CatalogState {
  q: string
  area: number | null
  regions: number[]
  topics: number[]
  from: number | null
  to: number | null
  attr: Record<string, string[]>
  sort: string
  page: number
  fixed: { area: number | null; region: number | null; topic: number | null }
}

export interface RegionNode { id: number; label: string; count: number; children: RegionNode[] }

export interface Facets {
  areas: { id: number; label: string; icon: string; count: number }[]
  regions: RegionNode[]
  topics: { id: number; label: string; count: number }[]
  years: { min: number | null; max: number | null }
  attributes: { code: string; label: string; type: string; options: { value: string; label: string; count: number }[] }[]
}

/** URL-Parameter wie in src/Catalog/CatalogQuery.php (toParams/fromRequest) */
export function toSearchParams(state: CatalogState): URLSearchParams {
  const params = new URLSearchParams()
  if (state.q.trim()) params.set('q', state.q.trim())
  if (state.area !== null && state.fixed.area === null) params.set('area', String(state.area))
  if (state.regions.length) params.set('region', state.regions.join(','))
  if (state.topics.length) params.set('topic', state.topics.join(','))
  if (state.from !== null) params.set('from', String(state.from))
  if (state.to !== null) params.set('to', String(state.to))
  if (state.sort !== 'new') params.set('sort', state.sort)
  if (state.page > 1) params.set('page', String(state.page))
  for (const [code, values] of Object.entries(state.attr)) {
    if (values.length) params.set(`attr[${code}]`, values.join(','))
  }
  return params
}

export function fromSearchParams(search: string, fixed: CatalogState['fixed']): CatalogState {
  const params = new URLSearchParams(search)
  const ids = (key: string) => (params.get(key) ?? '').split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0)
  const int = (key: string) => {
    const n = Number(params.get(key))
    return params.get(key) && Number.isInteger(n) && n > 0 ? n : null
  }
  const attr: Record<string, string[]> = {}
  params.forEach((value, key) => {
    const match = /^attr\[([a-z][a-z0-9_]*)\]$/.exec(key)
    if (match) attr[match[1]] = value.split(',').filter(Boolean)
  })
  return {
    q: params.get('q') ?? '',
    area: int('area'),
    regions: ids('region'),
    topics: ids('topic'),
    from: int('from'),
    to: int('to'),
    attr,
    sort: params.get('sort') ?? 'new',
    page: int('page') ?? 1,
    fixed,
  }
}
