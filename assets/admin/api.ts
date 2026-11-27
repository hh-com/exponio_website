export interface AreaMeta {
  id: number
  name: string
  icon: string
  definitions: { code: string; label: string; type: 'text' | 'number' | 'select' | 'boolean'; unit: string | null; options: { value: string; label: string }[] }[]
}

export interface Meta {
  areas: AreaMeta[]
  categories: { id: number; label: string }[]
  topics: { id: number; name: string }[]
  sides: { value: string; label: string }[]
}

export interface QueueItem {
  id: number
  title: string
  status: string
  thumb: string | null
  confidence: number | null
  recognized: boolean
  error: boolean
  folder: string | null
  createdAt: string
  duplicateOf: number | null
  proposal: boolean
}

export interface ImageData {
  id: number
  thumb: string
  medium: string
  full: string
  /** Größe der großen Variante (Seitenverhältnis für den Zuschnitt-Rahmen) */
  width: number
  height: number
  side: string | null
  alt: string | null
  /** zugeschnitten, der Zuschnitt lässt sich aufheben */
  cropped: boolean
}

/** Bildausschnitt in Anteilen (0–1) des angezeigten Bildes, Ursprung oben links */
export interface CropRect {
  x: number
  y: number
  w: number
  h: number
}

/** Hinweis „mögliches Duplikat von #ID“ */
export interface DuplicateInfo {
  id: number
  title: string
  status: string
  uploadCount: number
  confidence: number | null
  reason: string | null
  specimen: string | null
  condition: string | null
  photos: string | null
  better: boolean | null
  images: ImageData[]
  urls: { edit: string; detail: string }
}

/** bessere Fotos eines späteren Uploads, die auf Bestätigung warten */
export interface Proposal {
  id: number
  folder: string | null
  uploadedAt: string
  confidence: number | null
  reason: string | null
  specimen: string | null
  images: ImageData[]
}

/** Antwort, wenn ein Upload einem vorhandenen Objekt zugeordnet wurde */
export interface MergedInfo {
  id: number
  title: string
  uploadCount: number
  summary: string
  urls: { edit: string; detail: string }
}

/** Ergebnis eines Modells bei der letzten Erkennung (App\Recognition\RecognitionComparison) */
export interface RecognitionResultData {
  id: number
  provider: string
  /** Kurzname, z. B. „Claude“ */
  label: string
  model: string
  modelUsed: string | null
  /** Hauptzeile in den Einstellungen */
  primary: boolean
  /** steht im Formular */
  applied: boolean
  error: string | null
  confidence: number | null
  isCollectible: boolean | null
  durationMs: number
  inputTokens: number | null
  /** einschließlich Denk-Tokens */
  outputTokens: number | null
  /** US-Dollar, null ohne Preise in den Einstellungen */
  cost: number | null
  /** Zeilenschlüssel => lesbarer Wert */
  values: Record<string, string | null>
}

/** Vergleichstabelle: Zeilen = Felder, Spalten = Modelle */
export interface RecognitionComparison {
  rows: { key: string; label: string; long: boolean }[]
  results: RecognitionResultData[]
}

export interface ItemData {
  id: number
  status: string
  areaId: number | null
  categoryId: number | null
  topicIds: number[]
  title: string | null
  description: string | null
  defects: string | null
  metaDescription: string | null
  year: number | null
  yearTo: number | null
  issueDate: string | null
  series: string | null
  attributes: Record<string, string | null>
  catalogValue: string | null
  showEstimate: boolean
  featured: boolean
  internalNotes: string | null
  storageLocation: string | null
  /** Hinweis an die KI: geht bei jeder Erkennung mit (höchstens AI_HINT_MAX Zeichen) */
  aiHint: string | null
  sourceFolder: string | null
  /** Upload-Ordner, die beim Löschen des Objekts mit gelöscht werden */
  folders: string[]
  createdAt: string
  uploadCount: number
  images: ImageData[]
  duplicate: DuplicateInfo | null
  proposals: Proposal[]
  recognitions: RecognitionComparison
  ai: { confidence: number | null; model: string | null; recognizedAt: string | null; error: string | null; notes: string | null; alternatives: string[]; isCollectible: boolean | null }
  urls: { edit: string; public: string }
}

export class ApiError extends Error {
  constructor(message: string, public status: number, public data: Record<string, unknown> = {}) {
    super(message)
  }
}

/** wie Item::AI_HINT_MAX */
export const AI_HINT_MAX = 2000

export function createApi(base: string, csrf: string) {
  const root = base.replace(/\/review\/?$/, '')

  async function request<T>(url: string, body?: unknown, progressId?: string): Promise<T> {
    const response = await fetch(url, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(progressId ? { 'X-Progress-Id': progressId } : {}) },
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      const message = (data as { error?: string; detail?: string }).error ?? (data as { detail?: string }).detail ?? `Fehler ${response.status}`
      throw new ApiError(message, response.status, data as Record<string, unknown>)
    }
    return data as T
  }

  return {
    queue: () => request<{ items: QueueItem[]; meta: Meta }>(base),
    item: (id: number) => request<{ item: ItemData }>(`${base}/${id}`),
    save: (id: number, data: Partial<ItemData> & { action: string }) => request<{ item: ItemData }>(`${base}/${id}`, data),
    /** Objekt endgültig löschen, samt Fotos und Upload-Ordnern; failed: Ordner, die sich nicht löschen ließen */
    remove: (id: number) => request<{ deleted: number; folders: string[]; failed: Record<string, string> }>(`${base}/${id}/delete`, {}),
    /**
     * progressId: Zwischenstand für den Toast (assets/admin/aiProgress.ts).
     * aiHint wird vorher gespeichert und gilt schon für diese Erkennung; ohne aiHint gilt der gespeicherte Hinweis.
     */
    recognize: (id: number, progressId?: string, aiHint?: string | null) => request<{ ok: boolean; error: string | null; item?: ItemData; merged?: MergedInfo; meta: Meta }>(`${base}/${id}/recognize`, aiHint === undefined ? {} : { aiHint }, progressId),
    /** Vorschlag eines Modells ins Formular übernehmen (ersetzt die KI-Felder, Status bleibt) */
    applyRecognition: (id: number, resultId: number) => request<{ item: ItemData; meta: Meta }>(`${base}/${id}/recognition/${resultId}`, {}),
    duplicate: (id: number, action: 'keep' | 'replace' | 'separate') => request<{ item?: ItemData; merged?: MergedInfo }>(`${base}/${id}/duplicate`, { action }),
    proposal: (id: number, uploadId: number, action: 'accept' | 'reject') => request<{ item: ItemData }>(`${base}/${id}/proposal/${uploadId}`, { action }),
    images: (id: number, order: number[], remove: number[]) => request<{ item: ItemData }>(`${base}/${id}/images`, { order, delete: remove }),
    /** degrees im Uhrzeigersinn (90, 180, -90); erzeugt neue Bilddateien mit neuen Adressen */
    rotateImage: (id: number, imageId: number, degrees: number) => request<{ item: ItemData }>(`${base}/${id}/images`, { rotate: { [imageId]: degrees } }),
    /** rect = Ausschnitt des angezeigten Bildes, null hebt den Zuschnitt auf; erzeugt neue Bilddateien mit neuen Adressen */
    cropImage: (id: number, imageId: number, rect: CropRect | null) => request<{ item: ItemData }>(`${base}/${id}/images`, { crop: { [imageId]: rect } }),
    scan: () => request<{ imported: number; failed: Record<string, string> }>(`${root}/scan`, {}),
    createCategory: (nameDe: string, parentId: number | null) => request<{ id: number; label: string }>(`${root}/categories`, { nameDe, parentId }),
    createTopic: (nameDe: string) => request<{ id: number; name: string }>(`${root}/topics`, { nameDe }),
  }
}
