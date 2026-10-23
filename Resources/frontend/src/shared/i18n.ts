let dictionary: Record<string, string> = {}

export function setStrings(strings: Record<string, string>): void {
  dictionary = strings
}

/** Translates an English key with the server's dictionary. Placeholders use Laravel's ":name" form. */
export function t(key: string, replace: Record<string, string | number> = {}): string {
  let text = dictionary[key] ?? key
  for (const name of Object.keys(replace).sort((a, b) => b.length - a.length)) {
    text = text.split(`:${name}`).join(String(replace[name]))
  }

  return text
}
