<template>
  <div class="menu">
    <ul class="list-group">
      <!-- Use v-for to loop through calendars -->
      <li v-for="calendar in calendars" class="list-group-item" role="button" :key="calendar.id" @click="selectCalendar(calendar.id)" :class="{ 'active': calendar.isActive }">
        <span class="badge badge-pill bg-dark text-white" v-if="calendar.color" :style="{background: calendar.color}">&nbsp;</span>
        {{ calendar.name }}
      </li>
      <!-- Use v-if to conditionally render the New Calendar button -->
      <li class="list-group-item list-group-item-info" role="button" @click="addNewCalendar">
        {{ ljpccalendarmoduletranslations.newCalendar }}
      </li>
    </ul>
  </div>
</template>
<script setup>
import {computed, onMounted, ref} from 'vue';
const ljpccalendarmoduletranslations = window.ljpccalendarmoduletranslations

const props = defineProps({
    calendars: Array,
});

const emit = defineEmits(['select-calendar', 'add-new-calendar']);

const selectCalendar = (id) => {
    emit('select-calendar', id);
};

// Define a method to add a new calendar
const addNewCalendar = () => {
    emit('add-new-calendar');
}

</script>

<style scoped>
.menu {
    width: 100%;
    max-width: 300px;
}

.list-group-item:not(.active):hover {
    background-color: #f8f9fa; /* Change this to the color you want */
}
</style>
