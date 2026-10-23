<script setup lang="ts">
import { t } from './i18n'
import { withAllDay, withStart, type FormState } from './form-model'

/** All-day switch plus start and end, typed in the agent's FreeScout timezone (never the browser's). */
const props = defineProps<{ state: FormState; timeZone: string; errors: Record<string, string>; idPrefix: string }>()
const emit = defineEmits<{ change: [state: FormState] }>()

function patch(values: Partial<FormState>): void {
  emit('change', { ...props.state, ...values })
}

function setStartDate(date: string): void {
  if (date) {
    emit('change', withStart(props.state, date, props.state.startTime, props.timeZone))
  }
}

function setStartTime(time: string): void {
  if (time) {
    emit('change', withStart(props.state, props.state.startDate, time, props.timeZone))
  }
}
</script>

<template>
  <div class="ljpc-time-fields">
    <div class="checkbox">
      <label>
        <input type="checkbox" :checked="state.allDay" @change="emit('change', withAllDay(state, ($event.target as HTMLInputElement).checked, timeZone))">
        {{ t('All day') }}
      </label>
    </div>
    <div class="ljpc-time-row">
      <div class="form-group" :class="{ 'has-error': errors.start }">
        <label :for="`${idPrefix}-start-date`" class="control-label">{{ t('Starts') }}</label>
        <div class="ljpc-date-time">
          <input :id="`${idPrefix}-start-date`" type="date" class="form-control" required :value="state.startDate" @change="setStartDate(($event.target as HTMLInputElement).value)">
          <input v-if="!state.allDay" type="time" class="form-control" required :aria-label="t('Start time')" :value="state.startTime" @change="setStartTime(($event.target as HTMLInputElement).value)">
        </div>
        <p v-if="errors.start" class="help-block">{{ errors.start }}</p>
      </div>
      <div class="form-group" :class="{ 'has-error': errors.end }">
        <label :for="`${idPrefix}-end-date`" class="control-label">{{ t('Ends') }}</label>
        <div class="ljpc-date-time">
          <input :id="`${idPrefix}-end-date`" type="date" class="form-control" required :min="state.startDate" :value="state.endDate" @change="patch({ endDate: ($event.target as HTMLInputElement).value })">
          <input v-if="!state.allDay" type="time" class="form-control" required :aria-label="t('End time')" :value="state.endTime" @change="patch({ endTime: ($event.target as HTMLInputElement).value })">
        </div>
        <p v-if="errors.end" class="help-block">{{ errors.end }}</p>
      </div>
    </div>
  </div>
</template>
