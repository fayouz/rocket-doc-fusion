<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { MergedDocument } from '~/types/documents'

const appName = useAppConfig().rocket.name
useHead({ title: `Mes documents · ${appName}` })

const api = useApi()
const toast = useToast()
const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UTooltip = resolveComponent('UTooltip')

const search = ref('')
const searchDebounced = ref('')
let debounce: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(debounce)
  debounce = setTimeout(() => (searchDebounced.value = value), 300)
})

const { data: documents, status, refresh } = await useAsyncData('documents', () => api<MergedDocument[]>('/api/documents', {
  query: { 'itemsPerPage': 100, 'title': searchDebounced.value || undefined, 'order[updatedAt]': 'desc' },
}), { default: () => [], watch: [searchDebounced] })

// While documents are being merged, follow them.
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(() => {
    if (documents.value.some(d => d.status === 'merging') && !document.hidden) refresh()
  }, 2000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  clearTimeout(debounce)
})

async function download(doc: MergedDocument, format: 'docx' | 'pdf') {
  try {
    const blob = await api<Blob>(`/api/documents/${doc.id}/content`, { query: { format, download: 1 }, responseType: 'blob' })
    const link = Object.assign(window.document.createElement('a'), { href: URL.createObjectURL(blob), download: `${doc.title}.${format}` })
    link.click()
    URL.revokeObjectURL(link.href)
  }
  catch (error) {
    toast.add({ title: 'Téléchargement impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove(doc: MergedDocument) {
  if (!confirm(`Supprimer « ${doc.title} » et toutes ses versions ?`)) return
  try {
    await api(`/api/documents/${doc.id}`, { method: 'DELETE' })
    toast.add({ title: 'Document supprimé', color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Suppression impossible', description: apiErrorMessage(error), color: 'error' })
  }
}

const columns: TableColumn<MergedDocument>[] = [
  {
    accessorKey: 'title',
    header: 'Document',
    cell: ({ row }) => h('div', { class: 'min-w-0' }, [
      h(resolveComponent('NuxtLink'), { to: `/documents/${row.original.id}`, class: 'truncate font-medium text-highlighted hover:underline' }, () => row.original.title),
      h('p', { class: 'text-xs text-muted' }, [row.original.templateName, row.original.size ? ` · ${formatSize(row.original.size)}` : '', row.original.applicationName ? ` · via ${row.original.applicationName}` : ''].join('')),
    ]),
  },
  {
    accessorKey: 'status',
    header: 'Statut',
    cell: ({ row }) => {
      const s = DOCUMENT_STATUS[row.original.status]
      const badge = h(UBadge, { label: s.label, color: s.color, icon: s.icon, variant: 'subtle', class: row.original.status === 'merging' ? '[&>span]:animate-spin' : '' })
      return row.original.error ? h(UTooltip, { text: row.original.error }, () => badge) : badge
    },
  },
  { accessorKey: 'version', header: 'Version', cell: ({ row }) => row.original.version > 0 ? `v${row.original.version}` : '—' },
  { accessorKey: 'updatedAt', header: 'Modifié', cell: ({ row }) => h('span', { title: formatDate(row.original.updatedAt) }, timeAgo(row.original.updatedAt)) },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'flex justify-end gap-1' }, [
      row.original.ready && h(UButton, { 'icon': 'i-lucide-file-pen-line', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Ouvrir dans l’éditeur', 'to': `/documents/${row.original.id}` }),
      row.original.ready && h(UButton, { 'icon': 'i-lucide-file-down', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Télécharger (Word)', 'onClick': () => download(row.original, 'docx') }),
      row.original.ready && h(UButton, { 'icon': 'i-lucide-file-type', 'color': 'neutral', 'variant': 'ghost', 'aria-label': 'Télécharger (PDF)', 'onClick': () => download(row.original, 'pdf') }),
      h(UButton, { 'icon': 'i-lucide-trash-2', 'color': 'error', 'variant': 'ghost', 'aria-label': 'Supprimer', 'onClick': () => remove(row.original) }),
    ]),
  },
]
</script>

<template>
  <UDashboardPanel id="documents">
    <template #header>
      <UDashboardNavbar title="Mes documents">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-file-stack" label="Fusionner" to="/merge" />
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <UInput v-model="search" icon="i-lucide-search" placeholder="Rechercher un document" class="w-full sm:w-64" />
      </UDashboardToolbar>
    </template>

    <template #body>
      <UTable :data="documents" :columns="columns" :loading="status === 'pending'" empty="Aucun document : fusionnez un modèle depuis « Fusionner »." />
    </template>
  </UDashboardPanel>
</template>
