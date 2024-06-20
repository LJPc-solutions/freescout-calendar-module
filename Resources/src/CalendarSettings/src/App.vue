<template>
  <div class="container">
    <div class="row">
      <div class="col-sm-3">
        <Menu :calendars="calendars" @select-calendar="selectCalendar" @add-new-calendar="addNewCalendar"/>
      </div>
      <div class="col-sm-8">
        <p v-if="state === 'intro'">
          {{ ljpccalendarmoduletranslations.welcomeMessage }}
        </p>
        <NewCalendar v-if="state === 'new'" @save="fetchCalendars"/>
        <CalendarSettings v-if="state === 'edit' && activeCalendar" :calendar="activeCalendar" :key="'calendar' + activeCalendar.id" @save="fetchCalendars"/>
      </div>
    </div>
  </div>
</template>

<script setup>
import Menu from './components/Menu.vue'
import {computed, onMounted, ref} from "vue";
import NewCalendar from "@/components/NewCalendar.vue";
import CalendarSettings from "@/components/CalendarSettings.vue";

const state = ref('intro')
const calendars = ref([]);
const loading = ref(false);

const ljpccalendarmoduletranslations = window.ljpccalendarmoduletranslations

// Add a computed ref for the active calendar
const activeCalendar = computed(() => {
    if (calendars.value.length === 0) {
        return null;
    }
    return calendars.value.find((calendar) => {
        return calendar.isActive === true;
    });
});


const selectCalendar = (id) => {
    state.value = 'edit';
    calendars.value.forEach(calendar => {
        calendar.isActive = calendar.id === id;
    });
}

const addNewCalendar = () => {
    state.value = 'new';
    calendars.value.forEach(calendar => {
        calendar.isActive = false;
    });
}

const fetchCalendars = async () => {
    try {
        const response = await fetch(laroute.route('ljpccalendarmodule.api.calendars.all'), {
            method: 'GET',
            headers: {'Content-Type': 'application/json'}
        });
        calendars.value = await response.json();

        state.value = 'intro'
    } catch (error) {
        console.error(error);
    }
}

onMounted(async () => {
    await fetchCalendars();
});
</script>

<style scoped>
.container {
    margin-top: 25px;
}
</style>
