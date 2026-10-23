import { initialFieldValues } from '../shared/form-model'
import type { FieldDefinition, FieldValue } from '../shared/types'

/** v2 behavior: "[Assignee] Subject" when the conversation is assigned. */
export function conversationTitle(subject: string, assignee: string | null): string {
  return assignee ? `[${assignee}] ${subject}` : subject
}

/** Email fields start with the customer's address; the rest starts empty (booleans off). */
export function conversationFieldValues(fields: FieldDefinition[], customerEmail: string | null): Record<string, FieldValue> {
  const prefill: Record<string, FieldValue> = {}
  if (customerEmail) {
    for (const field of fields) {
      if (field.type === 'email') {
        prefill[String(field.id)] = customerEmail
      }
    }
  }

  return initialFieldValues(fields, prefill)
}
