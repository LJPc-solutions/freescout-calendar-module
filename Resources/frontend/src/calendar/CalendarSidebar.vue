<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '../shared/i18n'
import type { CalendarInfo } from '../shared/types'

// FreeScout's own sidebar (a mailbox's folders): the page's Blade view holds the column, this fills it. Below 992px
// core folds the menu into a panel behind its menu button; core binds that button before Vue renders, so it is ours.
const props = defineProps<{ calendars: CalendarInfo[]; hidden: number[]; canCreate: boolean; settingsUrl: string | null; syncing: boolean }>()
const emit = defineEmits<{ toggle: [id: number]; newEvent: []; sync: [] }>()

const open = ref(false)
// From 992px the column shows its primary "New event" button, so the buttons below it leave the plus out.
const wideQuery = window.matchMedia('(min-width: 992px)')
const wide = ref(wideQuery.matches)
const canSync = computed(() => props.calendars.some((calendar) => calendar.provider !== 'local'))
const buttons = computed(() => (props.settingsUrl ? 1 : 0) + (canSync.value ? 1 : 0) + (props.canCreate && !wide.value ? 1 : 0))

function onWidth(event: MediaQueryListEvent): void {
  wide.value = event.matches
}

function close(): void {
  open.value = false
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
  }
}

function readOnly(calendar: CalendarInfo): boolean {
  return !calendar.writable || (!calendar.rights.can_create && !calendar.rights.can_edit)
}

onMounted(() => {
  document.addEventListener('click', close)
  document.addEventListener('keydown', onKey)
  wideQuery.addEventListener('change', onWidth)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', close)
  document.removeEventListener('keydown', onKey)
  wideQuery.removeEventListener('change', onWidth)
})
</script>

<template>
  <Teleport to="#ljpc-calendar-sidebar">
    <button
      v-if="calendars.length"
      type="button"
      class="sidebar-menu-toggle"
      :class="{ active: open }"
      :aria-label="t('Show calendars')"
      :aria-expanded="open"
      aria-controls="ljpc-calendar-list"
      @click.stop="open = !open"
    >
      <span class="icon-bar"></span>
      <span class="icon-bar"></span>
      <span class="icon-bar"></span>
    </button>
    <div class="sidebar-title">{{ t('Calendar') }}</div>
    <button v-if="canCreate" type="button" class="btn btn-primary ljpc-sidebar-new" @click="emit('newEvent')">
      <i class="glyphicon glyphicon-plus" aria-hidden="true"></i> {{ t('New event') }}
    </button>
    <ul v-if="calendars.length" id="ljpc-calendar-list" class="sidebar-menu ljpc-calendar-menu" :class="{ active: open }" @click.stop>
      <li v-for="calendar in calendars" :key="calendar.id">
        <a
          href="#"
          role="switch"
          :aria-checked="!hidden.includes(calendar.id)"
          :class="{ 'no-active': hidden.includes(calendar.id) }"
          :title="calendar.name"
          @click.prevent="emit('toggle', calendar.id)"
        >
          <span class="ljpc-swatch" :style="{ backgroundColor: hidden.includes(calendar.id) ? 'transparent' : calendar.color, borderColor: calendar.color }" aria-hidden="true"></span>
          <span class="ljpc-calendar-name">{{ calendar.name }}</span>
          <template v-if="readOnly(calendar)">
            <i class="glyphicon glyphicon-lock ljpc-read-only" :title="t('Read-only')" aria-hidden="true"></i>
            <span class="sr-only">{{ t('Read-only') }}</span>
          </template>
        </a>
      </li>
    </ul>
    <div v-if="buttons > 0" class="sidebar-buttons btn-group btn-group-justified ljpc-sidebar-buttons" :style="{ '--ljpc-buttons': buttons }">
      <a v-if="settingsUrl" :href="settingsUrl" class="btn btn-trans" :title="t('Manage calendars')" :aria-label="t('Manage calendars')">
        <i class="glyphicon glyphicon-cog" aria-hidden="true"></i>
      </a>
      <!-- Links, like a mailbox's buttons: Bootstrap's justified group only lays out links. -->
      <a
        v-if="canSync"
        href="#"
        role="button"
        class="btn btn-trans ljpc-sidebar-sync"
        :class="{ disabled: syncing }"
        :title="t('Sync now')"
        :aria-label="t('Sync now')"
        :aria-busy="syncing"
        @click.prevent="syncing || emit('sync')"
      >
        <i class="glyphicon glyphicon-refresh" :class="{ 'ljpc-spin': syncing }" aria-hidden="true"></i>
      </a>
      <a v-if="canCreate && !wide" href="#" role="button" class="btn btn-trans ljpc-sidebar-plus" :title="t('New event')" :aria-label="t('New event')" @click.prevent="emit('newEvent')">
        <i class="glyphicon glyphicon-plus" aria-hidden="true"></i>
      </a>
    </div>
  </Teleport>
</template>
