document.addEventListener('DOMContentLoaded', function () {
  const navToggle = document.querySelector('.nav-toggle')
  const navWrap = document.querySelector('.nav-menu-wrap')
  const submenuButtons = Array.from(document.querySelectorAll('.nav-subtoggle'))

  if (navToggle && navWrap) {
    navToggle.addEventListener('click', function () {
      const open = navToggle.getAttribute('aria-expanded') === 'true'
      navToggle.setAttribute('aria-expanded', open ? 'false' : 'true')
      navWrap.classList.toggle('is-open', !open)
    })
  }

  function closeAllSubmenus(exceptItem) {
    document.querySelectorAll('.nav-item.has-children[data-open="true"]').forEach(function (item) {
      if (exceptItem && item === exceptItem) return
      item.removeAttribute('data-open')
      const button = item.querySelector(':scope > .nav-link-row .nav-subtoggle')
      if (button) button.setAttribute('aria-expanded', 'false')
    })
  }

  submenuButtons.forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault()
      event.stopPropagation()

      const item = button.closest('.nav-item.has-children')
      if (!item) return

      const open = item.getAttribute('data-open') === 'true'
      closeAllSubmenus(open ? null : item)

      if (open) {
        item.removeAttribute('data-open')
        button.setAttribute('aria-expanded', 'false')
        return
      }

      item.setAttribute('data-open', 'true')
      button.setAttribute('aria-expanded', 'true')
    })
  })

  document.addEventListener('click', function (event) {
    if (event.target.closest('nav')) return
    closeAllSubmenus(null)
  })

  // Escape-Taste schliesst Menues und Suche
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllSubmenus(null)
      if (navToggle && navWrap && navWrap.classList.contains('is-open')) {
        navToggle.setAttribute('aria-expanded', 'false')
        navWrap.classList.remove('is-open')
        navToggle.focus()
      }
      var sr = document.getElementById('search-results')
      if (sr) sr.classList.remove('is-open')
    }
  })

  // Suche
  var searchInput = document.getElementById('search-input')
  var searchResults = document.getElementById('search-results')
  var searchData = null

  if (searchInput && searchResults) {
    var debounceTimer = null

    searchInput.addEventListener('input', function () {
      clearTimeout(debounceTimer)
      var query = searchInput.value.trim().toLowerCase()

      if (query.length < 2) {
        searchResults.classList.remove('is-open')
        searchResults.innerHTML = ''
        return
      }

      debounceTimer = setTimeout(function () {
        if (!searchData) {
          // Statischer Index zuerst (kein PHP-Bootstrap), API als Fallback
          fetch('/search-index.json')
            .then(function (r) {
              if (!r.ok) throw new Error('no static index')
              return r.json()
            })
            .catch(function () {
              return fetch('/api.php?a=search_index').then(function (r) { return r.json() })
            })
            .then(function (d) {
              if (d && d.ok) searchData = d.data.pages
              renderSearchResults(query)
            })
            .catch(function () {})
        } else {
          renderSearchResults(query)
        }
      }, 200)
    })

    function renderSearchResults(query) {
      if (!searchData) return
      var matches = searchData.filter(function (p) {
        return p.title.toLowerCase().includes(query) ||
          p.description.toLowerCase().includes(query) ||
          p.text.toLowerCase().includes(query)
      }).slice(0, 8)

      if (matches.length === 0) {
        searchResults.innerHTML = '<div class="search-no-results">Keine Ergebnisse</div>'
      } else {
        searchResults.innerHTML = matches.map(function (p) {
          var href = p.slug === 'home' ? '/' : '/' + p.slug
          return '<a class="search-result-item" href="' + href + '">'
            + '<div class="search-result-title">' + escapeSearchHtml(p.title) + '</div>'
            + '<div class="search-result-desc">' + escapeSearchHtml(p.description) + '</div>'
            + '</a>'
        }).join('')
      }
      searchResults.classList.add('is-open')
    }

    function escapeSearchHtml(str) {
      return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    }

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.search-wrap')) {
        searchResults.classList.remove('is-open')
      }
    })
  }
})