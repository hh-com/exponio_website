// Übersetzungen kommen aus translations/messages.*.yaml (Schlüssel "vue.*"), siehe base.html.twig
let messages: Record<string, string> | null = null

export function t(key: string, params: Record<string, string | number> = {}): string {
  if (messages === null) {
    try {
      messages = JSON.parse(document.getElementById('i18n')?.textContent || '{}') as Record<string, string>
    } catch {
      messages = {}
    }
  }
  let text = messages[key] ?? key
  for (const [name, value] of Object.entries(params)) {
    text = text.split(`%${name}%`).join(String(value))
  }
  return text
}

export const locale = (): string => document.documentElement.lang || 'de'
