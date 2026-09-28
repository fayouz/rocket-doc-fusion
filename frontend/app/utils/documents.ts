import type { DocumentStatus } from '~/types/documents'

export const DOCUMENT_STATUS: Record<DocumentStatus, { label: string, color: 'info' | 'success' | 'error', icon: string }> = {
  merging: { label: 'Fusion en cours', color: 'info', icon: 'i-lucide-loader-circle' },
  ready: { label: 'Prêt', color: 'success', icon: 'i-lucide-circle-check' },
  failed: { label: 'Échec', color: 'error', icon: 'i-lucide-circle-x' },
}

/** "client_nom" → "Client nom": label of a form field from a template variable. */
export function variableLabel(name: string): string {
  const text = name.replace(/_+/g, ' ').trim()
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** "{{name}}" as written in a template (built here: braces in a Vue template would end an interpolation). */
export function placeholder(name: string): string {
  return `{{${name}}}`
}
