<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from './i18n'

/** A Bootstrap 3 modal driven by Vue (FreeScout's look, without Bootstrap's jQuery plugin). */
defineProps<{ title: string; size?: 'sm' | 'lg' }>()
const emit = defineEmits<{ close: [] }>()
const content = ref<HTMLElement | null>(null)
let previousFocus: HTMLElement | null = null

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => {
  previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  document.body.classList.add('modal-open')
  document.addEventListener('keydown', onKeydown)
  void nextTick(() => content.value?.focus())
})

onBeforeUnmount(() => {
  document.body.classList.remove('modal-open')
  document.removeEventListener('keydown', onKeydown)
  previousFocus?.focus()
})
</script>

<template>
  <Teleport to="body">
    <div class="ljpc-calendar ljpc-modal">
      <div class="modal-backdrop fade in"></div>
      <div class="modal fade in ljpc-modal-shown" role="dialog" aria-modal="true" :aria-label="title" @mousedown.self="emit('close')">
        <div class="modal-dialog" :class="size ? `modal-${size}` : ''">
          <div ref="content" class="modal-content" tabindex="-1">
            <div class="modal-header">
              <button type="button" class="close" :aria-label="t('Close')" @click="emit('close')"><span aria-hidden="true">&times;</span></button>
              <h4 class="modal-title">{{ title }}</h4>
            </div>
            <div class="modal-body"><slot /></div>
            <div v-if="$slots.footer" class="modal-footer"><slot name="footer" /></div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
