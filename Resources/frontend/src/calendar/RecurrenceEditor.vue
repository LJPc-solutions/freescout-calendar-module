<script setup lang="ts">
import { computed } from 'vue'
import { t } from '../shared/i18n'
import { WEEKDAYS, defaultRecurrence, weekdayCode, weekdayPosition } from '../shared/recurrence'
import type { Frequency, Recurrence } from '../shared/types'

/** The repeat options from the spec; every one maps onto RRULE and Microsoft Graph. */
const props = defineProps<{ modelValue: Recurrence | null; startDate: string; locale: string; errors: Record<string, string>; idPrefix: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: Recurrence | null] }>()

const rule = computed(() => props.modelValue)
// The server's refusal of the rule as a whole (the calendar can't store it) or of the zone it is read in sits here too.
const repeatError = computed(() => props.errors['recurrence.freq'] ?? props.errors.recurrence ?? props.errors.timezone ?? '')
const monthlyMode = computed(() => (rule.value?.bysetpos !== undefined ? 'weekday' : 'day'))
const endMode = computed(() => (rule.value?.until ? 'until' : rule.value?.count ? 'count' : 'never'))
const weekdayNames = computed(() => WEEKDAYS.map((code, index) => ({
  code,
  short: new Intl.DateTimeFormat(props.locale, { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1 + index))),
  long: new Intl.DateTimeFormat(props.locale, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 1 + index))),
})))
const positionLabel = computed(() => {
  const labels: Record<number, string> = { 1: t('first'), 2: t('second'), 3: t('third'), 4: t('fourth'), [-1]: t('last') }
  const day = weekdayNames.value.find((weekday) => weekday.code === weekdayCode(props.startDate))

  return t('On the :position :weekday', { position: labels[weekdayPosition(props.startDate)] ?? '', weekday: day?.long ?? '' })
})

function update(values: Partial<Recurrence>, remove: (keyof Recurrence)[] = []): void {
  if (!rule.value) {
    return
  }
  const next: Recurrence = { ...rule.value, ...values }
  for (const key of remove) {
    delete next[key]
  }
  emit('update:modelValue', next)
}

function setFrequency(value: string): void {
  emit('update:modelValue', value === '' ? null : defaultRecurrence(value as Frequency, props.startDate))
}

function toggleWeekday(code: string, checked: boolean): void {
  const days = new Set(rule.value?.byday ?? [])
  if (checked) {
    days.add(code)
  } else {
    days.delete(code)
  }
  update({ byday: WEEKDAYS.filter((day) => days.has(day)) })
}

function setMonthlyMode(mode: string): void {
  if (mode === 'weekday') {
    update({ byday: [weekdayCode(props.startDate)], bysetpos: weekdayPosition(props.startDate) }, ['bymonthday'])
  } else {
    update({ bymonthday: Number(props.startDate.slice(8, 10)) }, ['byday', 'bysetpos'])
  }
}

function setEndMode(mode: string): void {
  if (mode === 'until') {
    update({ until: props.startDate }, ['count'])
  } else if (mode === 'count') {
    update({ count: 10 }, ['until'])
  } else {
    update({}, ['until', 'count'])
  }
}
</script>

<template>
  <fieldset class="ljpc-recurrence">
    <div class="form-group" :class="{ 'has-error': repeatError }">
      <label :for="`${idPrefix}-repeat`" class="control-label">{{ t('Repeat') }}</label>
      <select :id="`${idPrefix}-repeat`" class="form-control" :value="rule?.freq ?? ''" @change="setFrequency(($event.target as HTMLSelectElement).value)">
        <option value="">{{ t('Does not repeat') }}</option>
        <option value="DAILY">{{ t('Daily') }}</option>
        <option value="WEEKLY">{{ t('Weekly') }}</option>
        <option value="MONTHLY">{{ t('Monthly') }}</option>
        <option value="YEARLY">{{ t('Yearly') }}</option>
      </select>
      <p v-if="repeatError" class="help-block">{{ repeatError }}</p>
    </div>

    <template v-if="rule">
      <div class="form-group form-inline" :class="{ 'has-error': errors['recurrence.interval'] }">
        <label :for="`${idPrefix}-interval`">{{ t('Repeat every') }}</label>
        <input
          :id="`${idPrefix}-interval`"
          type="number"
          class="form-control ljpc-input-narrow"
          min="1"
          max="999"
          :value="rule.interval"
          @change="update({ interval: Math.max(1, Number(($event.target as HTMLInputElement).value) || 1) })"
        >
        <span>{{ { DAILY: t('day(s)'), WEEKLY: t('week(s)'), MONTHLY: t('month(s)'), YEARLY: t('year(s)') }[rule.freq] }}</span>
        <p v-if="errors['recurrence.interval']" class="help-block">{{ errors['recurrence.interval'] }}</p>
      </div>

      <div v-if="rule.freq === 'WEEKLY'" class="form-group" :class="{ 'has-error': errors['recurrence.byday'] }">
        <span class="control-label ljpc-label">{{ t('Repeat on') }}</span>
        <div class="btn-group ljpc-weekdays" role="group">
          <label v-for="day in weekdayNames" :key="day.code" class="btn btn-default" :class="{ active: rule.byday?.includes(day.code) }" :title="day.long">
            <input type="checkbox" class="sr-only" :checked="rule.byday?.includes(day.code)" @change="toggleWeekday(day.code, ($event.target as HTMLInputElement).checked)">
            {{ day.short }}
          </label>
        </div>
        <p v-if="errors['recurrence.byday']" class="help-block">{{ errors['recurrence.byday'] }}</p>
      </div>

      <div v-if="rule.freq === 'MONTHLY'" class="form-group" :class="{ 'has-error': errors['recurrence.bysetpos'] || errors['recurrence.bymonthday'] }">
        <div class="radio">
          <label><input type="radio" :name="`${idPrefix}-monthly`" value="day" :checked="monthlyMode === 'day'" @change="setMonthlyMode('day')"> {{ t('On day :day', { day: Number(startDate.slice(8, 10)) }) }}</label>
        </div>
        <div class="radio">
          <label><input type="radio" :name="`${idPrefix}-monthly`" value="weekday" :checked="monthlyMode === 'weekday'" @change="setMonthlyMode('weekday')"> {{ positionLabel }}</label>
        </div>
        <p v-if="errors['recurrence.bysetpos'] || errors['recurrence.bymonthday']" class="help-block">{{ errors['recurrence.bysetpos'] ?? errors['recurrence.bymonthday'] }}</p>
      </div>

      <div class="form-group" :class="{ 'has-error': errors['recurrence.until'] || errors['recurrence.count'] }">
        <span class="control-label ljpc-label">{{ t('Ends') }}</span>
        <div class="radio">
          <label><input type="radio" :name="`${idPrefix}-ends`" value="never" :checked="endMode === 'never'" @change="setEndMode('never')"> {{ t('Never') }}</label>
        </div>
        <div class="radio form-inline">
          <label><input type="radio" :name="`${idPrefix}-ends`" value="until" :checked="endMode === 'until'" @change="setEndMode('until')"> {{ t('On date') }}</label>
          <input v-if="endMode === 'until'" type="date" class="form-control" :aria-label="t('End date of the series')" :min="startDate" :value="rule.until" @change="update({ until: ($event.target as HTMLInputElement).value })">
        </div>
        <div class="radio form-inline">
          <label><input type="radio" :name="`${idPrefix}-ends`" value="count" :checked="endMode === 'count'" @change="setEndMode('count')"> {{ t('After') }}</label>
          <template v-if="endMode === 'count'">
            <input type="number" class="form-control ljpc-input-narrow" min="1" max="999" :aria-label="t('Number of times')" :value="rule.count" @change="update({ count: Math.max(1, Number(($event.target as HTMLInputElement).value) || 1) })">
            <span>{{ t('times') }}</span>
          </template>
        </div>
        <p v-if="errors['recurrence.until'] || errors['recurrence.count']" class="help-block">{{ errors['recurrence.until'] ?? errors['recurrence.count'] }}</p>
      </div>
    </template>
  </fieldset>
</template>
