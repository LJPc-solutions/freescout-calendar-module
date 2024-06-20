// import './assets/main.css'

import {createApp} from 'vue'
import App from './App.vue'

const loadApp = () => {
    const app = createApp(App)
    app.mount('#calendar-settings-app')
}

document.addEventListener('DOMContentLoaded', function () {
    //timeout 20ms until window.ljpccalendarmoduletranslations is available
    let timeout = 0
    const checkTranslations = () => {
        if (window.ljpccalendarmoduletranslations) {
            clearTimeout(timeout)
            loadApp()
        } else {
            timeout = setTimeout(checkTranslations, 20)
        }
    }

    checkTranslations()
})
