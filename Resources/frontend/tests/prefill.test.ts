import { describe, expect, it } from 'vitest'
import { conversationFieldValues, conversationTitle } from '../src/conversation/prefill'

describe('conversation prefill', () => {
  it('names the assignee like v2 did', () => {
    expect(conversationTitle('Printer broken', 'Kim')).toBe('[Kim] Printer broken')
    expect(conversationTitle('Printer broken', null)).toBe('Printer broken')
  })

  it('puts the customer email in email fields and switches booleans off', () => {
    const fields = [
      { id: 1, name: 'Email', type: 'email' as const, required: false, options: [] },
      { id: 2, name: 'Paid', type: 'boolean' as const, required: false, options: [] },
      { id: 3, name: 'Note', type: 'text' as const, required: false, options: [] },
    ]
    expect(conversationFieldValues(fields, 'c@example.test')).toEqual({ 1: 'c@example.test', 2: false, 3: '' })
  })
})
