import { powCore } from './pow.js'

const script = document.currentScript
const SCRIPT_ORIGIN = script && script.src ? new URL(script.src).origin : location.origin
const DEFAULT_ENDPOINT = SCRIPT_ORIGIN + '/api/v1'

// Shapes the server can answer with. Anything else is treated as a failure, so a
// response can never smuggle code into a callback or the page.
const TOKEN_SHAPE = /^[A-Za-z0-9_-]{1,2048}\.[A-Za-z0-9_-]{1,128}$/
const CODE_SHAPE = /^[a-z0-9-]{1,40}$/

// Named callbacks come from HTML attributes, which other people may be able to
// write (a post's content, for instance). Never let them reach code runners.
const UNSAFE_CALLBACKS = ['eval', 'Function', 'setTimeout', 'setInterval', 'execScript', 'open', 'location', 'importScripts']

/**
 * The endpoint can only be this page's own site or the site serving the script.
 * An attribute pointing anywhere else is ignored, so injected markup cannot send
 * the widget to an attacker's server.
 */
function safeEndpoint(value) {
  if (!value) return DEFAULT_ENDPOINT

  try {
    const url = new URL(value, location.href)
    if (url.origin === location.origin || url.origin === SCRIPT_ORIGIN) return url.href.replace(/\/$/, '')
  } catch (error) {
    // invalid URL: fall through
  }

  console.warn('SelfCaptcha: ignoring endpoint on another site:', value)
  return DEFAULT_ENDPOINT
}
const MAX_WORKERS = 4
const CHUNK = 20000

const TEXTS = {
  en: {
    idle: "I'm not a robot",
    working: 'Verifying…',
    success: 'Verified',
    error: 'Verification failed',
    unavailable: 'Verification unavailable',
    blocked: 'Access blocked',
    retry: 'Try again',
  },
  pt: {
    idle: 'Não sou um robô',
    working: 'Verificando…',
    success: 'Verificado',
    error: 'Falha na verificação',
    unavailable: 'Verificação indisponível',
    blocked: 'Acesso bloqueado',
    retry: 'Tentar de novo',
  },
  es: {
    idle: 'No soy un robot',
    working: 'Verificando…',
    success: 'Verificado',
    error: 'Verificación fallida',
    unavailable: 'Verificación no disponible',
    blocked: 'Acceso bloqueado',
    retry: 'Reintentar',
  },
}

const STYLE = `
:host{display:inline-block;--bg:#fafafa;--border:#d4d4d8;--text:#18181b;--muted:#71717a;--accent:#16a34a;--danger:#dc2626;--box:#fff}
:host([data-theme=dark]){--bg:#1f1f23;--border:#3f3f46;--text:#f4f4f5;--muted:#a1a1aa;--accent:#22c55e;--danger:#f87171;--box:#27272a}
@media (prefers-color-scheme:dark){:host([data-theme=auto]){--bg:#1f1f23;--border:#3f3f46;--text:#f4f4f5;--muted:#a1a1aa;--accent:#22c55e;--danger:#f87171;--box:#27272a}}
*{box-sizing:border-box}
.card{display:flex;align-items:center;gap:12px;width:300px;max-width:100%;height:65px;padding:0 14px;background:var(--bg);border:1px solid var(--border);border-radius:6px;color:var(--text);font:14px/1.3 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.check{flex:none;display:grid;place-items:center;width:28px;height:28px;padding:0;background:var(--box);border:2px solid var(--border);border-radius:5px;color:#fff;cursor:pointer;transition:border-color .15s,background .15s}
.check:hover{border-color:var(--muted)}
.check:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.check svg{width:18px;height:18px;display:none}
.card[data-state=working] .check{border-color:transparent;background:transparent;cursor:progress}
.card[data-state=working] .check::after{content:"";width:22px;height:22px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
.card[data-state=success] .check{background:var(--accent);border-color:var(--accent);cursor:default}
.card[data-state=success] .check .ok{display:block}
.card[data-state=error] .check{background:var(--danger);border-color:var(--danger);cursor:default}
.card[data-state=error] .check .no{display:block}
.label{flex:1;min-width:0}
.retry{display:none;margin-top:2px;padding:0;background:none;border:0;color:var(--muted);font:inherit;font-size:12px;text-decoration:underline;cursor:pointer}
.card[data-state=error] .retry.on{display:block}
.brand{flex:none;display:flex;flex-direction:column;align-items:center;gap:2px;color:var(--muted);font-size:9px;letter-spacing:.06em;text-transform:uppercase}
.brand svg{width:22px;height:22px}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.card[data-state=working] .check::after{animation-duration:2.4s}}
`

const MARKUP = `
<div class="card" data-state="idle">
  <button type="button" class="check" role="checkbox" aria-checked="false">
    <svg class="ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
    <svg class="no" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M7 7l10 10M17 7L7 17"/></svg>
  </button>
  <div class="label"><span class="text" aria-live="polite"></span><button type="button" class="retry"></button></div>
  <div class="brand" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5.5c0 4.4-2.9 8-7 9.5-4.1-1.500-7-5.100-7-9.500V6z"/><path d="M9 12l2.200 2.200L15 10.500"/></svg>
    <span>captcha</span>
  </div>
</div>`

// Page-wide activity counters, shared by every widget on the page.
const activity = { m: 0, k: 0, t: 0, s: 0 }
const listen = (type, key) => window.addEventListener(type, () => activity[key]++, { passive: true, capture: true })
listen('pointermove', 'm')
listen('keydown', 'k')
listen('touchstart', 't')
listen('scroll', 's')

/** Finds the proof-of-work nonce, in Web Workers when allowed, on the main thread otherwise. */
function solve(salt, difficulty) {
  let cancel = () => {}

  const promise = new Promise((resolve) => {
    const count = Math.max(1, Math.min(MAX_WORKERS, navigator.hardwareConcurrency || 2))
    const workers = []
    let url = null
    let timer = null
    let done = false

    cancel = () => {
      done = true
      workers.forEach((worker) => worker.terminate())
      if (url) URL.revokeObjectURL(url)
      clearTimeout(timer)
    }

    const finish = (nonce) => {
      if (done) return
      cancel()
      resolve(String(nonce))
    }

    const onMainThread = () => {
      const search = powCore()
      let start = 0
      const step = () => {
        if (done) return
        const nonce = search(salt, difficulty, start, 1, CHUNK)
        if (nonce >= 0) return finish(nonce)
        start += CHUNK
        timer = setTimeout(step, 0)
      }
      step()
    }

    try {
      const source = `var search=(${powCore.toString()})();onmessage=function(e){var d=e.data,n=d.start;for(;;){var r=search(d.salt,d.difficulty,n,d.step,${CHUNK});if(r>=0){postMessage(r);return}n+=d.step*${CHUNK}}}`
      url = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }))

      for (let i = 0; i < count; i++) {
        const worker = new Worker(url)
        worker.onmessage = (event) => finish(event.data)
        worker.onerror = () => {
          // Blocked by the page CSP or similar: fall back once to the main thread.
          if (done || workers.failed) return
          workers.failed = true
          workers.forEach((item) => item.terminate())
          onMainThread()
        }
        worker.postMessage({ salt, difficulty, start: i, step: count })
        workers.push(worker)
      }
    } catch (error) {
      onMainThread()
    }
  })

  return { promise, cancel }
}

function browserSignals() {
  const nav = navigator
  return {
    wd: nav.webdriver === true,
    ua: nav.userAgent,
    lang: (nav.languages || []).length,
    plg: nav.plugins ? nav.plugins.length : -1,
    hc: nav.hardwareConcurrency || 0,
    sw: screen.width,
    sh: screen.height,
    ow: window.outerWidth,
    oh: window.outerHeight,
    chr: !!window.chrome,
    touch: 'ontouchstart' in window || nav.maxTouchPoints > 0,
    tz: (() => {
      try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone
      } catch (error) {
        return ''
      }
    })(),
  }
}

/**
 * A language forced by the site (data-language="pt") wins. Otherwise ("auto" or
 * nothing) the visitor's browser languages are tried in order, then the page's
 * own lang attribute, then English.
 */
function pickLanguage(forced) {
  const candidates = forced && forced !== 'auto'
    ? [forced]
    : [...(navigator.languages || [navigator.language]), document.documentElement.lang]

  for (const candidate of candidates) {
    const code = String(candidate || '').slice(0, 2).toLowerCase()
    if (TEXTS[code]) return code
  }

  return 'en'
}

const widgets = new Map()
let nextId = 1

class Widget {
  constructor(container, options) {
    this.container = container
    this.options = options
    this.endpoint = safeEndpoint(options.endpoint)
    this.texts = TEXTS[pickLanguage(options.language)]
    this.token = ''
    this.job = null
    this.expiry = null

    const host = document.createElement('div')
    host.dataset.theme = ['light', 'dark'].includes(options.theme) ? options.theme : 'auto'
    const root = host.attachShadow({ mode: 'open' })
    root.innerHTML = `<style>${STYLE}</style>${MARKUP}`

    this.card = root.querySelector('.card')
    this.check = root.querySelector('.check')
    this.text = root.querySelector('.text')
    this.retry = root.querySelector('.retry')
    this.retry.textContent = this.texts.retry

    this.input = document.createElement('input')
    this.input.type = 'hidden'
    this.input.name = options.fieldName || 'captcha-response'

    container.textContent = ''
    container.append(host, this.input)

    this.check.addEventListener('click', (event) => this.onClick(event))
    this.retry.addEventListener('click', () => this.reset())

    this.reset()
  }

  setState(state, message) {
    this.card.dataset.state = state
    this.check.setAttribute('aria-checked', state === 'success' ? 'true' : 'false')
    this.check.disabled = state !== 'idle'
    this.text.textContent = message || this.texts[state]
  }

  /** Back to the unchecked box, with a fresh challenge being solved in the background. */
  reset() {
    this.cancel()
    this.token = ''
    this.input.value = ''
    this.shownAt = performance.now()
    this.movesAtShow = activity.m
    this.retry.classList.remove('on')
    this.setState('idle')
    this.prepare().catch(() => {})
  }

  cancel() {
    if (this.job && this.job.solver) this.job.solver.cancel()
    this.job = null
    clearTimeout(this.expiry)
  }

  /** Fetches a challenge and starts solving it. Resolves with { challenge, nonce, ms }. */
  prepare() {
    const job = { solver: null, expiresAt: 0 }
    this.job = job

    job.promise = this.request('/challenge', { sitekey: this.options.sitekey }).then((data) => {
      if (this.job !== job) throw Object.assign(new Error('cancelled'), { code: 'cancelled' })
      const started = performance.now()
      job.expiresAt = Date.now() + (data.expires_in - 10) * 1000
      job.solver = solve(data.salt, data.difficulty)
      return job.solver.promise.then((nonce) => ({
        challenge: data.challenge,
        nonce,
        ms: Math.round(performance.now() - started),
      }))
    })

    return job.promise
  }

  onClick(event) {
    if (this.card.dataset.state !== 'idle') return

    const click = {
      trusted: event.isTrusted === true,
      dt: Math.round(performance.now() - this.shownAt),
      moves: activity.m - this.movesAtShow,
      type: event.pointerType || (event.detail === 0 ? 'key' : 'mouse'),
    }

    this.setState('working')

    // The challenge solved on load may have failed or expired while the page sat open.
    const stale = !this.job || (this.job.expiresAt && this.job.expiresAt < Date.now())
    const solved = stale ? (this.cancel(), this.prepare()) : this.job.promise.catch(() => this.prepare())

    solved
      .then((solution) =>
        this.request('/verify', {
          challenge: solution.challenge,
          nonce: solution.nonce,
          signals: Object.assign(browserSignals(), { ev: Object.assign({}, activity), pow: solution.ms, click }),
        }),
      )
      .then((data) => this.succeed(data))
      .catch((error) => this.fail(error))
  }

  succeed(data) {
    if (typeof data.token !== 'string' || !TOKEN_SHAPE.test(data.token)) {
      this.fail({ code: 'invalid-response' })
      return
    }

    const ttl = Math.min(3600, Math.max(20, Number(data.expires_in) || 300))

    this.job = null
    this.token = data.token
    this.input.value = data.token
    this.setState('success')
    this.call('callback', data.token)

    this.expiry = setTimeout(() => {
      this.reset()
      this.call('expiredCallback')
    }, (ttl - 10) * 1000)
  }

  fail(error) {
    const code = error && typeof error.code === 'string' && CODE_SHAPE.test(error.code) ? error.code : 'network-error'
    if (code === 'cancelled') return

    this.job = null
    const final = ['site-disabled', 'invalid-sitekey', 'domain-not-allowed', 'blocked'].includes(code)
    const message = code === 'blocked' ? this.texts.blocked : final ? this.texts.unavailable : this.texts.error

    this.retry.classList.toggle('on', !final)
    this.setState('error', message)
    this.call('errorCallback', code)
  }

  request(path, body) {
    return fetch(this.endpoint + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    })
      .then((response) => response.json().catch(() => ({})))
      .then((data) => {
        if (!data.success) {
          const code = typeof data.error === 'string' && CODE_SHAPE.test(data.error) ? data.error : 'request-failed'
          const error = new Error(code)
          error.code = code
          throw error
        }
        return data
      })
  }

  call(name, argument) {
    const handler = this.options[name]
    let fn = null

    if (typeof handler === 'function') {
      fn = handler
    } else if (typeof handler === 'string' && /^[A-Za-z_$][\w$]{0,63}$/.test(handler) && !UNSAFE_CALLBACKS.includes(handler)) {
      fn = window[handler]
    }

    if (typeof fn === 'function') {
      try {
        fn(argument)
      } catch (error) {
        console.error(error)
      }
    }
  }

  remove() {
    this.cancel()
    this.container.textContent = ''
  }
}

function resolveElement(target) {
  return typeof target === 'string' ? document.querySelector(target) : target
}

function render(target, options = {}) {
  const container = resolveElement(target)
  if (!container) throw new Error('SelfCaptcha: container not found')
  if (container.dataset.selfCaptchaId) return container.dataset.selfCaptchaId

  const data = container.dataset
  const settings = {
    sitekey: options.sitekey || data.sitekey,
    theme: options.theme || data.theme,
    language: options.language || data.language,
    endpoint: options.endpoint || data.endpoint,
    fieldName: options.fieldName || data.responseFieldName,
    callback: options.callback || data.callback,
    errorCallback: options['error-callback'] || options.errorCallback || data.errorCallback,
    expiredCallback: options['expired-callback'] || options.expiredCallback || data.expiredCallback,
  }

  if (!settings.sitekey) throw new Error('SelfCaptcha: missing sitekey')

  const id = String(nextId++)
  container.dataset.selfCaptchaId = id
  widgets.set(id, new Widget(container, settings))

  return id
}

function find(target) {
  if (target === undefined) return widgets.values().next().value
  if (widgets.has(String(target))) return widgets.get(String(target))
  const container = resolveElement(target)
  return container ? widgets.get(container.dataset.selfCaptchaId) : undefined
}

function renderAll() {
  document.querySelectorAll('.self-captcha').forEach((container) => {
    try {
      render(container)
    } catch (error) {
      console.error(error)
    }
  })
}

window.SelfCaptcha = {
  render,
  renderAll,
  reset: (target) => {
    const widget = find(target)
    if (widget) widget.reset()
  },
  getResponse: (target) => {
    const widget = find(target)
    return widget ? widget.token : ''
  },
  remove: (target) => {
    const widget = find(target)
    if (!widget) return
    widgets.delete(widget.container.dataset.selfCaptchaId)
    delete widget.container.dataset.selfCaptchaId
    widget.remove()
  },
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', renderAll)
} else {
  renderAll()
}
