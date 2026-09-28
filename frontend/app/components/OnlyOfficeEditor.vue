<script setup lang="ts">
import type { EditorSetup } from '~/types/documents'

/**
 * The ONLYOFFICE editor (DocsAPI.DocEditor) of a document, from the signed configuration of
 * GET /api/documents/{id}/editor: its script comes from ONLYOFFICE Docs itself.
 */
const props = defineProps<{ setup: EditorSetup }>()
const emit = defineEmits<{ ready: [], error: [message: string] }>()

interface DocEditor { destroyEditor: () => void }
type DocsApi = { DocEditor: new (id: string, config: Record<string, unknown>) => DocEditor }

const id = `onlyoffice-${useId()}`
let editor: DocEditor | null = null

function loadScript(src: string): Promise<void> {
  const w = window as unknown as { DocsAPI?: DocsApi }
  if (w.DocsAPI) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${src}"]`)
    const script = existing ?? Object.assign(document.createElement('script'), { src, async: true })
    script.addEventListener('load', () => resolve(), { once: true })
    script.addEventListener('error', () => reject(new Error(`ONLYOFFICE Docs est injoignable (${new URL(src).origin}).`)), { once: true })
    if (!existing) document.head.appendChild(script)
  })
}

async function open() {
  editor?.destroyEditor()
  editor = null
  try {
    await loadScript(props.setup.scriptUrl)
    const DocsAPI = (window as unknown as { DocsAPI: DocsApi }).DocsAPI
    editor = new DocsAPI.DocEditor(id, {
      ...props.setup.config,
      width: '100%',
      height: '100%',
      events: {
        onDocumentReady: () => emit('ready'),
        onError: (event: { data?: { errorDescription?: string } }) => emit('error', event.data?.errorDescription ?? 'Erreur de l’éditeur ONLYOFFICE.'),
      },
    })
  }
  catch (error) {
    emit('error', error instanceof Error ? error.message : String(error))
  }
}

onMounted(open)
watch(() => props.setup, open)
onBeforeUnmount(() => editor?.destroyEditor())
</script>

<template>
  <div class="size-full min-h-[480px]">
    <div :id="id" />
  </div>
</template>
