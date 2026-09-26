<script setup lang="ts">
import type { MergedDocument, TemplateVariables } from '~/types/documents'

const appName = useAppConfig().rocket.name
useHead({ title: `Fusionner · ${appName}` })

const api = useApi()
const toast = useToast()

const { data: settings } = await useAsyncData('document-settings', () => api<{ maxFileSize: number }>('/api/documents/settings'))

const file = ref<File | null>(null)
const template = ref<TemplateVariables | null>(null)
const values = ref<Record<string, unknown>>({})
const title = ref('')
const inspecting = ref(false)
const merging = ref(false)
const dragging = ref(false)
const input = useTemplateRef<HTMLInputElement>('input')

const tooBig = computed(() => !!file.value && !!settings.value && file.value.size > settings.value.maxFileSize)
const empty = computed(() => !!template.value && !template.value.variables.length && !template.value.sections.length)

async function pick(files: FileList | null | undefined) {
  const picked = files?.[0]
  if (!picked) return
  file.value = picked
  template.value = null
  values.value = {}
  title.value = picked.name.replace(/\.docx$/i, '')
  if (tooBig.value) return
  inspecting.value = true
  try {
    const body = new FormData()
    body.append('file', picked)
    template.value = await api<TemplateVariables>('/api/templates/inspect', { method: 'POST', body })
  }
  catch (error) {
    toast.add({ title: 'Modèle illisible', description: apiErrorMessage(error), color: 'error' })
    file.value = null
  }
  finally {
    inspecting.value = false
  }
}

function onDrop(event: DragEvent) {
  dragging.value = false
  pick(event.dataTransfer?.files)
}

async function downloadSample() {
  try {
    const blob = await api<Blob>('/api/templates/sample', { responseType: 'blob' })
    const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: 'devis-exemple.docx' })
    link.click()
    URL.revokeObjectURL(link.href)
  }
  catch (error) {
    toast.add({ title: 'Téléchargement impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function submit() {
  if (!file.value || !template.value) return
  merging.value = true
  try {
    const body = new FormData()
    body.append('template', file.value)
    body.append('values', JSON.stringify(values.value))
    body.append('title', title.value)
    const document = await api<MergedDocument>('/api/documents', { method: 'POST', body })
    toast.add({ title: 'Fusion lancée', description: `« ${document.title} » s’ouvre dans l’éditeur dès qu’il est prêt.`, color: 'success', icon: 'i-lucide-file-stack' })
    await navigateTo(`/documents/${document.id}`)
  }
  catch (error) {
    toast.add({ title: 'Fusion impossible', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    merging.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="merge">
    <template #header>
      <UDashboardNavbar title="Fusionner">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-files" label="Mes documents" color="neutral" variant="outline" to="/documents" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <UAlert
          icon="i-lucide-braces"
          color="neutral"
          variant="subtle"
          title="Un modèle Word avec des variables"
          :actions="[{ label: 'Télécharger un modèle d’exemple', icon: 'i-lucide-download', color: 'neutral', variant: 'outline', onClick: downloadSample }]"
        >
          <template #description>
            Écrivez <code>{{ placeholder('nom') }}</code> dans le document là où la valeur doit apparaître. Pour répéter une ligne de
            tableau, entourez-la de <code>{{ placeholder('#lignes') }}</code> (première cellule) et <code>{{ placeholder('/lignes') }}</code>
            (dernière cellule). Mettez en forme la variable entière (gras, couleur…).
          </template>
        </UAlert>

        <div
          data-testid="dropzone"
          class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-8 text-center transition"
          :class="dragging ? 'border-primary bg-primary/5' : 'border-default hover:border-primary/60'"
          role="button"
          tabindex="0"
          @click="input?.click()"
          @keydown.enter.prevent="input?.click()"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          <UIcon :name="inspecting ? 'i-lucide-loader-circle' : file ? 'i-lucide-file-check' : 'i-lucide-file-up'" class="size-10 text-primary" :class="{ 'animate-spin': inspecting }" />
          <template v-if="file">
            <p class="font-medium text-highlighted">
              {{ file.name }}
            </p>
            <p class="text-sm" :class="tooBig ? 'text-error' : 'text-muted'">
              {{ formatSize(file.size) }}<template v-if="tooBig">
                · dépasse la taille maximale ({{ formatSize(settings!.maxFileSize) }})
              </template>
            </p>
          </template>
          <template v-else>
            <p class="font-medium text-highlighted">
              Déposez un modèle Word (.docx) ici, ou cliquez pour le choisir
            </p>
            <p v-if="settings" class="text-sm text-muted">
              {{ formatSize(settings.maxFileSize) }} maximum
            </p>
          </template>
          <input ref="input" type="file" class="hidden" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" @change="pick(($event.target as HTMLInputElement).files)">
        </div>

        <UAlert
          v-if="template?.errors.length"
          icon="i-lucide-triangle-alert"
          color="error"
          variant="subtle"
          title="Modèle à corriger"
          :description="template.errors.join(' ')"
        />
        <UAlert
          v-else-if="empty"
          icon="i-lucide-info"
          color="warning"
          variant="subtle"
          title="Aucune variable dans ce modèle"
          description="Le document sera copié tel quel : ajoutez des variables comme {{nom}} pour le personnaliser."
        />

        <form v-if="template && !template.errors.length" class="flex flex-col gap-4" @submit.prevent="submit">
          <UCard>
            <div class="flex flex-col gap-6">
              <UFormField label="Nom du document" required>
                <UInput v-model="title" class="w-full" />
              </UFormField>
              <VariablesForm v-model="values" :template="template" />
            </div>
          </UCard>
          <div class="flex justify-end">
            <UButton type="submit" icon="i-lucide-file-stack" label="Fusionner" size="lg" :loading="merging" :disabled="!title.trim() || tooBig" />
          </div>
        </form>
        <p class="text-xs text-muted">
          Vos applications fusionnent via <code>POST /api/documents</code> au nom de leurs utilisateurs (voir la documentation de l’API).
        </p>
      </div>
    </template>
  </UDashboardPanel>
</template>
