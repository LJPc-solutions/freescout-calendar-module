import type { BaseConfig } from '../shared/config'
import type { HealthProblem } from './banners'

/** #ljpc-calendar-config (Http/FrontendConfig::page). */
export interface PageConfig extends BaseConfig {
  user: { id: number; is_admin: boolean }
  routes: {
    calendars: string
    events: string
    /** Show (with an "e<id>" or legacy ref), update and delete (with the numeric id); "__ID__" is the placeholder. */
    event: string
    settings: string | null
    /** POST: "Sync now" for every external calendar the user can view. */
    sync: string
  }
  migration_complete: boolean
  /** Problems an administrator has to solve; always empty for agents. */
  health: HealthProblem[]
}
