import { notify } from '../shared/alerts'
import { ApiError, createApi } from '../shared/api'
import { type BaseConfig, readConfig } from '../shared/config'
import { setStrings, t } from '../shared/i18n'

/**
 * The invite cards below a customer's message (Http/InviteCard.php): posts a card's form (or, for Yes, Maybe and No,
 * the clicked button's formaction with its answer) and shows the card it becomes.
 */
const config = readConfig<BaseConfig>('ljpc-invite-config')
setStrings(config.strings)
const api = createApi(config.csrf)

document.addEventListener('submit', (event) => {
  const form = event.target
  if (form instanceof HTMLFormElement && form.hasAttribute('data-ljpc-invite-form')) {
    event.preventDefault()
    void apply(form, (event as SubmitEvent).submitter)
  }
})

async function apply(form: HTMLFormElement, submitter: HTMLElement | null): Promise<void> {
  const buttons = Array.from(form.querySelectorAll('button'))
  const calendar = form.querySelector('select')
  const clicked = submitter instanceof HTMLButtonElement ? submitter : null
  const body: Record<string, string> = calendar ? { calendar_id: calendar.value } : {}
  if (clicked?.name) {
    body[clicked.name] = clicked.value
  }
  buttons.forEach((button) => (button.disabled = true))
  try {
    const answer = await api.post<{ message: string; html: string }>(clicked?.hasAttribute('formaction') ? clicked.formAction : form.action, body)
    const card = form.closest('[data-ljpc-invite]')
    if (card) {
      card.outerHTML = answer.html // rendered and escaped by the server (partials/invite_card)
    }
    notify('success', answer.message)
  } catch (error) {
    notify('error', error instanceof ApiError ? error.message : t('Something went wrong. Try again.'))
    buttons.forEach((button) => (button.disabled = false))
  }
}
