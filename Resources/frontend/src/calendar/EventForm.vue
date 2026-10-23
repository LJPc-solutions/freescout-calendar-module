<script setup lang="ts">
import { computed, ref } from 'vue'
import FieldInput from '../shared/FieldInput.vue'
import Modal from '../shared/Modal.vue'
import TimeFields from '../shared/TimeFields.vue'
import { ApiError, asksToDropExceptions, routeWith, type Api } from '../shared/api'
import { addressList, reminderOptions } from '../shared/format'
import { copyForm, ruleStartDate, toCreatePayload, toOccurrencePatch, toSeriesPatch, withCalendar, withoutCustomRule, type FormState } from '../shared/form-model'
import { t } from '../shared/i18n'
import type { CalendarInfo, EventDetail } from '../shared/types'
import RecurrenceEditor from './RecurrenceEditor.vue'
import type { PageConfig } from './page-config'

/** create: a new event; series: the whole event; occurrence: one occurrence of a repeating event. */
const props = defineProps<{
  mode: 'create' | 'series' | 'occurrence'
  initial: FormState
  event: EventDetail | null
  recurrenceId: string | null
  calendars: CalendarInfo[]
  config: PageConfig
  api: Api
}>()
const emit = defineEmits<{ close: []; saved: [event: EventDetail] }>()

// A plain copy, so `initial` may hold Vue proxies (structuredClone rejects them). A new event never keeps a custom rule.
const form = ref<FormState>(copyForm(props.mode === 'create' ? withoutCustomRule(props.initial) : props.initial))
const errors = ref<Record<string, string>>({})
const generalError = ref('')
const saving = ref(false)
// The server's question when a whole-series save would reset edited and deleted occurrences.
const resetQuestion = ref('')
const formId = `ljpc-event-form-${props.mode}`
// Attendees (InviteSender): only the whole event, and only one added from a conversation, has them.
const invitedBefore = (props.event?.attendees ?? []).map((attendee) => attendee.email)
const attendeeText = ref(invitedBefore.join(', '))
const notifyAttendees = ref(true)
const canInvite = computed(() => props.mode === 'series' && Boolean(props.event?.can_invite))
const hasAttendees = computed(() => props.mode !== 'create' && (invitedBefore.length > 0 || (canInvite.value && addressList(attendeeText.value).length > 0)))

const choices = computed(() => props.calendars.filter((calendar) =>
  (calendar.writable && calendar.rights.can_create) || calendar.id === props.event?.calendar_id))
const calendar = computed(() => props.calendars.find((item) => item.id === form.value.calendarId))
const fields = computed(() => calendar.value?.fields ?? [])
const title = computed(() => ({
  create: t('New event'),
  series: props.event?.is_recurring ? t('Edit series') : t('Edit event'),
  occurrence: t('Edit this occurrence'),
})[props.mode])

function setCalendar(id: number): void {
  form.value = withCalendar(form.value, id, props.calendars.find((item) => item.id === id)?.fields ?? [])
}

async function save(dropExceptions = false): Promise<void> {
  saving.value = true
  errors.value = {}
  generalError.value = ''
  resetQuestion.value = ''
  try {
    let saved: EventDetail
    if (props.mode === 'create') {
      saved = await props.api.post<EventDetail>(props.config.routes.events, toCreatePayload(form.value, fields.value))
    } else {
      const payload = props.mode === 'occurrence'
        ? toOccurrencePatch(props.initial, form.value, props.recurrenceId ?? '')
        : toSeriesPatch(props.initial, form.value, fields.value, props.event?.is_recurring ?? false)
      const attendees = addressList(attendeeText.value)
      saved = await props.api.patch<EventDetail>(routeWith(props.config.routes.event, props.event?.id ?? 0), {
        ...payload,
        ...(dropExceptions ? { drop_exceptions: true } : {}),
        ...(canInvite.value && attendees.join() !== invitedBefore.join() ? { attendees } : {}),
        ...(hasAttendees.value ? { notify: notifyAttendees.value } : {}),
      })
    }
    emit('saved', saved)
  } catch (caught) {
    // Nothing was saved. Continue sends the same form again with drop_exceptions; Cancel keeps the agent's input.
    if (asksToDropExceptions(caught)) {
      resetQuestion.value = caught.message

      return
    }
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
  <Modal :title="title" size="lg" @close="emit('close')">
    <form :id="formId" class="ljpc-event-form" @submit.prevent="save()">
      <p v-if="generalError" class="alert alert-danger" role="alert">{{ generalError }}</p>

      <div class="form-group" :class="{ 'has-error': errors.calendar_id }">
        <label :for="`${formId}-calendar`" class="control-label">{{ t('Calendar') }}</label>
        <select
          :id="`${formId}-calendar`"
          class="form-control"
          :disabled="mode === 'occurrence' || choices.length < 2"
          :value="form.calendarId ?? ''"
          @change="setCalendar(Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-for="choice in choices" :key="choice.id" :value="choice.id">{{ choice.name }}</option>
        </select>
        <p v-if="errors.calendar_id" class="help-block">{{ errors.calendar_id }}</p>
      </div>

      <div class="form-group" :class="{ 'has-error': errors.title }">
        <label :for="`${formId}-title`" class="control-label">{{ t('Title') }}</label>
        <input :id="`${formId}-title`" v-model="form.title" type="text" class="form-control" maxlength="500" :required="!calendar?.has_title_template">
        <p v-if="calendar?.has_title_template && mode !== 'occurrence'" class="help-block">{{ t('This calendar builds the title from a template that can use this text.') }}</p>
        <p v-if="errors.title" class="help-block">{{ errors.title }}</p>
      </div>

      <TimeFields :state="form" :time-zone="config.timezone" :errors="errors" :id-prefix="formId" @change="form = $event" />

      <template v-if="mode !== 'occurrence'">
        <p v-if="form.recurrenceCustom" class="alert alert-info">{{ t('This event repeats with a rule that can only be changed in the app it came from. It is kept as it is.') }}</p>
        <RecurrenceEditor v-else v-model="form.recurrence" :start-date="ruleStartDate(form, config.timezone)" :locale="config.locale" :errors="errors" :id-prefix="formId" />
      </template>

      <div class="form-group" :class="{ 'has-error': errors.location }">
        <label :for="`${formId}-location`" class="control-label">{{ t('Location') }}</label>
        <input :id="`${formId}-location`" v-model="form.location" type="text" class="form-control" maxlength="500">
        <p v-if="errors.location" class="help-block">{{ errors.location }}</p>
      </div>

      <div class="form-group" :class="{ 'has-error': errors.description }">
        <label :for="`${formId}-description`" class="control-label">{{ t('Description') }}</label>
        <textarea :id="`${formId}-description`" v-model="form.description" class="form-control" rows="4" maxlength="20000"></textarea>
        <p v-if="errors.description" class="help-block">{{ errors.description }}</p>
      </div>

      <div v-if="canInvite" class="form-group" :class="{ 'has-error': errors.attendees }">
        <label :for="`${formId}-attendees`" class="control-label">{{ t('Attendees') }}</label>
        <input :id="`${formId}-attendees`" v-model="attendeeText" type="text" class="form-control" :placeholder="t('Email addresses, separated by commas')">
        <p v-if="errors.attendees" class="help-block">{{ errors.attendees }}</p>
      </div>
      <div v-if="hasAttendees" class="checkbox">
        <label><input v-model="notifyAttendees" type="checkbox"> {{ t('Notify attendees') }}</label>
      </div>

      <template v-if="mode !== 'occurrence'">
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
          <p v-if="errors.reminder_minutes" class="help-block">{{ errors.reminder_minutes }}</p>
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
      </template>
      <p v-else class="text-help">{{ t('The repeat rule, the reminder and the custom fields belong to the whole series.') }}</p>
    </form>

    <template #footer>
      <div v-if="resetQuestion" class="ljpc-confirm" role="alert">
        <span>{{ resetQuestion }}</span>
        <button type="button" class="btn btn-default" @click="resetQuestion = ''">{{ t('Cancel') }}</button>
        <button type="button" class="btn btn-primary" @click="save(true)">{{ t('Continue') }}</button>
      </div>
      <template v-else>
        <button type="button" class="btn btn-default" @click="emit('close')">{{ t('Cancel') }}</button>
        <button type="submit" class="btn btn-primary" :form="formId" :disabled="saving">{{ saving ? t('Saving…') : t('Save') }}</button>
      </template>
    </template>
  </Modal>
</template>
