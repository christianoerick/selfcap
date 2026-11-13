/* Self Captcha admin: chart tooltip, keyboard navigation, confirmations. */
(function () {
  document.querySelectorAll('.scs [data-confirm]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (!window.confirm(link.getAttribute('data-confirm'))) event.preventDefault()
    })
  })

  var chart = document.querySelector('.scs-chart')
  if (!chart) return

  var tip = chart.querySelector('.scs-tip')
  var cols = Array.prototype.slice.call(chart.querySelectorAll('.scs-col'))
  var labels = JSON.parse(chart.getAttribute('data-labels') || '{}')
  var active = null

  var row = function (key, value) {
    var line = document.createElement('div')
    var mark = document.createElement('i')
    var name = document.createElement('span')
    var number = document.createElement('b')

    line.className = 'scs-tip__row'
    mark.className = 'is-' + key
    name.textContent = labels[key] || key
    number.textContent = Number(value || 0).toLocaleString()
    line.append(mark, name, number)

    return line
  }

  var show = function (col) {
    if (active) active.classList.remove('is-active')
    active = col
    col.classList.add('is-active')

    var date = document.createElement('div')
    date.className = 'scs-tip__date'
    date.textContent = col.getAttribute('data-date')

    tip.textContent = ''
    tip.append(date, row('passed', col.dataset.passed), row('failed', col.dataset.failed), row('blocked', col.dataset.blocked))
    tip.hidden = false

    // Beside the column, flipped to the other side near the right edge.
    var box = chart.getBoundingClientRect()
    var bar = col.getBoundingClientRect()
    var left = bar.right - box.left + 8

    if (left + tip.offsetWidth > box.width) left = bar.left - box.left - tip.offsetWidth - 8
    if (left < 0) left = Math.max(0, (box.width - tip.offsetWidth) / 2)

    tip.style.left = left + 'px'
    tip.style.top = '8px'
  }

  var hide = function () {
    if (active) active.classList.remove('is-active')
    active = null
    tip.hidden = true
  }

  cols.forEach(function (col, index) {
    col.addEventListener('pointerenter', function () {
      show(col)
    })
    col.addEventListener('focus', function () {
      show(col)
    })
    col.addEventListener('blur', hide)
    col.addEventListener('keydown', function (event) {
      var step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0
      var next = step ? cols[index + step] : event.key === 'Home' ? cols[0] : event.key === 'End' ? cols[cols.length - 1] : null

      if (!next) return

      event.preventDefault()
      col.tabIndex = -1
      next.tabIndex = 0
      next.focus()
    })
  })

  chart.addEventListener('pointerleave', function () {
    if (!chart.contains(document.activeElement) || document.activeElement === document.body) hide()
  })
})()
