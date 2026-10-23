<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import FullCalendar from '@fullcalendar/vue3'
import type { CalendarOptions, DateSelectInfo, EventClickInfo, EventDropInfo, EventInput, EventResizeDoneInfo, EventSourceFuncInfo, LocaleInput } from '@fullcalendar/vue3'
import classicTheme from '@fullcalendar/vue3/themes/classic'
import dayGrid from '@fullcalendar/vue3/daygrid'
import timeGrid from '@fullcalendar/vue3/timegrid'
import list from '@fullcalendar/vue3/list'
import multiMonth from '@fullcalendar/vue3/multimonth'
import interaction from '@fullcalendar/vue3/interaction'
import { ApiError, asksToDropExceptions, createApi, routeWith } from '../shared/api'
import { notify } from '../shared/alerts'
import { calendarTimeText } from '../shared/format'
import { copyOfOccurrence, copyOfSeries, emptyForm, formFromEvent, formFromOccurrence, formFromRange, formFromSeries, withCalendar, type FormState, type Scope } from '../shared/form-model'
import { t } from '../shared/i18n'
import { readStored, writeStored } from '../shared/storage'
import { dateOf, nextFullHour } from '../shared/time'
import type { CalendarInfo, EventDetail, Occurrence } from '../shared/types'
import Modal from '../shared/Modal.vue'
import CalendarSidebar from './CalendarSidebar.vue'
import { syncSummary, type SyncResult } from './sync'
import EventDetails from './EventDetails.vue'
import EventForm from './EventForm.vue'
import ScopeDialog from './ScopeDialog.vue'
import { pageBanner } from './banners'
import { compareEvents, dragPatch, overlapsRange, seriesDragPatch, toEventInput, type EventProps } from './events'
import { eventRef, linkRef, refFromHash } from './permalink'
import type { PageConfig } from './page-config'
import { VIEWS, isIdList, isView } from './preferences'

const props = defineProps<{ config: PageConfig; locale: LocaleInput | null }>()
const api = createApi(props.config.csrf)
const tz = props.config.timezone

const STORAGE = { hidden: 'ljpc-calendar.hidden', view: 'ljpc-calendar.view', lastCalendar: 'ljpc-calendar.last-calendar' }
const mobile = window.matchMedia('(max-width: 767px)').matches

const calendars = ref<CalendarInfo[]>([])
const loadState = ref<'loading' | 'ready' | 'failed'>('loading')
const hidden = ref<number[]>(readStored<number[]>(STORAGE.hidden, [], isIdList))
const busy = ref(false)
const fetching = ref(false)

type Details = { reference: string; recurrenceId: string | null }
type Form = { mode: 'create' | 'series' | 'occurrence'; initial: FormState; event: EventDetail | null; recurrenceId: string | null }
const details = ref<Details | null>(null)
const form = shallowRef<Form | null>(null)
const scopeQuestion = ref<{ question: string; allowSeries: boolean; resolve: (scope: Scope | null) => void } | null>(null)
const confirmQuestion = ref<{ question: string; resolve: (confirmed: boolean) => void } | null>(null)
const calendarRef = ref<InstanceType<typeof FullCalendar> | null>(null)

const byId = computed(() => new Map(calendars.value.map((calendar) => [calendar.id, calendar])))
const syncing = ref(false)
const creatable = computed(() => calendars.value.filter((calendar) => calendar.writable && calendar.rights.can_create))
// The administrator's problems can be closed for this page; the agent's notice stays while it is true.
const dismissed = ref(false)
const banner = computed(() => pageBanner(props.config.user.is_admin, props.config.health, dismissed.value, calendars.value, hidden.value, Date.now()))

// The last response is kept per range: toggling a calendar re-filters without a request, and a failed request
// keeps the events already shown (2.7.x behavior) instead of blanking the calendar.
let visibleRange = { start: '', end: '' }
let cachedRange = ''
let cachedRows: Occurrence[] = []

async function fetchEvents(info: EventSourceFuncInfo): Promise<EventInput[]> {
  const range = `${info.startStr}|${info.endStr}`
  if (range !== cachedRange) {
    try {
      cachedRows = await api.get<Occurrence[]>(props.config.routes.events, { start: info.startStr, end: info.endStr })
      cachedRange = range
    } catch (caught) {
      notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
    }
  }

  return cachedRows
    .filter((row) => !hidden.value.includes(row.calendar_id))
    .map((row) => toEventInput(row, byId.value.get(row.calendar_id)))
}

function refresh(): void {
  cachedRange = ''
  calendarRef.value?.getApi().refetchEvents()
}

/** The series itself, with its own first start and rule, also when the agent works from one of its occurrences. */
function fetchMaster(eventId: number): Promise<EventDetail> {
  return api.get<EventDetail>(routeWith(props.config.routes.event, eventRef(eventId)))
}

function askScope(question: string, allowSeries = true): Promise<Scope | null> {
  return new Promise((resolve) => {
    scopeQuestion.value = { question, allowSeries, resolve }
  })
}

function chooseScope(scope: Scope | null): void {
  scopeQuestion.value?.resolve(scope)
  scopeQuestion.value = null
}

function askConfirm(question: string): Promise<boolean> {
  return new Promise((resolve) => {
    confirmQuestion.value = { question, resolve }
  })
}

function answerConfirm(confirmed: boolean): void {
  confirmQuestion.value?.resolve(confirmed)
  confirmQuestion.value = null
}

/**
 * Saves a whole-series change. When it would reset edited and deleted occurrences, the server saves nothing and asks
 * (409 "drops_exceptions"); the agent confirms, and the same change goes again with drop_exceptions. False on Cancel.
 */
async function patchSeries(url: string, patch: Record<string, unknown>): Promise<boolean> {
  try {
    await api.patch(url, patch)
  } catch (caught) {
    if (!asksToDropExceptions(caught)) {
      throw caught
    }
    if (!(await askConfirm(caught.message))) {
      return false
    }
    await api.patch(url, { ...patch, drop_exceptions: true })
  }

  return true
}

async function onChange(info: EventDropInfo | EventResizeDoneInfo): Promise<void> {
  const item = info.event.extendedProps as EventProps
  let scope: Scope = 'series'
  let master: EventDetail | null = null
  if (item.isRecurring) {
    // Only another provider can refuse a series' rule; its series is read first, so the question offers what is possible.
    if (byId.value.get(item.calendarId)?.provider !== 'local') {
      try {
        master = await fetchMaster(item.eventId)
      } catch (caught) {
        info.revert()
        notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))

        return
      }
    }
    const seriesAllowed = master?.series_writable ?? true
    const choice = await askScope(seriesAllowed ? t('Move only this occurrence, or the whole series?') : t('This series can only be changed one occurrence at a time.'), seriesAllowed)
    if (!choice) {
      info.revert()

      return
    }
    scope = choice
  }
  busy.value = true
  try {
    const url = routeWith(props.config.routes.event, item.eventId)
    if (scope === 'occurrence' || !item.isRecurring) {
      await api.patch(url, dragPatch(scope, item.recurrenceId, { all_day: info.event.allDay, start: info.event.startStr, end: info.event.endStr }))
    } else {
      // A series moves by the same amount from its own first start.
      master = master ?? await fetchMaster(item.eventId)
      const startDelta = 'delta' in info ? info.delta : info.startDelta
      const endDelta = 'delta' in info ? info.delta : info.endDelta
      if (!(await patchSeries(url, seriesDragPatch(master, startDelta, endDelta, tz)))) {
        info.revert()

        return
      }
    }
    refresh()
  } catch (caught) {
    info.revert()
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  } finally {
    busy.value = false
  }
}

function defaultCalendarId(): number | null {
  const last = readStored<number | null>(STORAGE.lastCalendar, null)

  return creatable.value.find((calendar) => calendar.id === last)?.id ?? creatable.value[0]?.id ?? null
}

function openCreate(state?: FormState): void {
  const calendarId = state?.calendarId ?? defaultCalendarId()
  const fields = byId.value.get(calendarId ?? 0)?.fields ?? []
  form.value = { mode: 'create', initial: state ?? emptyForm(calendarId, nextFullHour(tz), tz, fields), event: null, recurrenceId: null }
}

function onSelect(info: DateSelectInfo): void {
  calendarRef.value?.getApi().unselect()
  const calendarId = defaultCalendarId()
  openCreate(formFromRange(calendarId, info.startStr, info.endStr, tz, byId.value.get(calendarId ?? 0)?.fields ?? []))
}

function onEventClick(info: EventClickInfo): void {
  info.jsEvent.preventDefault()
  const item = info.event.extendedProps as EventProps
  openDetails(eventRef(item.eventId), item.isRecurring ? item.recurrenceId : null)
}

function openDetails(reference: string, recurrenceId: string | null): void {
  form.value = null
  details.value = { reference, recurrenceId }
}

/** The address bar shows the permalink of what is open: the occurrence of a repeating event, or the event. */
function onDetailsLoaded(event: EventDetail): void {
  const hash = `#${linkRef(event.id, event.is_recurring ? details.value?.recurrenceId ?? null : null)}`
  if (window.location.hash !== hash) {
    history.replaceState(null, '', hash)
  }
  showEvent(event)
}

function onDetailsStale(): void {
  notify('warning', t('This occurrence no longer exists, so the event itself is shown.'))
  openDetails(details.value?.reference ?? '', null)
}

/** Jumps to an event the visible range doesn't show at all, so an opened or created event is on screen. */
function showEvent(event: EventDetail): void {
  if (!overlapsRange(event, visibleRange, tz)) {
    calendarRef.value?.getApi().gotoDate(dateOf(event.start, tz))
  }
}

/** Closes the details and drops their permalink, also when they give way to the form. */
function closeDetails(): void {
  details.value = null
  history.replaceState(null, '', window.location.pathname + window.location.search)
}

async function onEdit(event: EventDetail, scope: Scope): Promise<void> {
  const recurrenceId = details.value?.recurrenceId ?? null
  closeDetails()
  const calendar = byId.value.get(event.calendar_id)
  const fields = calendar?.fields ?? []
  if (scope === 'occurrence') {
    form.value = { mode: 'occurrence', initial: formFromOccurrence(event, tz, fields, calendar?.has_title_template ?? false), event, recurrenceId }

    return
  }
  // The details of an occurrence show its own times; the series form needs the series' first start.
  try {
    const master = recurrenceId ? await fetchMaster(event.id) : event
    form.value = { mode: 'series', initial: formFromSeries(master, tz, fields), event: master, recurrenceId: null }
  } catch (caught) {
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  }
}

async function onDuplicate(event: EventDetail): Promise<void> {
  const reference = details.value?.reference ?? eventRef(event.id)
  const recurrenceId = details.value?.recurrenceId ?? null
  closeDetails()
  const calendar = byId.value.get(event.calendar_id)
  const fields = calendar?.fields ?? []
  // A series opened as a whole copies itself, in its own zone.
  let state = event.is_recurring ? formFromSeries(event, tz, fields) : formFromEvent(event, tz, fields)
  // An occurrence of a repeating event copies either itself, or the whole series from the occurrence's original slot.
  // A new event can't repeat by a custom rule, so an occurrence of such a series copies only itself, without asking.
  if (event.is_recurring && recurrenceId) {
    const scope = event.recurrence_custom ? 'occurrence' : await askScope(t('Duplicate only this occurrence or the whole series?'))
    if (!scope) {
      openDetails(reference, recurrenceId)

      return
    }
    if (scope === 'occurrence') {
      state = copyOfOccurrence(event, tz, fields, calendar?.has_title_template ?? false)
    } else {
      let master: EventDetail
      try {
        master = await fetchMaster(event.id)
      } catch (caught) {
        notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))

        return
      }
      state = copyOfSeries(master, recurrenceId, tz, fields)
    }
  }
  // A copy of an event in a calendar the agent can't add to goes to their default calendar, with its fields.
  const writable = creatable.value.some((calendar) => calendar.id === event.calendar_id)
  const calendarId = writable ? event.calendar_id : defaultCalendarId()
  openCreate(withCalendar(state, calendarId, byId.value.get(calendarId ?? 0)?.fields ?? []))
}

function onSaved(event: EventDetail): void {
  const created = form.value?.mode === 'create'
  writeStored(STORAGE.lastCalendar, event.calendar_id)
  form.value = null
  // A move the previous calendar didn't complete is saved, but the agent must know the event is in both calendars.
  if (event.warning) {
    notify('warning', event.warning)
  } else {
    notify('success', t('Event saved.'))
  }
  refresh()
  // A new event may be on a date that isn't shown; an edited one stays where the agent was looking.
  if (created) {
    showEvent(event)
  }
}

function onDeleted(): void {
  closeDetails()
  refresh()
}

function toggleCalendar(id: number): void {
  hidden.value = hidden.value.includes(id) ? hidden.value.filter((item) => item !== id) : [...hidden.value, id]
  writeStored(STORAGE.hidden, hidden.value)
  calendarRef.value?.getApi().refetchEvents()
}

/** A permalink opens only on a loaded calendar, never during the upgrade or after the calendars failed to load. */
function openFromHash(): void {
  const link = refFromHash(window.location.hash)
  if (loadState.value === 'ready' && link && (details.value?.reference !== link.reference || details.value?.recurrenceId !== link.recurrenceId)) {
    openDetails(link.reference, link.recurrenceId)
  }
}

const views = VIEWS.join(',')
const hour12 = props.config.hour12
const options: CalendarOptions = {
  plugins: [classicTheme, dayGrid, timeGrid, list, multiMonth, interaction],
  timeZone: tz,
  ...(props.locale ? { locale: props.locale } : {}),
  direction: props.config.rtl ? 'rtl' : 'ltr',
  firstDay: 1,
  initialView: mobile ? 'listWeek' : readStored<string>(STORAGE.view, 'timeGridWeek', isView),
  headerToolbar: mobile ? { start: 'prev,next', center: 'title', end: 'today' } : { start: 'prev,next today', center: 'title', end: views },
  ...(mobile ? { footerToolbar: { center: 'timeGridDay,listWeek,dayGridMonth' } } : {}),
  height: mobile ? 'auto' : '100%',
  nowIndicator: true,
  navLinks: true,
  scrollTime: '08:00:00',
  dayMaxEvents: true,
  // The all-day row above the time grid shows every event (2.7.x); only month cells fold into "+N more".
  views: { timeGrid: { dayMaxEvents: false } },
  selectable: true,
  selectMirror: true,
  forceEventDuration: true,
  // Times as FreeScout core shows them ("02:30pm" or "14:30"). FullCalendar hands these functions the wall clock in
  // its own time zone, the agent's, never the browser's.
  eventTimeFormat: (info) => calendarTimeText(info, hour12),
  slotHeaderFormat: (info) => calendarTimeText(info, hour12),
  toolbarTitleClass: 'ljpc-toolbar-title',
  // FullCalendar 7.1.1 types eventOrder as its parsed form; the runtime takes comparators and field names.
  eventOrder: [compareEvents, '-duration', 'title'] as unknown as CalendarOptions['eventOrder'],
  events: fetchEvents,
  loading: (isLoading) => {
    fetching.value = isLoading
  },
  eventDidMount: (info) => info.el.setAttribute('title', info.event.title),
  eventClass: (info) => (['cancelled', 'declined'].includes(info.event.extendedProps.status) ? 'ljpc-event-cancelled' : ''),
  popoverClass: 'ljpc-popover',
  // A repeating event changes between all-day and timed in the form, where the whole rule is visible.
  eventAllow: (span, moving) => !(moving?.extendedProps.isRecurring && span.allDay !== moving.allDay),
  selectAllow: () => creatable.value.length > 0,
  select: onSelect,
  eventClick: onEventClick,
  eventDrop: (info) => void onChange(info),
  eventResize: (info) => void onChange(info),
  datesSet: (info) => {
    visibleRange = { start: dateOf(info.startStr, tz), end: dateOf(info.endStr, tz) }
    if (!mobile) {
      writeStored(STORAGE.view, info.view.type)
    }
  },
}

onMounted(async () => {
  window.addEventListener('hashchange', openFromHash)
  if (!props.config.migration_complete) {
    return
  }
  try {
    calendars.value = await api.get<CalendarInfo[]>(props.config.routes.calendars)
    loadState.value = 'ready'
    openFromHash()
  } catch (caught) {
    loadState.value = 'failed'
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  }
})

onBeforeUnmount(() => window.removeEventListener('hashchange', openFromHash))

/** "Sync now": the server syncs every external calendar the agent can view, then the page shows what it has now. */
async function syncNow(): Promise<void> {
  syncing.value = true
  try {
    const answer = await api.post<{ calendars: SyncResult[] }>(props.config.routes.sync, {})
    const summary = syncSummary(answer.calendars)
    if (summary) {
      notify(summary.type, summary.text)
    }
    calendars.value = await api.get<CalendarInfo[]>(props.config.routes.calendars)
    calendarRef.value?.getApi().refetchEvents()
  } catch (caught) {
    notify('error', caught instanceof ApiError ? caught.message : t('Something went wrong. Try again.'))
  } finally {
    syncing.value = false
  }
}

function reload(): void {
  window.location.reload()
}
</script>

<template>
  <div class="ljpc-page" :class="{ 'is-busy': busy, 'is-mobile': mobile }">
    <CalendarSidebar
      :calendars="loadState === 'ready' && config.migration_complete ? calendars : []"
      :hidden="hidden"
      :can-create="config.migration_complete && creatable.length > 0"
      :settings-url="config.routes.settings"
      :syncing="syncing"
      @toggle="toggleCalendar"
      @new-event="openCreate()"
      @sync="syncNow"
    />
    <div v-if="!config.migration_complete" class="ljpc-state">
      <h2>{{ t('The calendar is being upgraded') }}</h2>
      <p>{{ t('The calendar opens again as soon as the upgrade to version 3 has finished.') }}</p>
      <p v-if="config.routes.settings"><a :href="config.routes.settings">{{ t('Open the upgrade report') }}</a></p>
    </div>
    <div v-else-if="loadState === 'loading'" class="ljpc-state" role="status">{{ t('Loading…') }}</div>
    <div v-else-if="loadState === 'failed'" class="ljpc-state">
      <p>{{ t('The calendars could not be loaded.') }}</p>
      <button type="button" class="btn btn-default" @click="reload">{{ t('Try again') }}</button>
    </div>
    <div v-else-if="calendars.length === 0" class="ljpc-state">
      <h2>{{ t('No calendars yet') }}</h2>
      <p v-if="config.routes.settings">{{ t('Create a calendar and choose who can use it.') }} <a :href="config.routes.settings">{{ t('Manage calendars') }}</a></p>
      <p v-else>{{ t('An administrator can give you access to a calendar.') }}</p>
    </div>
    <template v-else>
      <main class="ljpc-main">
        <div v-if="banner?.kind === 'health'" class="alert alert-warning alert-dismissible ljpc-banner ljpc-health" role="status">
          <button type="button" class="close" :aria-label="t('Close')" @click="dismissed = true"><span aria-hidden="true">&times;</span></button>
          <p><strong>{{ t('These calendar problems need an administrator:') }}</strong></p>
          <ul>
            <li v-for="problem in banner.problems" :key="problem.key"><a :href="problem.url">{{ problem.text }}</a></li>
          </ul>
        </div>
        <div v-else-if="banner?.kind === 'stale'" class="alert alert-warning ljpc-banner" role="status">
          {{ t('Some calendars are not up to date: :names.', { names: banner.names.join(', ') }) }}
        </div>
        <div class="ljpc-grid" :class="{ 'is-loading': fetching }" :aria-busy="fetching">
          <FullCalendar ref="calendarRef" :options="options" />
        </div>
      </main>
    </template>

    <EventDetails
      v-if="details"
      :key="`${details.reference}|${details.recurrenceId}`"
      :reference="details.reference"
      :recurrence-id="details.recurrenceId"
      :calendars="calendars"
      :config="config"
      :api="api"
      :can-duplicate="creatable.length > 0"
      @close="closeDetails"
      @loaded="onDetailsLoaded"
      @stale="onDetailsStale"
      @edit="onEdit"
      @duplicate="onDuplicate"
      @deleted="onDeleted"
    />
    <EventForm
      v-if="form"
      :mode="form.mode"
      :initial="form.initial"
      :event="form.event"
      :recurrence-id="form.recurrenceId"
      :calendars="calendars"
      :config="config"
      :api="api"
      @close="form = null"
      @saved="onSaved"
    />
    <ScopeDialog v-if="scopeQuestion" :question="scopeQuestion.question" :allow-series="scopeQuestion.allowSeries" @choose="chooseScope" />
    <Modal v-if="confirmQuestion" :title="t('Repeating event')" size="sm" @close="answerConfirm(false)">
      <p>{{ confirmQuestion.question }}</p>
      <template #footer>
        <button type="button" class="btn btn-default" @click="answerConfirm(false)">{{ t('Cancel') }}</button>
        <button type="button" class="btn btn-primary" @click="answerConfirm(true)">{{ t('Continue') }}</button>
      </template>
    </Modal>
  </div>
</template>
