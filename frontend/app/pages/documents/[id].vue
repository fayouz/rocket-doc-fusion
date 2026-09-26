<script setup lang="ts">
import type { EditorSetup, MergedDocument } from '~/types/documents'

const appName = useAppConfig().rocket.name
const route = useRoute()
const api = useApi()
const toast = useToast()
const id = computed(() => String(route.params.id))

const { data: doc, refresh, error } = await useAsyncData(`document-${id.value}`, () => api<MergedDocument>(`/api/documents/${id.value}`))
useHead({ title: () => `${doc.value?.title ?? 'Document'} · ${appName}` })

const mode = ref<'edit' | 'view'>('edit')
const setup = ref<EditorSetup | null>(null)
const editorError = ref('')
const saving = ref(false)

async function loadEditor() {
  editorError.value = ''
  try {
    setup.value = await api<EditorSetup>(`/api/documents/${id.value}/editor`, { query: { mode: mode.value } })
  }
  catch (e) {
    editorError.value = apiErrorMessage(e)
  }
}

// Merging: follow the document, then open the editor.
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  if (doc.value?.ready) loadEditor()
  timer = setInterval(async () => {
    if (doc.value?.status !== 'merging') return
    await refresh()
    if (doc.value?.ready) loadEditor()
  }, 1500)
})
onBeforeUnmount(() => clearInterval(timer))
watch(mode, loadEditor)

async function forceSave() {
  saving.value = true
  try {
    const result = await api<{ saved: boolean }>(`/api/documents/${id.value}/force-save`, { method: 'POST' })
    toast.add(result.saved
      ? { title: 'Enregistrement demandé', description: 'La nouvelle version apparaît dans quelques secondes.', color: 'success', icon: 'i-lucide-save' }
      : { title: 'Rien à enregistrer', description: 'Aucune modification en cours.', color: 'neutral' })
    setTimeout(refresh, 3000)
  }
  catch (e) {
    toast.add({ title: 'Enregistrement impossible', description: apiErrorMessage(e), color: 'error' })
  }
  finally {
    saving.value = false
  }
}

async function download(format: 'docx' | 'pdf') {
  if (!doc.value) return
  try {
    const blob = await api<Blob>(`/api/documents/${id.value}/content`, { query: { format, download: 1 }, responseType: 'blob' })
    const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: `${doc.value.title}.${format}` })
    link.click()
    URL.revokeObjectURL(link.href)
  }
  catch (e) {
    toast.add({ title: 'Téléchargement impossible', description: apiErrorMessage(e), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="document" :ui="{ body: 'p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar :title="doc?.title ?? 'Document'">
        <template #leading>
          <UDashboardSidebarCollapse />
          <UButton icon="i-lucide-arrow-left" color="neutral" variant="ghost" to="/documents" aria-label="Mes documents" />
        </template>
        <template #right>
          <template v-if="doc?.ready">
            <UBadge :label="`v${doc.version}`" color="neutral" variant="subtle" />
            <UTabs
              v-model="mode"
              :items="[{ label: 'Modifier', value: 'edit', icon: 'i-lucide-pencil' }, { label: 'Lire', value: 'view', icon: 'i-lucide-eye' }]"
              :content="false"
              size="xs"
            />
            <UButton v-if="mode === 'edit'" icon="i-lucide-save" label="Enregistrer" color="neutral" variant="outline" :loading="saving" @click="forceSave" />
            <UDropdownMenu :items="[[{ label: 'Word (.docx)', icon: 'i-lucide-file-text', onSelect: () => download('docx') }, { label: 'PDF', icon: 'i-lucide-file-type', onSelect: () => download('pdf') }]]">
              <UButton icon="i-lucide-download" label="Télécharger" />
            </UDropdownMenu>
          </template>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="error" class="p-6">
        <UAlert color="error" variant="subtle" icon="i-lucide-file-x" title="Document introuvable" :description="apiErrorMessage(error)" />
      </div>
      <div v-else-if="doc?.status === 'merging'" class="flex h-full flex-col items-center justify-center gap-3 p-10 text-muted">
        <UIcon name="i-lucide-loader-circle" class="size-8 animate-spin text-primary" />
        Fusion de « {{ doc.templateName }} » par ONLYOFFICE…
      </div>
      <div v-else-if="doc?.status === 'failed'" class="p-6">
        <UAlert color="error" variant="subtle" icon="i-lucide-circle-x" title="La fusion a échoué" :description="doc.error ?? undefined" />
      </div>
      <div v-else-if="editorError" class="p-6">
        <UAlert color="error" variant="subtle" icon="i-lucide-plug-zap" title="Éditeur indisponible" :description="editorError" />
      </div>
      <OnlyOfficeEditor v-else-if="setup" :key="mode" :setup="setup" class="h-full" @error="editorError = $event" />
    </template>
  </UDashboardPanel>
</template>
