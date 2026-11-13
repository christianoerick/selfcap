/* Glue between the widget and forms that submit without reloading the page. */
(function () {
  var captcha = window.SelfCaptcha
  if (!captcha) return

  // A submit that did not go through spent the token: ask again. A visitor who
  // had not answered yet keeps the challenge already solved in the background.
  var renew = function (container) {
    if (container && container.isConnected && captcha.getResponse(container)) captcha.reset(container)
  }

  // ---- Contact Form 7 ----
  document.addEventListener('wpcf7submit', function (event) {
    renew(event.target && event.target.querySelector && event.target.querySelector('.self-captcha'))
  })

  // ---- WooCommerce classic checkout ----
  if (window.jQuery) {
    var $ = window.jQuery
    var kept = null

    var current = function () {
      return document.querySelector('form.checkout .self-captcha')
    }

    $(function () {
      kept = current()
    })

    // WooCommerce swaps the payment box on every totals refresh, which would
    // drop a widget the visitor already solved: put the live one back.
    $(document.body).on('updated_checkout', function () {
      var fresh = current()
      if (!fresh) return

      if (kept && fresh !== kept && kept.dataset.selfCaptchaId) {
        fresh.replaceWith(kept)
      } else {
        captcha.render(fresh)
        kept = fresh
      }
    })

    $(document.body).on('checkout_error', function () {
      renew(current())
    })
  }

  // ---- WooCommerce block checkout ----
  var data = window.wp && window.wp.data

  if (data && document.querySelector('.self-captcha-block, .wc-block-checkout, .wp-block-woocommerce-checkout')) {
    var STORE = 'wc/store/checkout'
    var live = null

    var send = function (token) {
      var actions = data.dispatch(STORE)
      var set = actions && (actions.setExtensionData || actions.__internalSetExtensionData)
      if (set) set('selfcap', { token: token || '' })
    }

    var mount = function () {
      var container = document.querySelector('.self-captcha-block:not([data-self-captcha-id])')
      if (!container) return

      live = container
      send('')
      captcha.render(container, {
        callback: send,
        'expired-callback': function () {
          send('')
        },
        'error-callback': function () {
          send('')
        },
      })
    }

    // The block is built in the browser and may be rebuilt at any time.
    new MutationObserver(mount).observe(document.body, { childList: true, subtree: true })
    mount()

    // An order attempt that did not go through.
    var wasBusy = false

    data.subscribe(function () {
      var checkout = data.select(STORE)
      if (!checkout || !checkout.isProcessing) return

      var busy = checkout.isProcessing() || (checkout.isBeforeProcessing && checkout.isBeforeProcessing()) || (checkout.isAfterProcessing && checkout.isAfterProcessing())

      var finished = wasBusy && !busy
      wasBusy = busy

      if (finished && !(checkout.isComplete && checkout.isComplete())) {
        send('')
        renew(live)
      }
    })
  }
})()
