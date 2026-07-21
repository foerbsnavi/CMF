document.addEventListener('DOMContentLoaded', function () {
  const BLOCK_TYPES = [
    { type: 'heading', label: 'Heading' },
    { type: 'text', label: 'Text' },
    { type: 'image', label: 'Bild' },
    { type: 'list', label: 'Liste' },
    { type: 'buttons', label: 'Buttons' },
    { type: 'columns', label: 'Spalten' },
    { type: 'html', label: 'HTML' },
    { type: 'blog_overview', label: 'Blog-Uebersicht' },
    { type: 'form', label: 'Formular' }
  ]

  const FORM_FIELD_TYPES = [
    { type: 'text', label: 'Text' },
    { type: 'email', label: 'E-Mail' },
    { type: 'tel', label: 'Telefon' },
    { type: 'textarea', label: 'Mehrzeilig' },
    { type: 'select', label: 'Auswahl' },
    { type: 'checkbox', label: 'Checkbox' },
    { type: 'radio', label: 'Radio' }
  ]

  function slugifyName(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
      .substring(0, 40)
  }

  let uidCounter = 1
  let mediaPickerCallback = null
  let mediaPickerTrigger = null

  function uid(prefix) {
    uidCounter += 1
    return prefix + '_' + Date.now().toString(36) + '_' + uidCounter.toString(36)
  }

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
  }

  function parseJson(value, fallback) {
    try {
      const parsed = JSON.parse(value)
      return parsed && typeof parsed === 'object' ? parsed : fallback
    } catch {
      return fallback
    }
  }

  function normalizePage(raw, fallbackTitle) {
    const page = raw && typeof raw === 'object' ? raw : {}
    const meta = page.meta && typeof page.meta === 'object' ? page.meta : {}
    const content = page.content && typeof page.content === 'object' ? page.content : {}
    let blocks = Array.isArray(content.blocks) ? content.blocks : []

    if (!blocks.length) {
      blocks = [
        {
          id: uid('b'),
          type: 'heading',
          data: {
            level: 1,
            text: fallbackTitle || 'Neue Seite'
          }
        },
        {
          id: uid('b'),
          type: 'text',
          data: {
            html: '<p>Inhalt hier.</p>'
          }
        }
      ]
    }

    return {
      meta: {
        title: String(meta.title || fallbackTitle || 'Neue Seite'),
        description: String(meta.description || '')
      },
      content: {
        blocks: blocks
      }
    }
  }

  function makeDefaultBlock(type) {
    if (type === 'heading') {
      return {
        id: uid('b'),
        type: 'heading',
        data: { level: 2, text: 'Überschrift' }
      }
    }

    if (type === 'text') {
      return {
        id: uid('b'),
        type: 'text',
        data: { html: '<p>Text</p>' }
      }
    }

    if (type === 'image') {
      return {
        id: uid('b'),
        type: 'image',
        data: { src: '', alt: '', caption: '', loading: 'lazy' }
      }
    }

    if (type === 'list') {
      return {
        id: uid('b'),
        type: 'list',
        data: { ordered: false, items: ['Listeneintrag'] }
      }
    }

    if (type === 'buttons') {
      return {
        id: uid('b'),
        type: 'buttons',
        data: {
          items: [
            { label: 'Button', href: '/', style: 'primary' }
          ]
        }
      }
    }

    if (type === 'columns') {
      return {
        id: uid('b'),
        type: 'columns',
        data: {
          columns: 2,
          items: [[], []]
        }
      }
    }

    if (type === 'blog_overview') {
      return {
        id: uid('b'),
        type: 'blog_overview',
        data: { category: '' }
      }
    }

    if (type === 'form') {
      return {
        id: uid('b'),
        type: 'form',
        data: {
          title: 'Kontakt',
          intro: '',
          submit_label: 'Absenden',
          success_message: 'Vielen Dank! Ihre Nachricht wurde gesendet.',
          store: true,
          email_to: '',
          fields: [
            { name: 'name', type: 'text', label: 'Name', required: true },
            { name: 'email', type: 'email', label: 'E-Mail', required: true },
            { name: 'nachricht', type: 'textarea', label: 'Nachricht', required: true },
            { name: 'einwilligung', type: 'checkbox', label: 'Ich stimme der Datenschutzerklärung zu.', required: true }
          ]
        }
      }
    }

    return {
      id: uid('b'),
      type: 'html',
      data: { code: '<div>HTML</div>' }
    }
  }

  function createField(label, inputHtml) {
    const wrap = document.createElement('div')
    wrap.className = 'be-field'
    const fieldId = uid('f')
    const htmlWithId = inputHtml.replace(/(type="[^"]*")/, '$1 id="' + fieldId + '"').replace(/(<textarea)/, '$1 id="' + fieldId + '"').replace(/(<select)/, '$1 id="' + fieldId + '"')
    wrap.innerHTML = '<label for="' + fieldId + '">' + esc(label) + '</label>' + htmlWithId
    return wrap
  }

  var ICON_LABELS = { '\u2715': 'Entfernen', '\u2191': 'Nach oben', '\u2193': 'Nach unten', '\u2190': 'Nach links', '\u2192': 'Nach rechts' }

  function createIconButton(label, action) {
    const btn = document.createElement('button')
    btn.type = 'button'
    btn.className = 'be-icon-btn'
    btn.dataset.action = action
    btn.textContent = label
    btn.setAttribute('aria-label', ICON_LABELS[label] || label)
    return btn
  }

  function openMediaPicker(media, callback) {
    const modal = document.getElementById('be-media-modal')
    const grid = modal ? modal.querySelector('[data-be-media-grid]') : null
    if (!modal || !grid) return

    mediaPickerCallback = callback
    grid.innerHTML = ''

    if (!Array.isArray(media) || !media.length) {
      const empty = document.createElement('p')
      empty.textContent = 'Keine Bilder gefunden.'
      grid.appendChild(empty)
    } else {
      media.forEach(function (item) {
        const btn = document.createElement('button')
        btn.type = 'button'
        btn.className = 'be-media-item'
        btn.innerHTML = '<img src="' + esc(item.path) + '" alt="">' +
          '<strong>' + esc(item.filename || item.path) + '</strong><br>' +
          '<small>' + esc(item.path) + '</small>'
        btn.addEventListener('click', function () {
          if (typeof mediaPickerCallback === 'function') {
            mediaPickerCallback(item.path)
          }
          closeMediaPicker()
        })
        grid.appendChild(btn)
      })
    }

    modal.classList.add('is-open')
    modal.setAttribute('aria-hidden', 'false')
    mediaPickerTrigger = document.activeElement
    // Fokus ins Modal setzen
    var firstBtn = grid.querySelector('button')
    if (firstBtn) firstBtn.focus()
  }

  function closeMediaPicker() {
    const modal = document.getElementById('be-media-modal')
    if (!modal) return
    modal.classList.remove('is-open')
    modal.setAttribute('aria-hidden', 'true')
    mediaPickerCallback = null
    // Fokus zurueck zum ausloesenden Element
    if (mediaPickerTrigger && mediaPickerTrigger.focus) {
      mediaPickerTrigger.focus()
      mediaPickerTrigger = null
    }
  }

  document.querySelectorAll('[data-be-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', closeMediaPicker)
  })

  document.addEventListener('click', function (event) {
    const modal = document.getElementById('be-media-modal')
    if (!modal || !modal.classList.contains('is-open')) return
    if (event.target === modal) closeMediaPicker()
  })

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeMediaPicker()
  })

  function buildAddMenu(onAdd) {
    const wrap = document.createElement('div')
    wrap.className = 'be-toolbar'

    BLOCK_TYPES.forEach(function (item) {
      const btn = document.createElement('button')
      btn.type = 'button'
      btn.className = 'btn'
      btn.textContent = '+ ' + item.label
      btn.addEventListener('click', function () {
        onAdd(item.type)
      })
      wrap.appendChild(btn)
    })

    return wrap
  }

  function moveNode(node, direction) {
    if (!node || !node.parentElement) return
    const parent = node.parentElement

    if (direction === 'up') {
      const prev = node.previousElementSibling
      if (prev) parent.insertBefore(node, prev)
      return
    }

    if (direction === 'down') {
      const next = node.nextElementSibling
      if (next) parent.insertBefore(next, node)
      return
    }

    if (direction === 'left') {
      const prev = node.previousElementSibling
      if (prev) parent.insertBefore(node, prev)
      return
    }

    if (direction === 'right') {
      const next = node.nextElementSibling
      if (next) parent.insertBefore(next, node)
    }
  }

  function renderButtonsItems(container, items) {
    const list = document.createElement('div')
    list.className = 'be-lines'

    function addRow(item) {
      const row = document.createElement('div')
      row.className = 'be-line-3'
      row.innerHTML =
        '<input type="text" data-btn-label placeholder="Button-Text" value="' + esc(item.label || '') + '">' +
        '<input type="text" data-btn-href placeholder="Button-Link" value="' + esc(item.href || '') + '">' +
        '<button type="button" class="be-icon-btn" data-remove-btn>✕</button>'

      row.dataset.style = String(item.style || '')

      const removeBtn = row.querySelector('[data-remove-btn]')
      removeBtn.addEventListener('click', function () {
        row.remove()
      })

      list.appendChild(row)
    }

    ;(Array.isArray(items) && items.length ? items : [{ label: 'Button', href: '/', style: 'primary' }]).forEach(addRow)

    const add = document.createElement('button')
    add.type = 'button'
    add.className = 'btn'
    add.textContent = '+ Button'
    add.addEventListener('click', function () {
      addRow({ label: '', href: '', style: '' })
    })

    container.appendChild(list)
    container.appendChild(add)
  }

  function renderListItems(container, items) {
    const list = document.createElement('div')
    list.className = 'be-lines'

    function addRow(value) {
      const row = document.createElement('div')
      row.className = 'be-line'
      row.innerHTML =
        '<input type="text" data-list-item value="' + esc(value || '') + '">' +
        '<button type="button" class="be-icon-btn" data-remove-list-item>✕</button>'

      row.querySelector('[data-remove-list-item]').addEventListener('click', function () {
        row.remove()
      })

      list.appendChild(row)
    }

    ;(Array.isArray(items) && items.length ? items : ['Listeneintrag']).forEach(addRow)

    const add = document.createElement('button')
    add.type = 'button'
    add.className = 'btn'
    add.textContent = '+ Eintrag'
    add.addEventListener('click', function () {
      addRow('')
    })

    container.appendChild(list)
    container.appendChild(add)
  }

  function renderFormFields(container, fields) {
    const list = document.createElement('div')
    list.className = 'be-form-fields'
    list.dataset.formFields = '1'

    function typeOptions(selected) {
      return FORM_FIELD_TYPES.map(function (t) {
        return '<option value="' + t.type + '"' + (t.type === selected ? ' selected' : '') + '>' + esc(t.label) + '</option>'
      }).join('')
    }

    function addRow(field) {
      const f = field && typeof field === 'object' ? field : {}
      const ftype = String(f.type || 'text')
      const row = document.createElement('div')
      row.className = 'be-form-field'
      row.innerHTML =
        '<div class="be-form-field-main">' +
          '<input type="text" data-ff-label placeholder="Beschriftung" value="' + esc(f.label || '') + '">' +
          '<select data-ff-type>' + typeOptions(ftype) + '</select>' +
          '<label class="be-ff-req"><input type="checkbox" data-ff-required' + (f.required ? ' checked' : '') + '> Pflicht</label>' +
          '<button type="button" class="be-icon-btn" data-ff-up aria-label="Nach oben">↑</button>' +
          '<button type="button" class="be-icon-btn" data-ff-down aria-label="Nach unten">↓</button>' +
          '<button type="button" class="be-icon-btn" data-ff-remove aria-label="Entfernen">✕</button>' +
        '</div>' +
        '<div class="be-form-field-options" data-ff-options-wrap>' +
          '<input type="text" data-ff-options placeholder="Optionen mit Komma trennen: A, B, C" value="' +
            esc(Array.isArray(f.options) ? f.options.join(', ') : '') + '">' +
        '</div>'

      const typeSel = row.querySelector('[data-ff-type]')
      const optWrap = row.querySelector('[data-ff-options-wrap]')
      function syncOptVisibility() {
        const t = typeSel.value
        optWrap.style.display = (t === 'select' || t === 'radio') ? '' : 'none'
      }
      syncOptVisibility()
      typeSel.addEventListener('change', syncOptVisibility)

      row.querySelector('[data-ff-remove]').addEventListener('click', function () { row.remove() })
      row.querySelector('[data-ff-up]').addEventListener('click', function () { moveNode(row, 'up') })
      row.querySelector('[data-ff-down]').addEventListener('click', function () { moveNode(row, 'down') })

      list.appendChild(row)
    }

    ;(Array.isArray(fields) && fields.length ? fields : []).forEach(addRow)

    const add = document.createElement('button')
    add.type = 'button'
    add.className = 'btn'
    add.textContent = '+ Feld'
    add.addEventListener('click', function () { addRow({ type: 'text', label: '', required: false }) })

    container.appendChild(list)
    container.appendChild(add)
  }

  function createBlockElement(block, media) {
    const el = document.createElement('div')
    el.className = 'be-block'
    el.dataset.blockId = String(block.id || uid('b'))
    el.dataset.blockType = String(block.type || 'text')

    const typeLabel = BLOCK_TYPES.find(function (item) {
      return item.type === el.dataset.blockType
    })

    const head = document.createElement('div')
    head.className = 'be-block-head'
    head.innerHTML = '<div class="be-block-title">' + esc(typeLabel ? typeLabel.label : el.dataset.blockType) + '</div>'

    const actions = document.createElement('div')
    actions.className = 'be-block-actions'

    const del = createIconButton('✕', 'delete')
    const up = createIconButton('↑', 'up')
    const down = createIconButton('↓', 'down')

    del.addEventListener('click', function () {
      el.remove()
    })
    up.addEventListener('click', function () {
      moveNode(el, 'up')
    })
    down.addEventListener('click', function () {
      moveNode(el, 'down')
    })

    actions.appendChild(del)
    actions.appendChild(up)
    actions.appendChild(down)
    head.appendChild(actions)
    el.appendChild(head)

    const body = document.createElement('div')
    body.className = 'be-block-body'
    const data = block && typeof block.data === 'object' ? block.data : {}

    if (el.dataset.blockType === 'heading') {
      const grid = document.createElement('div')
      grid.className = 'be-grid be-grid-2'
      grid.appendChild(createField('Level', '<select data-field="level">' +
        '<option value="1"' + ((Number(data.level) || 2) === 1 ? ' selected' : '') + '>h1</option>' +
        '<option value="2"' + ((Number(data.level) || 2) === 2 ? ' selected' : '') + '>h2</option>' +
        '<option value="3"' + ((Number(data.level) || 2) === 3 ? ' selected' : '') + '>h3</option>' +
        '<option value="4"' + ((Number(data.level) || 2) === 4 ? ' selected' : '') + '>h4</option>' +
        '<option value="5"' + ((Number(data.level) || 2) === 5 ? ' selected' : '') + '>h5</option>' +
        '<option value="6"' + ((Number(data.level) || 2) === 6 ? ' selected' : '') + '>h6</option>' +
      '</select>'))
      grid.appendChild(createField('Text', '<input type="text" data-field="text" value="' + esc(data.text || '') + '">'))
      body.appendChild(grid)
    }

    if (el.dataset.blockType === 'text') {
      body.appendChild(createField('HTML-Text', '<textarea data-field="html">' + esc(data.html || '') + '</textarea>'))
    }

    if (el.dataset.blockType === 'image') {
      const grid = document.createElement('div')
      grid.className = 'be-grid be-grid-2'
      grid.appendChild(createField('Bildpfad', '<input type="text" data-field="src" value="' + esc(data.src || '') + '">'))
      grid.appendChild(createField('Alt-Text', '<input type="text" data-field="alt" value="' + esc(data.alt || '') + '">'))
      grid.appendChild(createField('Bildunterschrift', '<input type="text" data-field="caption" value="' + esc(data.caption || '') + '">'))
      grid.appendChild(createField('Loading', '<select data-field="loading">' +
        '<option value="lazy"' + ((String(data.loading || 'lazy') === 'lazy') ? ' selected' : '') + '>lazy</option>' +
        '<option value="eager"' + ((String(data.loading || '') === 'eager') ? ' selected' : '') + '>eager</option>' +
      '</select>'))
      body.appendChild(grid)

      const actionsWrap = document.createElement('div')
      actionsWrap.className = 'be-inline-actions'

      const choose = document.createElement('button')
      choose.type = 'button'
      choose.className = 'btn'
      choose.textContent = 'Bild auswählen'

      choose.addEventListener('click', function () {
        const input = body.querySelector('[data-field="src"]')
        const preview = body.querySelector('[data-be-image-preview]')
        openMediaPicker(media, function (path) {
          input.value = path
          if (preview) {
            preview.innerHTML = '<img src="' + esc(path) + '" alt="">'
          }
        })
      })

      actionsWrap.appendChild(choose)
      body.appendChild(actionsWrap)

      const preview = document.createElement('div')
      preview.className = 'be-media-preview'
      preview.dataset.beImagePreview = '1'
      if (data.src) {
        preview.innerHTML = '<img src="' + esc(data.src) + '" alt="">'
      }
      body.appendChild(preview)

      const srcInput = body.querySelector('[data-field="src"]')
      srcInput.addEventListener('input', function () {
        preview.innerHTML = srcInput.value.trim() !== '' ? '<img src="' + esc(srcInput.value.trim()) + '" alt="">' : ''
      })
    }

    if (el.dataset.blockType === 'list') {
      const orderedField = createField('Typ', '<select data-field="ordered">' +
        '<option value="0"' + ((data.ordered ? '' : ' selected')) + '>ungeordnet</option>' +
        '<option value="1"' + ((data.ordered ? ' selected' : '')) + '>geordnet</option>' +
      '</select>')
      body.appendChild(orderedField)
      renderListItems(body, data.items)
    }

    if (el.dataset.blockType === 'buttons') {
      renderButtonsItems(body, data.items)
    }

    if (el.dataset.blockType === 'html') {
      body.appendChild(createField('HTML-Code', '<textarea data-field="code">' + esc(data.code || '') + '</textarea>'))
    }

    if (el.dataset.blockType === 'blog_overview') {
      const info = document.createElement('p')
      info.textContent = 'Zeigt Blogbeitraege als Karten-Grid an.'
      info.style.cssText = 'color:var(--color-muted,#666);font-style:italic;margin:0 0 8px'
      body.appendChild(info)
      var catVal = String(data.category || '')
      body.appendChild(createField('Kategorie-Filter', '<input type="text" data-field="category" value="' + esc(catVal) + '" placeholder="Leer = alle Beitraege">'))
    }

    if (el.dataset.blockType === 'form') {
      const grid = document.createElement('div')
      grid.className = 'be-grid be-grid-2'
      grid.appendChild(createField('Titel (optional)', '<input type="text" data-field="title" value="' + esc(data.title || '') + '">'))
      grid.appendChild(createField('Button-Text', '<input type="text" data-field="submit_label" value="' + esc(data.submit_label || 'Absenden') + '">'))
      body.appendChild(grid)

      body.appendChild(createField('Einleitungstext (optional)', '<input type="text" data-field="intro" value="' + esc(data.intro || '') + '">'))
      body.appendChild(createField('Danke-Meldung', '<input type="text" data-field="success_message" value="' + esc(data.success_message || '') + '">'))

      const grid2 = document.createElement('div')
      grid2.className = 'be-grid be-grid-2'
      grid2.appendChild(createField('E-Mail-Empfänger (optional)', '<input type="text" data-field="email_to" value="' + esc(data.email_to || '') + '" placeholder="Leer = keine E-Mail">'))
      grid2.appendChild(createField('Einsendungen speichern', '<select data-field="store">' +
        '<option value="1"' + ((data.store === false) ? '' : ' selected') + '>ja</option>' +
        '<option value="0"' + ((data.store === false) ? ' selected' : '') + '>nein</option>' +
      '</select>'))
      body.appendChild(grid2)

      const info = document.createElement('p')
      info.className = 'be-help'
      info.innerHTML = '<small>Felder des Formulars. Bei <strong>Auswahl</strong>/<strong>Radio</strong> die Optionen mit Komma trennen. Eine Checkbox eignet sich für die Datenschutz-Einwilligung.</small>'
      body.appendChild(info)

      renderFormFields(body, Array.isArray(data.fields) ? data.fields : [])
    }

    if (el.dataset.blockType === 'columns') {
      const amount = Math.max(2, Math.min(5, Number(data.columns) || 2))
      const items = Array.isArray(data.items) ? data.items.slice(0, amount) : []
      while (items.length < amount) items.push([])

      const config = document.createElement('div')
      config.className = 'be-grid be-grid-2'
      config.appendChild(createField('Spalten', '<select data-field="columns">' +
        '<option value="2"' + (amount === 2 ? ' selected' : '') + '>2</option>' +
        '<option value="3"' + (amount === 3 ? ' selected' : '') + '>3</option>' +
        '<option value="4"' + (amount === 4 ? ' selected' : '') + '>4</option>' +
        '<option value="5"' + (amount === 5 ? ' selected' : '') + '>5</option>' +
      '</select>'))
      body.appendChild(config)

      const colsWrap = document.createElement('div')
      colsWrap.className = 'be-columns-wrap cols-' + amount
      colsWrap.dataset.columnsWrap = '1'
      body.appendChild(colsWrap)

      function renderColumns(count, sourceItems) {
        colsWrap.className = 'be-columns-wrap cols-' + count

        const currentData = []
        Array.from(colsWrap.children).forEach(function (colEl) {
          currentData.push(readColumn(colEl))
        })

        const normalized = Array.isArray(sourceItems) && sourceItems.length ? sourceItems.slice(0, count) : currentData.slice(0, count)
        while (normalized.length < count) normalized.push([])

        colsWrap.innerHTML = ''

        normalized.forEach(function (colBlocks, index) {
          const col = document.createElement('div')
          col.className = 'be-column'
          col.dataset.columnIndex = String(index)

          const colHead = document.createElement('div')
          colHead.className = 'be-column-head'
          colHead.innerHTML = '<div class="be-column-title">Spalte ' + (index + 1) + '</div>'

          const colActions = document.createElement('div')
          colActions.className = 'be-column-actions'

          const remove = createIconButton('✕', 'delete-column')
          const left = createIconButton('←', 'left')
          const right = createIconButton('→', 'right')

          remove.addEventListener('click', function () {
            col.innerHTML = ''
          })

          left.addEventListener('click', function () {
            moveNode(col, 'left')
            refreshColumnTitles(colsWrap)
          })

          right.addEventListener('click', function () {
            moveNode(col, 'right')
            refreshColumnTitles(colsWrap)
          })

          colActions.appendChild(remove)
          colActions.appendChild(left)
          colActions.appendChild(right)
          colHead.appendChild(colActions)
          col.appendChild(colHead)

          const sublist = document.createElement('div')
          sublist.className = 'be-sublist'
          sublist.dataset.blockList = '1'
          col.appendChild(sublist)

          const add = document.createElement('button')
          add.type = 'button'
          add.className = 'be-add'
          add.textContent = '+ Block in diese Spalte'
          add.addEventListener('click', function () {
            openAdder(function (type) {
              sublist.appendChild(createBlockElement(makeDefaultBlock(type), media))
            })
          })
          col.appendChild(add)

          ;(Array.isArray(colBlocks) ? colBlocks : []).forEach(function (subBlock) {
            sublist.appendChild(createBlockElement(subBlock, media))
          })

          colsWrap.appendChild(col)
        })

        refreshColumnTitles(colsWrap)
      }

      const select = config.querySelector('[data-field="columns"]')
      select.addEventListener('change', function () {
        const nextCount = Math.max(2, Math.min(5, Number(select.value) || 2))
        const current = []
        Array.from(colsWrap.children).forEach(function (colEl) {
          current.push(readColumn(colEl))
        })
        renderColumns(nextCount, current.slice(0, nextCount))
      })

      renderColumns(amount, items)
    }

    el.appendChild(body)
    return el
  }

  function refreshColumnTitles(colsWrap) {
    Array.from(colsWrap.children).forEach(function (col, index) {
      const title = col.querySelector('.be-column-title')
      if (title) title.textContent = 'Spalte ' + (index + 1)
    })
  }

  function readColumn(colEl) {
    const list = colEl ? colEl.querySelector('[data-block-list]') : null
    return readBlockList(list)
  }

  function readBlock(el) {
    const type = el.dataset.blockType
    const id = el.dataset.blockId || uid('b')

    if (type === 'heading') {
      return {
        id: id,
        type: 'heading',
        data: {
          level: Math.max(1, Math.min(6, Number(el.querySelector('[data-field="level"]').value) || 2)),
          text: String(el.querySelector('[data-field="text"]').value || '')
        }
      }
    }

    if (type === 'text') {
      return {
        id: id,
        type: 'text',
        data: {
          html: String(el.querySelector('[data-field="html"]').value || '')
        }
      }
    }

    if (type === 'image') {
      return {
        id: id,
        type: 'image',
        data: {
          src: String(el.querySelector('[data-field="src"]').value || ''),
          alt: String(el.querySelector('[data-field="alt"]').value || ''),
          caption: String(el.querySelector('[data-field="caption"]').value || ''),
          loading: String(el.querySelector('[data-field="loading"]').value || 'lazy')
        }
      }
    }

    if (type === 'list') {
      return {
        id: id,
        type: 'list',
        data: {
          ordered: String(el.querySelector('[data-field="ordered"]').value || '0') === '1',
          items: Array.from(el.querySelectorAll('[data-list-item]'))
            .map(function (input) { return String(input.value || '').trim() })
            .filter(function (value) { return value !== '' })
        }
      }
    }

    if (type === 'buttons') {
      return {
        id: id,
        type: 'buttons',
        data: {
          items: Array.from(el.querySelectorAll('.be-line-3')).map(function (row) {
            return {
              label: String((row.querySelector('[data-btn-label]') || {}).value || '').trim(),
              href: String((row.querySelector('[data-btn-href]') || {}).value || '').trim(),
              style: String(row.dataset.style || '')
            }
          }).filter(function (item) {
            return item.label !== '' || item.href !== ''
          })
        }
      }
    }

    if (type === 'columns') {
      const columns = Math.max(2, Math.min(5, Number((el.querySelector('[data-field="columns"]') || {}).value) || 2))
      const items = Array.from(el.querySelectorAll(':scope > .be-block-body > [data-columns-wrap] > .be-column'))
        .slice(0, columns)
        .map(readColumn)

      while (items.length < columns) items.push([])

      return {
        id: id,
        type: 'columns',
        data: {
          columns: columns,
          items: items.slice(0, columns)
        }
      }
    }

    if (type === 'html') {
      return {
        id: id,
        type: 'html',
        data: {
          code: String(el.querySelector('[data-field="code"]').value || '')
        }
      }
    }

    if (type === 'blog_overview') {
      var catField = el.querySelector('[data-field="category"]')
      var catValue = catField ? String(catField.value || '').trim() : ''
      return {
        id: id,
        type: 'blog_overview',
        data: catValue !== '' ? { category: catValue } : {}
      }
    }

    if (type === 'form') {
      function fieldVal(sel) {
        var node = el.querySelector(sel)
        return node ? String(node.value || '') : ''
      }
      var usedNames = {}
      var fields = Array.from(el.querySelectorAll(':scope > .be-block-body [data-form-fields] > .be-form-field')).map(function (row) {
        var label = String((row.querySelector('[data-ff-label]') || {}).value || '').trim()
        var ftype = String((row.querySelector('[data-ff-type]') || {}).value || 'text')
        var required = !!(row.querySelector('[data-ff-required]') || {}).checked
        if (label === '') return null
        var name = slugifyName(label) || 'feld'
        // Namen eindeutig machen
        var base = name, n = 2
        while (usedNames[name]) { name = base + '_' + (n++) }
        usedNames[name] = true
        var field = { name: name, type: ftype, label: label, required: required }
        if (ftype === 'select' || ftype === 'radio') {
          var optRaw = String((row.querySelector('[data-ff-options]') || {}).value || '')
          field.options = optRaw.split(',').map(function (s) { return s.trim() }).filter(function (s) { return s !== '' })
        }
        return field
      }).filter(Boolean)

      var data = {
        title: fieldVal('[data-field="title"]').trim(),
        intro: fieldVal('[data-field="intro"]').trim(),
        submit_label: fieldVal('[data-field="submit_label"]').trim() || 'Absenden',
        success_message: fieldVal('[data-field="success_message"]').trim(),
        store: fieldVal('[data-field="store"]') !== '0',
        email_to: fieldVal('[data-field="email_to"]').trim(),
        fields: fields
      }
      return { id: id, type: 'form', data: data }
    }

    return null
  }

  function readBlockList(listEl) {
    if (!listEl) return []
    return Array.from(listEl.children)
      .filter(function (child) { return child.classList.contains('be-block') })
      .map(readBlock)
      .filter(Boolean)
  }

  function openAdder(onAdd) {
    const chooser = document.createElement('div')
    chooser.className = 'be-toolbar'
    BLOCK_TYPES.forEach(function (item) {
      const btn = document.createElement('button')
      btn.type = 'button'
      btn.className = 'btn'
      btn.textContent = item.label
      btn.addEventListener('click', function () {
        chooser.remove()
        onAdd(item.type)
      })
      chooser.appendChild(btn)
    })
    document.activeElement && document.activeElement.insertAdjacentElement('afterend', chooser)
  }

  function initEditor(shell) {
  const form = shell.closest('form')
  if (!form) return

  const textarea = form.querySelector('[data-block-editor-source]')
  const jsonWrap = form.querySelector('[data-block-editor-json-wrap]')
  const titleSelector = shell.dataset.blockEditorTitleSelector || ''
  const titleInput = titleSelector ? form.querySelector(titleSelector) : null
  const modeButtons = Array.from(form.querySelectorAll('[data-be-mode]'))
  const media = parseJson(shell.dataset.blockEditorMedia || '[]', [])

  if (!textarea) return

  let page = normalizePage(parseJson(textarea.value || '{}', {}), titleInput ? titleInput.value : 'Neue Seite')
  let currentMode = 'visual'
  let currentList = null

  function renderVisualEditor(fromPage) {
    shell.innerHTML = ''

    const addBar = buildAddMenu(function (type) {
      currentList.appendChild(createBlockElement(makeDefaultBlock(type), media))
    })

    const list = document.createElement('div')
    list.className = 'be-list'
    list.dataset.blockList = '1'

    fromPage.content.blocks.forEach(function (block) {
      list.appendChild(createBlockElement(block, media))
    })

    shell.appendChild(addBar)
    shell.appendChild(list)
    currentList = list
  }

  function syncToTextarea() {
    if (!currentList) return

    const currentTitle = titleInput ? String(titleInput.value || '').trim() : String(page.meta.title || '')
    const raw = parseJson(textarea.value || '{}', {})
    const meta = raw.meta && typeof raw.meta === 'object' ? raw.meta : {}

    const nextMeta = {
      title: currentTitle || String(meta.title || 'Ohne Titel'),
      description: String(meta.description || '')
    }
    if (meta.image) nextMeta.image = String(meta.image)
    if (meta.robots) nextMeta.robots = String(meta.robots)

    const nextPage = {
      meta: nextMeta,
      content: {
        blocks: readBlockList(currentList)
      }
    }

    textarea.value = JSON.stringify(nextPage, null, 4)
  }

  function setMode(mode) {
    currentMode = mode

    if (mode === 'json') {
      syncToTextarea()
      shell.classList.remove('is-active')
      if (jsonWrap) jsonWrap.classList.remove('is-hidden')
      modeButtons.forEach(function (b) {
        b.classList.toggle('primary', b.dataset.beMode === 'json')
      })
      return
    }

    page = normalizePage(parseJson(textarea.value || '{}', {}), titleInput ? titleInput.value : 'Neue Seite')
    renderVisualEditor(page)
    shell.classList.add('is-active')
    if (jsonWrap) jsonWrap.classList.add('is-hidden')
    modeButtons.forEach(function (b) {
      b.classList.toggle('primary', b.dataset.beMode === 'visual')
    })
  }

  renderVisualEditor(page)
  if (jsonWrap) jsonWrap.classList.add('is-hidden')
  shell.classList.add('is-active')
  modeButtons.forEach(function (b) {
    b.classList.toggle('primary', b.dataset.beMode === 'visual')
  })

  modeButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      setMode(btn.dataset.beMode || 'visual')
    })
  })

  form.addEventListener('submit', function () {
    if (currentMode === 'visual') {
      syncToTextarea()
    }
  })
}

  document.querySelectorAll('[data-block-editor]').forEach(initEditor)

  // Auto-Resize fuer Block-Editor Textareas
  function autoResize(ta) {
    ta.style.height = 'auto'
    ta.style.height = Math.max(60, ta.scrollHeight + 2) + 'px'
  }
  function initAutoResize() {
    document.querySelectorAll('.be-block-body textarea').forEach(function (ta) {
      autoResize(ta)
      ta.addEventListener('input', function () { autoResize(ta) })
    })
  }
  initAutoResize()
  var obs = new MutationObserver(function () { setTimeout(initAutoResize, 50) })
  document.querySelectorAll('.be-list').forEach(function (el) {
    obs.observe(el, { childList: true, subtree: true })
  })

  // beforeunload-Warnung bei ungespeicherten Aenderungen
  let dirty = false
  document.addEventListener('input', function () { dirty = true })
  document.addEventListener('change', function () { dirty = true })
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function () { dirty = false })
  })
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault() }
  })
})