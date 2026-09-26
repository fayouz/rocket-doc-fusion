<script setup lang="ts">
import type { OnlyOfficeState } from '~/types/documents'

definePageMeta({ admin: true })
const appName = useAppConfig().rocket.name
useHead({ title: `ONLYOFFICE · ${appName}` })

const api = useApi()
const toast = useToast()
const { data: state, status, refresh } = await useAsyncData('admin-onlyoffice', () => api<OnlyOfficeState>('/api/admin/onlyoffice'))

const EDITIONS = { community: 'Community (gratuite)', enterprise: 'Enterprise', developer: 'Developer' } as const
const LICENSE = {
  none: { label: 'Aucune licence', color: 'neutral' },
  valid: { label: 'Valide', color: 'success' },
  expired: { label: 'Expirée', color: 'error' },
  not_started: { label: 'Pas encore active', color: 'warning' },
  invalid: { label: 'Invalide ou limite atteinte', color: 'error' },
} as const

const input = useTemplateRef<HTMLInputElement>('input')
const uploading = ref(false)

async function install(files: FileList | null) {
  const file = files?.[0]
  if (!file) return
  uploading.value = true
  try {
    const body = new FormData()
    body.append('file', file)
    state.value = await api<OnlyOfficeState>('/api/admin/onlyoffice/license', { method: 'POST', body })
    toast.add({ title: 'Licence installée', description: 'Redémarrez ONLYOFFICE Docs pour l’appliquer.', color: 'success', icon: 'i-lucide-key-round' })
  }
  catch (error) {
    toast.add({ title: 'Licence refusée', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    uploading.value = false
    if (input.value) input.value.value = ''
  }
}

async function remove() {
  if (!confirm('Retirer le fichier de licence ? ONLYOFFICE repassera en édition Community à son redémarrage.')) return
  try {
    state.value = await api<OnlyOfficeState>('/api/admin/onlyoffice/license', { method: 'DELETE' })
    toast.add({ title: 'Licence retirée', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Action impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

function limit(value: number | null | undefined) {
  return value == null ? 'illimité' : formatNumber(value)
}
</script>

<template>
  <UDashboardPanel id="onlyoffice">
    <template #header>
      <UDashboardNavbar title="ONLYOFFICE">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" label="Actualiser" color="neutral" variant="outline" :loading="status === 'pending'" @click="refresh()" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="state" class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <UAlert
          v-if="!state.reachable"
          icon="i-lucide-plug-zap"
          color="error"
          variant="subtle"
          title="ONLYOFFICE Docs est injoignable"
          :description="state.error ?? undefined"
        />

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Document Server
            </p>
          </template>
          <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-muted">
                Version
              </dt>
              <dd class="font-medium">
                {{ state.version ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Édition
              </dt>
              <dd class="font-medium">
                {{ state.edition ? EDITIONS[state.edition] : '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Adresse pour les navigateurs
              </dt>
              <dd><code>{{ state.publicUrl }}</code></dd>
            </div>
            <div>
              <dt class="text-muted">
                Adresse pour l’API
              </dt>
              <dd><code>{{ state.internalUrl }}</code></dd>
            </div>
          </dl>
        </UCard>

        <UCard>
          <template #header>
            <div class="flex items-center justify-between gap-2">
              <p class="font-medium text-highlighted">
                Licence
              </p>
              <UBadge v-if="state.license" :label="LICENSE[state.license.status].label" :color="LICENSE[state.license.status].color" variant="subtle" />
            </div>
          </template>
          <div class="flex flex-col gap-4 text-sm">
            <dl v-if="state.license && state.license.status !== 'none'" class="grid gap-3 sm:grid-cols-3">
              <div>
                <dt class="text-muted">
                  Client
                </dt>
                <dd class="font-medium">
                  {{ state.license.customer ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Fin
                </dt>
                <dd class="font-medium">
                  {{ state.license.endDate ? formatDate(state.license.endDate) : 'sans limite' }}<template v-if="state.license.trial">
                    (essai)
                  </template>
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Connexions / utilisateurs
                </dt>
                <dd class="font-medium">
                  {{ limit(state.license.connections) }} / {{ limit(state.license.users) }}
                </dd>
              </div>
            </dl>
            <p class="text-muted">
              Sans licence, ONLYOFFICE Docs fonctionne en édition Community. Pour l’édition Enterprise ou Developer, déposez ici le
              fichier <code>license.lic</code> fourni par ONLYOFFICE : il est enregistré dans le dossier partagé avec le Document Server,
              puis appliqué à son redémarrage (<code>docker compose restart onlyoffice</code>).
            </p>
            <p v-if="state.licenseFile.installed" class="text-muted">
              Fichier installé {{ state.licenseFile.updatedAt ? `le ${formatDate(state.licenseFile.updatedAt)}` : '' }}.
            </p>
            <div class="flex flex-wrap gap-2">
              <UButton icon="i-lucide-key-round" :label="state.licenseFile.installed ? 'Remplacer la licence' : 'Installer une licence'" :loading="uploading" @click="input?.click()" />
              <UButton v-if="state.licenseFile.installed" icon="i-lucide-trash-2" label="Retirer" color="error" variant="ghost" @click="remove" />
              <input ref="input" type="file" class="hidden" accept=".lic,application/json" @change="install(($event.target as HTMLInputElement).files)">
            </div>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
