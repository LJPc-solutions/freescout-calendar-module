declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

interface Window {
  showFloatingAlert?: (type: 'success' | 'error', message: string, noAutohide?: boolean) => void
}
