<script setup>
import {onMounted, ref, watch} from 'vue';
import draggable from 'vuedraggable';

const props = defineProps({
  calendar: Object,
});

const emit = defineEmits(['save'])

const permissions = ref({});

const name = ref('');
const color = ref('#3498db');
const calendarType = ref('normal');
const icsURL = ref('');
const syncFrequency = ref('5 minutes');
const caldavURL = ref('');
const caldavUsername = ref('');
const caldavPassword = ref('');

const loadingIndicator = ref(false);

const ljpccalendarmoduletranslations = window.ljpccalendarmoduletranslations

const customFields = ref([]);
const drag = ref(false);

const addCustomField = () => {
  customFields.value.push({
    id: Date.now(),
    name: '',
    type: 'text',
    required: false,
    options: ''
  });
};

const removeField = (index) => {
  customFields.value.splice(index, 1);
};

watch(customFields, (newValue) => {
  // Update the calendar's custom_fields when customFields change
  props.calendar.custom_fields = {
    ...props.calendar.custom_fields,
    fields: newValue
  };
}, {deep: true});

onMounted(() => {
  // Initialize customFields from calendar.custom_fields
  if (props.calendar.custom_fields && props.calendar.custom_fields.fields) {
    customFields.value = props.calendar.custom_fields.fields;
  }
});


const handleSubmit = async (event) => {
  event.preventDefault();

  // show the loading indicator
  loadingIndicator.value = true;

  const token = window.ljpccalendarmodule.csrf ?? '';

  const buildPostData = {
    _token: token,
    name: name.value,
    color: color.value,
    permissions: permissions.value,
    custom_fields: {
      ...props.calendar.custom_fields,
      fields: customFields.value
    },
  }

  if (calendarType.value === 'ics') {
    buildPostData.url = icsURL.value;
    buildPostData.refresh = syncFrequency.value;
  }

  if (calendarType.value === 'caldav') {
    buildPostData.url = caldavURL.value;
    buildPostData.username = caldavUsername.value;
    buildPostData.password = caldavPassword.value;
    buildPostData.refresh = syncFrequency.value;
  }

  try {
    const response = await fetch(laroute.route('ljpccalendarmodule.api.calendar.update', {id: props.calendar.id}) + '?_method=PUT', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(buildPostData),
    });

    if (response.status === 200) {
      showFloatingAlert('success', ljpccalendarmoduletranslations.calendarHasBeenSaved);
      emit('save');
    } else {
      showFloatingAlert('error', ljpccalendarmoduletranslations.errorSavingCalendar);
      console.error(data);
    }
  } catch (error) {
    showFloatingAlert('error', ljpccalendarmoduletranslations.errorSavingCalendar);
    console.error(error);
  } finally {
    // close the loading indicator
    loadingIndicator.value = false;
  }
};

const deleteCalendar = async () => {
  if (!confirm(ljpccalendarmoduletranslations.confirmDeleteCalendar)) {
    return;
  }

  try {
    const response = await fetch(laroute.route('ljpccalendarmodule.api.calendar.delete', {id: props.calendar.id}) + '?_method=DELETE', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      }, body: JSON.stringify({_token: window.ljpccalendarmodule.csrf ?? ''})
    });

    if (response.status === 200) {
      showFloatingAlert('success', ljpccalendarmoduletranslations.calendarHasBeenDeleted);
      emit('save');
    } else {
      showFloatingAlert('error', ljpccalendarmoduletranslations.errorDeletingCalendar);
      console.error(data);
    }
  } catch (error) {
    showFloatingAlert('error', ljpccalendarmoduletranslations.errorDeletingCalendar);
    console.error(error);
  } finally {
    // close the loading indicator
    loadingIndicator.value = false;
  }
}

onMounted(() => {
  name.value = props.calendar.name;
  color.value = props.calendar.color;
  calendarType.value = props.calendar.type;
  if (props.calendar.custom_fields) {
    icsURL.value = props.calendar.custom_fields.url;
    syncFrequency.value = props.calendar.custom_fields.refresh;
  }
  if (props.calendar.type === 'caldav' && props.calendar.custom_fields) {
    caldavURL.value = props.calendar.custom_fields.url;
    caldavUsername.value = props.calendar.custom_fields.username;
    caldavPassword.value = props.calendar.custom_fields.password;
  }

  permissions.value = props.calendar.permissions;

  fetch(laroute.route('ljpccalendarmodule.api.users'))
      .then(response => response.json())
      .then((data) => {
        data['results'].forEach(user => {
          if (!permissions.value.hasOwnProperty(user.id)) {
            permissions.value[user.id] = {
              userTeam: user.text,
              showInDashboard: true,
              showInCalendar: true,
              createItems: true,
              editItems: true,
            };
          } else {
            permissions.value[user.id].userTeam = user.text;
          }
        });
      })
      .catch(error => console.error(error));
});

</script>

<template>
  <div>
    <h1>{{ name }}</h1>
    <form @submit="handleSubmit">
      <div class="form-group">
        <label for="calendarName"><strong>{{ ljpccalendarmoduletranslations.calendarName }}:</strong></label>
        <input type="text" class="form-control" id="calendarName" :placeholder="ljpccalendarmoduletranslations.enterCalendarName" v-model="name">
      </div>

      <div class="form-group">
        <label for="colorPicker"><strong>{{ ljpccalendarmoduletranslations.calendarColor }}:</strong></label>
        <input type="color" class="form-control" id="colorPicker" placeholder="#3498db" v-model="color">
        <div class="swatches">
          <button type="button" class="btn btn-sm swatch" @click="color='#3498db'" style="background-color: #3498db;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#e74c3c'" style="background-color: #e74c3c;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#e67e22'" style="background-color: #e67e22;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#1abc9c'" style="background-color: #1abc9c;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#9b59b6'" style="background-color: #9b59b6;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#2ecc71'" style="background-color: #2ecc71;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#f1c40f'" style="background-color: #f1c40f;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#833471'" style="background-color: #833471;"></button>
          <button type="button" class="btn btn-sm swatch" @click="color='#9980FA'" style="background-color: #9980FA;"></button>
        </div>
      </div>

      <hr>
      <div class="form-group">
        <label><strong>{{ ljpccalendarmoduletranslations.calendarType }}:</strong></label>
        <div>
          {{ calendarType === 'normal' ? ljpccalendarmoduletranslations.normal : calendarType === 'ics' ? ljpccalendarmoduletranslations.ics : ljpccalendarmoduletranslations.caldav }}
        </div>
      </div>
      <template v-if="calendarType === 'ics'">
        <hr>
        <div class="form-group">
          <label for="icsUrl"><strong>{{ ljpccalendarmoduletranslations.icsUrl }}:</strong></label>
          <input type="text" class="form-control" id="icsUrl" :placeholder="ljpccalendarmoduletranslations.enterIcsUrl" v-model="icsURL">
        </div>

        <div class="form-group">
          <label for="syncFrequency"><strong>{{ ljpccalendarmoduletranslations.syncFrequency }}:</strong></label>
          <select class="form-control" id="syncFrequency" v-model="syncFrequency">
            <option value="1 minute">{{ ljpccalendarmoduletranslations.everyMinute }}</option>
            <option value="5 minutes">{{ ljpccalendarmoduletranslations.every5Minutes }}</option>
            <option value="15 minutes">{{ ljpccalendarmoduletranslations.every15Minutes }}</option>
            <option value="30 minutes">{{ ljpccalendarmoduletranslations.every30Minutes }}</option>
            <option value="1 hour">{{ ljpccalendarmoduletranslations.everyHour }}</option>
            <option value="2 hours">{{ ljpccalendarmoduletranslations.every2Hours }}</option>
            <option value="6 hours">{{ ljpccalendarmoduletranslations.every6Hours }}</option>
            <option value="12 hours">{{ ljpccalendarmoduletranslations.every12Hours }}</option>
            <option value="daily">{{ ljpccalendarmoduletranslations.everyDay }}</option>
          </select>
        </div>
      </template>

      <template v-if="calendarType === 'caldav'">
        <hr>
        <div class="form-group">
          <label for="caldavUrl"><strong>{{ ljpccalendarmoduletranslations.caldavUrl }}:</strong></label>
          <input type="text" class="form-control" id="caldavUrl" v-model="caldavURL" :placeholder="ljpccalendarmoduletranslations.enterCaldavUrl">
        </div>
        <div class="form-group">
          <label for="caldavUsername"><strong>{{ ljpccalendarmoduletranslations.caldavUsername }}:</strong></label>
          <input type="text" class="form-control" id="caldavUsername" v-model="caldavUsername" :placeholder="ljpccalendarmoduletranslations.enterCaldavUsername">
        </div>
        <div class="form-group">
          <label for="caldavPassword"><strong>{{ ljpccalendarmoduletranslations.caldavPassword }}:</strong></label>
          <input type="password" class="form-control" id="caldavPassword" v-model="caldavPassword" :placeholder="ljpccalendarmoduletranslations.enterCaldavPassword">
        </div>
        <div class="form-group">
          <label for="syncFrequency"><strong>{{ ljpccalendarmoduletranslations.syncFrequency }}:</strong></label>
          <select class="form-control" id="syncFrequency" v-model="syncFrequency">
            <option value="1 minute">{{ ljpccalendarmoduletranslations.everyMinute }}</option>
            <option value="5 minutes">{{ ljpccalendarmoduletranslations.every5Minutes }}</option>
            <option value="15 minutes">{{ ljpccalendarmoduletranslations.every15Minutes }}</option>
            <option value="30 minutes">{{ ljpccalendarmoduletranslations.every30Minutes }}</option>
            <option value="1 hour">{{ ljpccalendarmoduletranslations.everyHour }}</option>
            <option value="2 hours">{{ ljpccalendarmoduletranslations.every2Hours }}</option>
            <option value="6 hours">{{ ljpccalendarmoduletranslations.every6Hours }}</option>
            <option value="12 hours">{{ ljpccalendarmoduletranslations.every12Hours }}</option>
            <option value="daily">{{ ljpccalendarmoduletranslations.everyDay }}</option>
          </select>
        </div>
      </template>

      <template v-if="calendarType !== 'ics'">
        <hr>
        <div class="form-group">
          <label><strong>{{ ljpccalendarmoduletranslations.customFields }}:</strong></label>
          <draggable v-model="customFields" group="customFields" item-key="id" handle=".drag-handle">
            <template #item="{ element, index }">
              <div class="custom-field">
                <div class="field-header">
                  <span class="drag-handle">&#9776;</span> <!-- Drag handle icon -->
                  <input v-model="element.name" placeholder="Field Name" class="form-control">
                  <select v-model="element.type" class="form-control">
                    <option value="text">{{ ljpccalendarmoduletranslations.text }}</option>
                    <option value="number">{{ ljpccalendarmoduletranslations.number }}</option>
                    <option value="dropdown">{{ ljpccalendarmoduletranslations.dropdown }}</option>
                    <option value="boolean">{{ ljpccalendarmoduletranslations.boolean }}</option>
                    <option value="multiselect">{{ ljpccalendarmoduletranslations.multiselect }}</option>
                    <option value="date">{{ ljpccalendarmoduletranslations.date }}</option>
                    <option value="email">{{ ljpccalendarmoduletranslations.email }}</option>
                    <option value="source">{{ ljpccalendarmoduletranslations.source }}</option>
                  </select>
                  <input type="checkbox" v-model="element.required" :id="'required-' + element.id">
                  <label :for="'required-' + element.id">{{ ljpccalendarmoduletranslations.required }}</label>
                  <button @click="removeField(index)" class="btn btn-danger btn-sm">{{ ljpccalendarmoduletranslations.remove }}</button>
                </div>
                <div v-if="element.type === 'dropdown' || element.type === 'multiselect'" class="field-options">
                  <input v-model="element.options" placeholder="Option1,Option2,Option3" class="form-control">
                </div>
              </div>
            </template>
          </draggable>
          <button @click="addCustomField" class="btn btn-primary mt-2">{{ ljpccalendarmoduletranslations.addCustomField }}</button>
        </div>
      </template>


      <hr>
      <div class="form-group">
        <label><strong>{{ ljpccalendarmoduletranslations.permissions }}:</strong></label>
        <table class="table">
          <thead>
          <tr>
            <th>{{ ljpccalendarmoduletranslations.userTeam }}</th>
            <th>{{ ljpccalendarmoduletranslations.showInDashboardWidget }}</th>
            <th>{{ ljpccalendarmoduletranslations.showInCalendar }}</th>
            <th v-if="calendarType !== 'ics'">{{ ljpccalendarmoduletranslations.createEvents }}</th>
            <th v-if="calendarType !== 'ics'">{{ ljpccalendarmoduletranslations.editEvents }}</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="(permission, index) in permissions" :key="index">
            <td>{{ permission.userTeam }}</td>
            <td><input type="checkbox" v-model="permission.showInDashboard"></td>
            <td><input type="checkbox" v-model="permission.showInCalendar"></td>
            <td v-if="calendarType !== 'ics'"><input type="checkbox" v-model="permission.createItems"></td>
            <td v-if="calendarType !== 'ics'"><input type="checkbox" v-model="permission.editItems"></td>
          </tr>
          </tbody>
        </table>
      </div>


      <hr>
      <div class="form-group">
        <label><strong>{{ ljpccalendarmoduletranslations.export }}:</strong></label>
      </div>
      <div class="form-group">
        {{ ljpccalendarmoduletranslations.allDetails }}: <a :href="props.calendar.ics_url">{{ props.calendar.ics_url }}</a>
      </div>
      <div class="form-group">
        {{ ljpccalendarmoduletranslations.onlyFreeBusy }}: <a :href="props.calendar.obfuscated_ics_url">{{ props.calendar.obfuscated_ics_url }}</a>
      </div>

      <div style="display: flex; gap:5px;">
        <button type="submit" v-if="!loadingIndicator" class="btn btn-primary">{{ ljpccalendarmoduletranslations.save }}</button>
        <span v-else>{{ ljpccalendarmoduletranslations.saving }}</span>
        <button type="button" @click="deleteCalendar" class="btn btn-danger">{{ ljpccalendarmoduletranslations.deleteCalendar }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
h1 {
  margin-top: 0;
  padding-top: 0;
}

.form-check {
  position: relative;
  display: block;
  padding-left: 1.25rem;
  margin-bottom: 5px;
}

.form-check-input {
  position: absolute;
  margin-top: .3rem;
  margin-left: -1.25rem;
}

.form-check-label {
  margin-bottom: 0;
  margin-left: 5px;
}

small {
  display: block;
}

.swatches {
  margin-top: 5px;
}

.swatch {
  margin-right: 2px;
  width: 20px;
  height: 20px;
}

.custom-field {
  border: 1px solid #ccc;
  padding: 10px;
  margin-bottom: 10px;
  background-color: #f9f9f9;
}

.field-header {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 10px;
}

.field-options {
  margin-top: 10px;
}

.drag-handle {
  cursor: move;
  padding: 5px;
  font-size: 20px;
  color: #888;
}

.drag-handle:hover {
  color: #333;
}
</style>
