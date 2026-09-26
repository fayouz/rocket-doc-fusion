export type DocumentStatus = 'merging' | 'ready' | 'failed'

export interface MergedDocument {
  id: string
  title: string
  templateName: string
  values: Record<string, unknown>
  status: DocumentStatus
  error: string | null
  version: number
  size: number | null
  editedAt: string | null
  ready: boolean
  ownerEmail: string
  applicationName: string | null
  createdAt: string
  updatedAt: string
}

export interface TemplateVariables {
  variables: string[]
  sections: { name: string, fields: string[] }[]
  errors: string[]
}

export interface EditorSetup {
  scriptUrl: string
  config: Record<string, unknown>
}

export interface OnlyOfficeState {
  publicUrl: string
  internalUrl: string
  reachable: boolean
  version: string | null
  edition: 'community' | 'enterprise' | 'developer' | null
  license: {
    status: 'none' | 'valid' | 'expired' | 'not_started' | 'invalid'
    customer: string | null
    startDate: string | null
    endDate: string | null
    trial: boolean
    connections: number | null
    connectionsView: number | null
    users: number | null
  } | null
  licenseFile: { installed: boolean, updatedAt: string | null }
  error: string | null
}
