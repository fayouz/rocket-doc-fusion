<script setup lang="ts">
import type { EmbedContext } from '#rocket/composables/useEmbedBridge'
import type { EditorSetup, MergedDocument } from '~/types/documents'

/**
 * The ONLYOFFICE editor of a document, embedded in an application (Rocket Dispatch…):
 * /embed/editor?app=<application>&document=<id>&mode=edit|view. Authentication: an embed token minted by the
 * application for its user (useEmbedBridge). Tells the host "document" (loaded), "editor-ready" and "saved" (force save).
 * The host may ask "save".
 */
definePageMeta({ layout: 'bare' })
useHead({ title: 'Rocket Doc Fusion' })

const route = useRoute()
const bridge = useEmbedBridge()
const api = useApi()

const state = ref<'loading' | 'ready' | 'error'>('loading')
const errorMessage = ref('')
const context = ref<EmbedContext | null>(null)
const setup = ref<EditorSetup | null>(null)
const documentId = computed(() => typeof route.query.document === 'string' ? route.query.document : '')
const mode = computed(() => route.query.mode === 'view' ? 'view' : 'edit')
let stopSave: (() => void) | undefined

onMounted(async () => {
  try {
    context.value = await bridge.connect(typeof route.query.app === 'string' ? route.query.app : '')
    let doc = await api<MergedDocument>(`/api/documents/${documentId.value}`)
    for (let i = 0; doc.status === 'merging' && i < 120; i++) {
      await new Promise(resolve => setTimeout(resolve, 1000))
      doc = await api<MergedDocument>(`/api/documents/${documentId.value}`)
    }
    bridge.notify('document', { document: { id: doc.id, title: doc.title, status: doc.status, version: doc.version, error: doc.error } })
    if (!doc.ready) throw new Error(doc.error ?? 'Le document n’est pas prêt.')
    setup.value = await api<EditorSetup>(`/api/documents/${documentId.value}/editor`, { query: { mode: mode.value } })
    stopSave = bridge.onHostMessage('save', async () => {
      const result = await api<{ saved: boolean, version: number }>(`/api/documents/${documentId.value}/force-save`, { method: 'POST' })
      bridge.notify('saved', result)
    })
    state.value = 'ready'
  }
  catch (error) {
    errorMessage.value = apiErrorMessage(error)
    state.value = 'error'
  }
})

onBeforeUnmount(() => stopSave?.())
</script>

<template>
  <div class="h-screen">
    <div v-if="state === 'loading'" class="flex items-center gap-2 p-10 text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" /> Chargement du document…
    </div>
    <div v-else-if="state === 'error'" class="p-4">
      <UAlert color="error" variant="subtle" icon="i-lucide-shield-alert" title="Document indisponible" :description="errorMessage" />
    </div>
    <OnlyOfficeEditor v-else-if="setup" :setup="setup" class="h-full" @ready="bridge.notify('editor-ready')" @error="errorMessage = $event; state = 'error'" />
  </div>
</template>
