<script setup lang="ts">
import Modal from '../shared/Modal.vue'
import { t } from '../shared/i18n'
import type { Scope } from '../shared/form-model'

// Without allowSeries only the occurrence can be chosen: a series whose rule its calendar's provider can't write.
const { question, allowSeries = true } = defineProps<{ question: string; allowSeries?: boolean }>()
const emit = defineEmits<{ choose: [scope: Scope | null] }>()
</script>

<template>
  <Modal :title="t('Repeating event')" size="sm" @close="emit('choose', null)">
    <p>{{ question }}</p>
    <template #footer>
      <button type="button" class="btn btn-default" @click="emit('choose', null)">{{ t('Cancel') }}</button>
      <button v-if="allowSeries" type="button" class="btn btn-default" @click="emit('choose', 'series')">{{ t('Whole series') }}</button>
      <button type="button" class="btn btn-primary" @click="emit('choose', 'occurrence')">{{ t('This occurrence') }}</button>
    </template>
  </Modal>
</template>
