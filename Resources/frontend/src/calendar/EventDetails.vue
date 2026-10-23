<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue'
import Modal from '../shared/Modal.vue'
import { ApiError, routeWith, type Api } from '../shared/api'
import { notify } from '../shared/alerts'
import { answerLabel, displayFieldValue, formatWhen, reminderLabel } from '../shared/format'
import { t } from '../shared/i18n'
import { describeRecurrence } from '../shared/recurrence'
import type { Scope } from '../shared/form-model'
import type { CalendarInfo, EventDetail } from '../shared/types'
import { permalink } from './permalink'
import type { PageConfig } from './page-config'

const props = defineProps<{ reference: string; recurrenceId: string | null; calendars: CalendarInfo[]; config: PageConfig; api: Api; canDuplicate: boolean }>()
const emit = defineEmits<{ close: []; loaded: [event: EventDetail]; edit: [event: EventDetail, scope: Scope]; duplicate: [event: EventDetail]; deleted: []; stale: [] }>()

// Shallow: the loaded event is only replaced, never mutated, so it is handed to the form as a plain object.
const event = shallowRef<EventDetail | null>(null)
const error = ref('')
const confirming = ref(false)
const busy = ref(false)
// The attendees hear of a delete (a cancellation, or an update for one occurrence) unless the agent unticks it.
const notifyAttendees = ref(true)

const calendar = computed(() => props.calendars.find((item) => item.id === event.value?.calendar_id))
const fieldRows = computed(() => (calendar.value?.fields ?? [])
  .map((field) => ({ field, text: displayFieldValue(field, event.value?.field_values[String(field.id)]) }))
  .filter((row) => row.text !== ''))
const isOccurrence = computed(() => Boolean(event.value?.is_recurring && props.recurrenceId))
// A repeating event opened without an occurrence (a series permalink) is deleted as a whole.
const confirmQuestion = computed(() => {
  if (isOccurrence.value) {
    return t('Delete only this occurrence, or the whole series?')
  }

  return event.value?.is_recurring ? t('Delete the whole series?') : t('Delete this event?')
})

onMounted(async () => {
  try {
    const query = props.recurrenceId ? { recurrence_id: props.recurrenceId } : undefined
    event.value = await props.api.get<EventDetail>(routeWith(props.config.routes.event, props.reference), query)
    emit('loaded', event.value)
  } catch (caught) {
    // A link to an occurrence the series no longer produces: the page opens the event itself instead.
    if (caught instanceof ApiError && caught.code === 'occurrence_missing') {
      emit('stale')

      return
    }
    error.value = caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.')
  }
})

async function copyLink(): Promise<void> {
  if (!event.value) {
    return
  }
  const url = permalink(event.value.id, isOccurrence.value ? props.recurrenceId : null, window.location)
  try {
    await navigator.clipboard.writeText(url)
    notify('success', t('Link copied.'))
  } catch {
    window.prompt(t('Copy this link:'), url)
  }
}

async function remove(scope: Scope): Promise<void> {
  if (!event.value) {
    return
  }
  busy.value = true
  try {
    const body = {
      ...(scope === 'occurrence' ? { scope, recurrence_id: props.recurrenceId } : { scope }),
      ...(event.value.attendees.length ? { notify: notifyAttendees.value } : {}),
    }
    await props.api.delete(routeWith(props.config.routes.event, event.value.id), body)
    notify('success', t('Event deleted.'))
    emit('deleted')
  } catch (caught) {
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="event?.title ?? t('Event')" @close="emit('close')">
    <p v-if="error" class="alert alert-danger">{{ error }}</p>
    <div v-else-if="!event" class="ljpc-loading" role="status">{{ t('Loading…') }}</div>
    <dl v-else class="ljpc-details">
      <dt>{{ t('Calendar') }}</dt>
      <dd><span class="ljpc-swatch" :style="{ backgroundColor: calendar?.color }" aria-hidden="true"></span> {{ calendar?.name }}</dd>
      <dt>{{ t('When') }}</dt>
      <dd>{{ formatWhen(event.start, event.end, event.all_day, config.locale, config.timezone, config.hour12) }}</dd>
      <template v-if="event.recurrence || event.recurrence_custom">
        <dt>{{ t('Repeats') }}</dt>
        <dd>{{ event.recurrence ? describeRecurrence(event.recurrence, config.locale) : t('With a custom rule') }}</dd>
      </template>
      <template v-if="event.location">
        <dt>{{ t('Location') }}</dt>
        <dd>{{ event.location }}</dd>
      </template>
      <template v-if="event.description">
        <dt>{{ t('Description') }}</dt>
        <dd class="ljpc-description">{{ event.description }}</dd>
      </template>
      <template v-for="row in fieldRows" :key="row.field.id">
        <dt>{{ row.field.name }}</dt>
        <dd>{{ row.text }}</dd>
      </template>
      <dt>{{ t('Reminder') }}</dt>
      <dd>{{ reminderLabel(event.reminder_minutes) }}</dd>
      <template v-if="event.author">
        <dt>{{ t('Created by') }}</dt>
        <dd>{{ event.author.name }}</dd>
      </template>
      <template v-if="event.attendees.length">
        <dt>{{ t('Attendees') }}</dt>
        <dd>
          <ul class="ljpc-attendees">
            <li v-for="attendee in event.attendees" :key="attendee.email">
              {{ attendee.name ? `${attendee.name} <${attendee.email}>` : attendee.email }} <span class="text-help">({{ answerLabel(attendee.partstat) }})</span>
            </li>
          </ul>
        </dd>
      </template>
      <template v-if="event.conversation">
        <dt>{{ t('Conversation') }}</dt>
        <dd><a :href="event.conversation.url">#{{ event.conversation.number }}</a></dd>
      </template>
    </dl>
    <p v-if="event && event.editable && event.is_recurring && !event.series_writable" class="help-block">{{ t('This series can only be changed one occurrence at a time.') }}</p>

    <template #footer>
      <div v-if="event && confirming" class="ljpc-confirm" role="alert">
        <span>{{ confirmQuestion }}</span>
        <label v-if="event.attendees.length" class="ljpc-notify"><input v-model="notifyAttendees" type="checkbox"> {{ t('Notify attendees') }}</label>
        <button type="button" class="btn btn-default" :disabled="busy" @click="confirming = false">{{ t('Cancel') }}</button>
        <template v-if="isOccurrence">
          <button type="button" class="btn btn-danger" :disabled="busy" @click="remove('series')">{{ t('Whole series') }}</button>
          <button type="button" class="btn btn-danger" :disabled="busy" @click="remove('occurrence')">{{ t('This occurrence') }}</button>
        </template>
        <button v-else type="button" class="btn btn-danger" :disabled="busy" @click="remove('series')">{{ t('Delete') }}</button>
      </div>
      <template v-else-if="event">
        <button type="button" class="btn btn-link pull-left" @click="copyLink">{{ t('Copy link') }}</button>
        <button v-if="canDuplicate" type="button" class="btn btn-default" @click="emit('duplicate', event)">{{ t('Duplicate') }}</button>
        <template v-if="event.editable">
          <button type="button" class="btn btn-default" @click="confirming = true">{{ t('Delete') }}</button>
          <template v-if="isOccurrence">
            <button v-if="event.series_writable" type="button" class="btn btn-default" @click="emit('edit', event, 'series')">{{ t('Edit series') }}</button>
            <button type="button" class="btn btn-primary" @click="emit('edit', event, 'occurrence')">{{ t('Edit this occurrence') }}</button>
          </template>
          <button v-else-if="event.series_writable" type="button" class="btn btn-primary" @click="emit('edit', event, 'series')">{{ t('Edit') }}</button>
        </template>
      </template>
      <button v-else type="button" class="btn btn-default" @click="emit('close')">{{ t('Close') }}</button>
    </template>
  </Modal>
</template>
