<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import FieldInput from '../shared/FieldInput.vue'
import Modal from '../shared/Modal.vue'
import TimeFields from '../shared/TimeFields.vue'
import { ApiError, createApi } from '../shared/api'
import { eventRef } from '../calendar/permalink'
import { addressList, reminderOptions } from '../shared/format'
import { emptyForm, toCreatePayload, withCalendar, type FormState } from '../shared/form-model'
import { t } from '../shared/i18n'
import { readStored, writeStored } from '../shared/storage'
import { nextFullHour } from '../shared/time'
import type { CalendarInfo, EventDetail, FieldValue } from '../shared/types'
import type { ConversationConfig } from './conversation-config'
import { conversationFieldValues, conversationTitle } from './prefill'

const props = defineProps<{ config: ConversationConfig }>()
const emit = defineEmits<{ close: [] }>()
const api = createApi(props.config.csrf)
const formId = 'ljpc-conversation-event'
const LAST_CALENDAR = 'ljpc-calendar.last-calendar'

const calendars = ref<CalendarInfo[] | null>(null)
const form = ref<FormState | null>(null)
const errors = ref<Record<string, string>>({})
const generalError = ref('')
const saving = ref(false)
const saved = ref<EventDetail | null>(null)

// Invites (decision 2026-10-19: inviting the customer is off until the agent turns it on).
const inviteCustomer = ref(false)
const otherAddresses = ref('')
const inviteMessage = ref('')
const attendees = computed(() => [
  ...(inviteCustomer.value && props.config.conversation.customer_email ? [props.config.conversation.customer_email] : []),
  ...addressList(otherAddresses.value),
])

const calendar = computed(() => calendars.value?.find((item) => item.id === form.value?.calendarId))
const fields = computed(() => calendar.value?.fields ?? [])

function startForm(calendarId: number): void {
  const nextFields = calendars.value?.find((item) => item.id === calendarId)?.fields ?? []
  const base = form.value ?? emptyForm(calendarId, nextFullHour(props.config.timezone), props.config.timezone)
  const { subject, assignee, customer_email } = props.config.conversation
  // Switching calendar keeps the values of fields both calendars share; the rest starts from the conversation's defaults.
  const kept = withCalendar(base, calendarId, nextFields)
  const defaults = conversationFieldValues(nextFields, customer_email)
  const values: Record<string, FieldValue> = {}
  for (const [id, value] of Object.entries(defaults)) {
    const carried = kept.fields[id]
    values[id] = carried === undefined || carried === '' ? value : carried
  }
  form.value = { ...kept, title: base.title || conversationTitle(subject, assignee), fields: values }
}

onMounted(async () => {
  try {
    const all = await api.get<CalendarInfo[]>(props.config.routes.calendars)
    calendars.value = all.filter((item) => item.writable && item.rights.can_create)
    const last = readStored<number | null>(LAST_CALENDAR, null, (value) => typeof value === 'number')
    const first = calendars.value.find((item) => item.id === last) ?? calendars.value[0]
    if (first) {
      startForm(first.id)
    }
  } catch (caught) {
    calendars.value = []
    generalError.value = caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.')
  }
})

async function save(): Promise<void> {
  if (!form.value) {
    return
  }
  saving.value = true
  errors.value = {}
  generalError.value = ''
  try {
    const invite = attendees.value.length ? { attendees: attendees.value, message: inviteMessage.value } : {}
    saved.value = await api.post<EventDetail>(props.config.routes.conversation_events, { ...toCreatePayload(form.value, fields.value), ...invite })
    writeStored(LAST_CALENDAR, saved.value.calendar_id)
  } catch (caught) {
    if (caught instanceof ApiError && caught.status === 422) {
      errors.value = caught.fieldErrors()
    }
    generalError.value = caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :title="t('Add to calendar')" size="lg" @close="emit('close')">
    <div v-if="saved" class="ljpc-saved" role="status">
      <p>{{ t('The event was added to the calendar. The conversation shows it the next time it is opened.') }}</p>
      <p v-if="saved.attendees.length">{{ t('The invite goes to the attendees as a reply in this conversation.') }}</p>
    </div>
    <div v-else-if="calendars === null" class="ljpc-loading" role="status">{{ t('Loading…') }}</div>
    <p v-else-if="!form" class="alert alert-info">{{ generalError || t('You can\'t add events to any calendar. An administrator can give you access.') }}</p>
    <form v-else :id="formId" @submit.prevent="save">
      <p v-if="generalError" class="alert alert-danger" role="alert">{{ generalError }}</p>
      <div class="form-group" :class="{ 'has-error': errors.calendar_id }">
        <label :for="`${formId}-calendar`" class="control-label">{{ t('Calendar') }}</label>
        <select :id="`${formId}-calendar`" class="form-control" :value="form.calendarId ?? ''" @change="startForm(Number(($event.target as HTMLSelectElement).value))">
          <option v-for="choice in calendars" :key="choice.id" :value="choice.id">{{ choice.name }}</option>
        </select>
        <p v-if="errors.calendar_id" class="help-block">{{ errors.calendar_id }}</p>
      </div>
      <!-- A calendar with a title template builds the title itself; the prefilled text still fills {{title}}. -->
      <div v-if="!calendar?.has_title_template" class="form-group" :class="{ 'has-error': errors.title }">
        <label :for="`${formId}-title`" class="control-label">{{ t('Title') }}</label>
        <input :id="`${formId}-title`" v-model="form.title" type="text" class="form-control" maxlength="500" required>
        <p v-if="errors.title" class="help-block">{{ errors.title }}</p>
      </div>
      <TimeFields :state="form" :time-zone="config.timezone" :errors="errors" :id-prefix="formId" @change="form = $event" />
      <div class="form-group" :class="{ 'has-error': errors.reminder_minutes }">
        <label :for="`${formId}-reminder`" class="control-label">{{ t('Reminder') }}</label>
        <select
          :id="`${formId}-reminder`"
          class="form-control"
          :value="form.reminder === null ? '' : String(form.reminder)"
          @change="form.reminder = ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="option in reminderOptions()" :key="String(option.value)" :value="option.value === null ? '' : String(option.value)">{{ option.label }}</option>
        </select>
      </div>
      <FieldInput
        v-for="field in fields"
        :key="field.id"
        :field="field"
        :model-value="form.fields[String(field.id)]"
        :error="errors[`field_values.${field.id}`]"
        :id-prefix="formId"
        @update:model-value="form.fields[String(field.id)] = $event"
      />
      <fieldset class="ljpc-invite-fields">
        <legend>{{ t('Invite') }}</legend>
        <div v-if="config.conversation.customer_email" class="checkbox">
          <label><input v-model="inviteCustomer" type="checkbox"> {{ t('Invite the customer (:email)', { email: config.conversation.customer_email }) }}</label>
        </div>
        <div class="form-group" :class="{ 'has-error': errors.attendees }">
          <label :for="`${formId}-attendees`" class="control-label">{{ t('Other people to invite') }}</label>
          <input :id="`${formId}-attendees`" v-model="otherAddresses" type="text" class="form-control" :placeholder="t('Email addresses, separated by commas')">
          <p v-if="errors.attendees" class="help-block">{{ errors.attendees }}</p>
        </div>
        <div v-if="attendees.length" class="form-group" :class="{ 'has-error': errors.message }">
          <label :for="`${formId}-message`" class="control-label">{{ t('Message with the invite') }}</label>
          <textarea :id="`${formId}-message`" v-model="inviteMessage" class="form-control" rows="3" maxlength="5000"></textarea>
          <p class="help-block">{{ errors.message || t('The invite goes out as a reply in this conversation: this message, then what, when and where.') }}</p>
        </div>
      </fieldset>
    </form>

    <template #footer>
      <template v-if="saved">
        <a class="btn btn-default" :href="`${config.routes.page}#${eventRef(saved.id)}`" target="_blank" rel="noopener">{{ t('Open in calendar') }}</a>
        <button type="button" class="btn btn-primary" @click="emit('close')">{{ t('Close') }}</button>
      </template>
      <template v-else>
        <button type="button" class="btn btn-default" @click="emit('close')">{{ t('Cancel') }}</button>
        <button v-if="form" type="submit" class="btn btn-primary" :form="formId" :disabled="saving">{{ saving ? t('Saving…') : attendees.length ? t('Add and invite') : t('Add to calendar') }}</button>
      </template>
    </template>
  </Modal>
</template>
