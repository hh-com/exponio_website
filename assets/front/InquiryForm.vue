<script setup lang="ts">
import { computed, nextTick, reactive, ref, useId } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n'

const props = defineProps<{
  endpoint: string
  token: string
  privacyUrl: string | null
}>()

type Field = 'name' | 'email' | 'message'

const id = useId()
const form = reactive({
  name: '',
  email: '',
  message: '',
  website: '', // Honeypot – bleibt für Menschen unsichtbar und leer
})
const errors = ref<Partial<Record<Field, string>>>({})
const generalError = ref('')
const sending = ref(false)
const done = ref(false)
const formEl = ref<HTMLFormElement | null>(null)
const successEl = ref<HTMLElement | null>(null)

const privacyNotice = computed(() => t('form_privacy', { url: props.privacyUrl ?? '#' }))
const describedBy = (field: Field) => (errors.value[field] ? `${id}-${field}-error` : undefined)

async function submit() {
  sending.value = true
  errors.value = {}
  generalError.value = ''
  try {
    const response = await fetch(props.endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...form, token: props.token }),
    })
    const data = await response.json().catch(() => ({}))
    if (response.ok && data.ok) {
      done.value = true
      await nextTick()
      successEl.value?.focus()
    } else if (data.errors) {
      errors.value = data.errors
      generalError.value = t('form_check')
      await nextTick()
      // Fokus auf das erste fehlerhafte Feld (WCAG 3.3.1)
      formEl.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
    } else {
      generalError.value = data.error || t('form_error')
    }
  } catch {
    generalError.value = t('form_error')
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div v-if="done" ref="successEl" tabindex="-1" role="status" class="flex animate-slide-up flex-col items-center justify-center rounded-2xl bg-success-soft px-6 py-12 text-center focus:outline-none">
    <span class="grid size-14 place-items-center rounded-full bg-white text-success shadow-sm">
      <Icon name="check" class="size-7" />
    </span>
    <h2 class="mt-4 text-xl">{{ t('form_success_title') }}</h2>
    <p class="mt-2 max-w-sm text-muted">{{ t('form_success_text') }}</p>
  </div>

  <form v-else ref="formEl" class="grid gap-5 sm:grid-cols-2" novalidate @submit.prevent="submit">
    <p v-if="generalError" role="alert" class="rounded-xl bg-warning-soft px-4 py-3 text-sm font-medium text-warning sm:col-span-2">{{ generalError }}</p>

    <div>
      <label class="label" :for="`${id}-name`">{{ t('form_name') }} <span aria-hidden="true">*</span></label>
      <input :id="`${id}-name`" v-model="form.name" class="input" autocomplete="name" required aria-required="true" :aria-invalid="!!errors.name" :aria-describedby="describedBy('name')">
      <p v-if="errors.name" :id="`${id}-name-error`" class="mt-1 text-sm font-medium text-warning">{{ errors.name }}</p>
    </div>
    <div>
      <label class="label" :for="`${id}-email`">{{ t('form_email') }} <span aria-hidden="true">*</span></label>
      <input :id="`${id}-email`" v-model="form.email" class="input" type="email" autocomplete="email" inputmode="email" required aria-required="true" :aria-invalid="!!errors.email" :aria-describedby="describedBy('email')">
      <p v-if="errors.email" :id="`${id}-email-error`" class="mt-1 text-sm font-medium text-warning">{{ errors.email }}</p>
    </div>
    <div class="sm:col-span-2">
      <label class="label" :for="`${id}-message`">{{ t('form_message') }} <span aria-hidden="true">*</span></label>
      <textarea :id="`${id}-message`" v-model="form.message" class="input min-h-40 resize-y" rows="6" required aria-required="true" :aria-invalid="!!errors.message" :aria-describedby="describedBy('message')" />
      <p v-if="errors.message" :id="`${id}-message-error`" class="mt-1 text-sm font-medium text-warning">{{ errors.message }}</p>
    </div>

    <!-- Honeypot -->
    <div class="absolute -start-[9999px] h-0 overflow-hidden" aria-hidden="true">
      <label :for="`${id}-website`">Website</label>
      <input :id="`${id}-website`" v-model="form.website" type="text" tabindex="-1" autocomplete="off">
    </div>

    <!-- eslint-disable-next-line vue/no-v-html -- Text aus eigener Übersetzungsdatei -->
    <p class="text-sm text-muted sm:col-span-2" v-html="privacyNotice" />
    <p class="text-sm text-muted sm:col-span-2"><span aria-hidden="true">*</span> {{ t('form_required') }}</p>

    <div class="sm:col-span-2">
      <button type="submit" class="btn btn-primary w-full sm:w-auto" :disabled="sending">
        <Icon name="send" class="flip-rtl size-4" />
        {{ sending ? t('form_sending') : t('form_submit') }}
      </button>
    </div>
  </form>
</template>
