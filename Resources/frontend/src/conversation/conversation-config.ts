import type { BaseConfig } from '../shared/config'

/** #ljpc-conversation-config (Http/FrontendConfig::conversation). */
export interface ConversationConfig extends BaseConfig {
  /** conversation_events is this conversation's own create URL. */
  routes: { calendars: string; conversation_events: string; page: string }
  conversation: { id: number; subject: string; customer_email: string | null; assignee: string | null }
}
