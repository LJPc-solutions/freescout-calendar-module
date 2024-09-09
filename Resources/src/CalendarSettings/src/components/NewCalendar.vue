<script setup>
import {onMounted, ref} from "vue";
import draggable from 'vuedraggable';

const permissions = ref({});
const emit = defineEmits(['save'])

const name = ref('');
const color = ref('#3498db');
const calendarType = ref('normal');
const icsURL = ref('');
const syncFrequency = ref('5 minutes');
const caldavUrl = ref('');
const caldavUsername = ref('');
const caldavPassword = ref('');

const ljpccalendarmoduletranslations = window.ljpccalendarmoduletranslations


const loadingIndicator = ref(false);

const customFields = ref([]);

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

const handleSubmit = async (event) => {
  event.preventDefault();

  // show the loading indicator
  loadingIndicator.value = true;

  const token = window.ljpccalendarmodule.csrf ?? '';

  const buildPostData = {
    _token: token,
    name: name.value,
    color: color.value,
    type: calendarType.value,
    custom_fields: {
      fields: customFields.value
    },
    permissions: permissions.value,
  }

  if (calendarType.value === 'ics') {
    buildPostData.url = icsURL.value;
    buildPostData.refresh = syncFrequency.value;
  }

  if (calendarType.value === 'caldav') {
    buildPostData.url = caldavUrl.value;
    buildPostData.username = caldavUsername.value;
    buildPostData.password = caldavPassword.value;
    buildPostData.refresh = syncFrequency.value;
  }

  try {
    const response = await fetch(laroute.route('ljpccalendarmodule.api.calendar.new'), {
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

onMounted(() => {
  fetch(laroute.route('ljpccalendarmodule.api.users'))
      .then(response => response.json())
      .then((data) => {
        data['results'].forEach(user => {
          if (user && user.id) {
            permissions.value[user.id] = {
              userTeam: user.text,
              showInDashboard: true,
              showInCalendar: true,
              createItems: true,
              editItems: true,
            };
          }
        });

      })
      .catch(error => console.error(error));
});

</script>

<template>
  <div>
    <h1>{{ ljpccalendarmoduletranslations.newCalendar }}</h1>
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
        <div class="form-check">
          <input class="form-check-input" type="radio" v-model="calendarType" id="normal" value="normal">
          <label class="form-check-label" for="normal">
            {{ ljpccalendarmoduletranslations.normal }}
            <small>{{ ljpccalendarmoduletranslations.normalDescription }}</small>
          </label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" v-model="calendarType" id="ics" value="ics">
          <label class="form-check-label" for="ics">
            {{ ljpccalendarmoduletranslations.ics }}
            <small>{{ ljpccalendarmoduletranslations.icsDescription }}</small>
          </label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" v-model="calendarType" id="caldav" value="caldav">
          <label class="form-check-label" for="caldav">
            {{ ljpccalendarmoduletranslations.caldav }}
            <small>{{ ljpccalendarmoduletranslations.caldavDescription }}</small>
          </label>
        </div>
        <div class="alert alert-warning">{{ ljpccalendarmoduletranslations.calendarTypeWarning }}</div>
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
          <input type="text" class="form-control" id="caldavUrl" v-model="caldavUrl" :placeholder="ljpccalendarmoduletranslations.enterCaldavUrl">
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
                  <span class="drag-handle">&#9776;</span>
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
          <button type="button" @click="addCustomField" class="btn btn-primary mt-2">{{ ljpccalendarmoduletranslations.addCustomField }}</button>
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
          <tr v-for="(permission,index) in permissions" :key="index">
            <td>{{ permission.userTeam }}</td>
            <td><input type="checkbox" v-model="permission.showInDashboard"></td>
            <td><input type="checkbox" v-model="permission.showInCalendar"></td>
            <td v-if="calendarType !== 'ics'"><input type="checkbox" v-model="permission.createItems"></td>
            <td v-if="calendarType !== 'ics'"><input type="checkbox" v-model="permission.editItems"></td>
          </tr>
          </tbody>
        </table>
      </div>


      <button type="submit" v-if="!loadingIndicator" class="btn btn-primary">{{ ljpccalendarmoduletranslations.createCalendar }}</button>
      <span v-else>{{ ljpccalendarmoduletranslations.creating }}</span>
    </form>
  </div>
</template>
<style scoped>
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
