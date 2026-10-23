<script setup lang="ts">
import { computed } from 'vue'
import { t } from './i18n'
import type { FieldDefinition, FieldValue } from './types'

const props = defineProps<{ field: FieldDefinition; modelValue: FieldValue | undefined; error?: string; idPrefix: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: FieldValue] }>()

const inputId = computed(() => `${props.idPrefix}-field-${props.field.id}`)
const inputType = computed(() => ({ number: 'number', date: 'date', email: 'email' } as Record<string, string>)[props.field.type] ?? 'text')
const selected = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []))

function setValue(event: Event): void {
  const input = event.target as HTMLInputElement
  emit('update:modelValue', props.field.type === 'number' && input.value !== '' ? Number(input.value) : input.value)
}

function toggleOption(option: string, checked: boolean): void {
  const next = selected.value.filter((value) => value !== option)
  emit('update:modelValue', checked ? [...next, option] : next)
}
</script>

<template>
  <div class="form-group" :class="{ 'has-error': error }">
    <div v-if="field.type === 'boolean'" class="checkbox">
      <label>
        <input type="checkbox" :checked="modelValue === true" @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)">
        {{ field.name }}<span v-if="field.required" aria-hidden="true"> *</span>
      </label>
    </div>
    <template v-else>
      <label :for="field.type === 'multiselect' ? undefined : inputId" class="control-label">
        {{ field.name }}<span v-if="field.required" aria-hidden="true"> *</span>
      </label>
      <select
        v-if="field.type === 'dropdown'"
        :id="inputId"
        class="form-control"
        :value="modelValue ?? ''"
        :required="field.required"
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
      >
        <option value="">{{ t('Choose an option') }}</option>
        <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
      </select>
      <div v-else-if="field.type === 'multiselect'" role="group" :aria-label="field.name">
        <div v-for="option in field.options" :key="option" class="checkbox">
          <label>
            <input type="checkbox" :checked="selected.includes(option)" @change="toggleOption(option, ($event.target as HTMLInputElement).checked)">
            {{ option }}
          </label>
        </div>
      </div>
      <input
        v-else
        :id="inputId"
        :type="inputType"
        class="form-control"
        :value="modelValue ?? ''"
        :required="field.required"
        :step="field.type === 'number' ? 'any' : undefined"
        @input="setValue"
      >
    </template>
    <p v-if="error" class="help-block">{{ error }}</p>
  </div>
</template>
