<script setup lang="ts">
import type { TemplateVariables } from '~/types/documents'

/** Values of a template: one field per variable, and a table per repeated section (one line per item). */
const props = defineProps<{ template: TemplateVariables }>()
const values = defineModel<Record<string, unknown>>({ required: true })

function rows(section: string): Record<string, string>[] {
  if (!Array.isArray(values.value[section])) values.value[section] = []
  return values.value[section] as Record<string, string>[]
}

function addRow(section: { name: string, fields: string[] }) {
  rows(section.name).push(Object.fromEntries(section.fields.map(field => [field, ''])))
}

watch(() => props.template, (template) => {
  for (const name of template.variables) values.value[name] ??= ''
  for (const section of template.sections) {
    if (!rows(section.name).length) addRow(section)
  }
}, { immediate: true })
</script>

<template>
  <div class="flex flex-col gap-6">
    <div v-if="template.variables.length" class="grid gap-4 sm:grid-cols-2">
      <UFormField v-for="name in template.variables" :key="name" :label="variableLabel(name)" :hint="placeholder(name)">
        <UInput v-model="(values[name] as string)" class="w-full" />
      </UFormField>
    </div>

    <div v-for="section in template.sections" :key="section.name" class="flex flex-col gap-2">
      <div class="flex items-center justify-between">
        <p class="font-medium text-highlighted">
          {{ variableLabel(section.name) }} <span class="text-xs font-normal text-muted">{{ placeholder(`#${section.name}`) }} · une ligne du tableau par élément</span>
        </p>
        <UButton icon="i-lucide-plus" label="Ajouter une ligne" size="sm" color="neutral" variant="outline" @click="addRow(section)" />
      </div>
      <div v-for="(row, index) in rows(section.name)" :key="index" class="flex items-end gap-2">
        <UFormField v-for="field in section.fields" :key="field" :label="index === 0 ? variableLabel(field) : undefined" class="flex-1">
          <UInput v-model="row[field]" class="w-full" />
        </UFormField>
        <UButton icon="i-lucide-trash-2" color="neutral" variant="ghost" aria-label="Supprimer la ligne" @click="rows(section.name).splice(index, 1)" />
      </div>
    </div>
  </div>
</template>
