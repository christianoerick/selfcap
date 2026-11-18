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

  // ---- WooCommerce classic checkout and Pay for order ----
  // Some themes duplicate the payment box or move the Place order button, so the
  // widget can be printed more than once or far from the button. One widget is
  // kept, and placed where the settings say (data-position on the widget):
  //   button   → right above the visible Place order button (default)
  //   selector → right before the element matching data-selector
  //   others   → where WooCommerce printed it (before/after payment or billing)
  var visible = function (el) {
    return !!(el && el.getClientRects().length)
  }

  var placeTarget = function (form, widget) {
    var position = widget.getAttribute('data-position') || ''

    if (position === 'selector') {
      try {
        return document.querySelector(widget.getAttribute('data-selector') || '')
      } catch (error) {
        return null
      }
    }

    if (position !== 'button') return null

    var buttons = form.querySelectorAll('#place_order, [name="woocommerce_checkout_place_order"], [name="woocommerce_pay"]')
    for (var i = 0; i < buttons.length; i++) if (visible(buttons[i])) return buttons[i]
    return buttons[0] || null
  }

  var kept = null
  var arranging = false

  var arrange = function () {
    var form = document.querySelector('form.checkout, form#order_review')
    if (!form || arranging) return

    var all = Array.prototype.slice.call(form.querySelectorAll('.self-captcha'))
    if (!all.length) return

    arranging = true

    // WooCommerce rebuilds the payment box on every totals refresh: a widget the
    // visitor already solved is put back in place of the fresh copy.
    var live = kept && kept.dataset.selfCaptchaId ? kept : all[0]
    if (!live.isConnected) all[0].replaceWith(live)

    all.forEach(function (widget) {
      if (widget !== live && widget.isConnected) widget.remove()
    })

    var target = placeTarget(form, live)
    if (target && target.parentNode && target.previousElementSibling !== live) {
      target.parentNode.insertBefore(live, target)
    }

    if (!live.dataset.selfCaptchaId) captcha.render(live)
    kept = live
    arranging = false
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrange)
  else arrange()

  // Themes often rearrange the checkout after load: follow them, briefly.
  var watched = document.querySelector('form.checkout, form#order_review')
  if (watched && window.MutationObserver) {
    var pending = null
    new MutationObserver(function () {
      clearTimeout(pending)
      pending = setTimeout(arrange, 50)
    }).observe(watched, { childList: true, subtree: true })
  }

  if (window.jQuery) {
    var $ = window.jQuery

    $(document.body).on('updated_checkout', arrange)

    $(document.body).on('checkout_error', function () {
      renew(kept)
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
