import {crudFormQuery} from './formFunctions'
import sortableFunction from './sortable'
import {listComponentRequest} from './asyncFunctions'

export default (
  creatable = false,
  sortable = false,
  reindex = false,
  async = false,
  asyncUrl = '',
) => ({
  actionsOpen: false,
  lastRow: null,
  table: null,
  async: async,
  asyncUrl: asyncUrl,
  sortable: sortable,
  creatable: creatable,
  reindex: reindex,
  loading: false,
  init() {
    this.table = this.$root.querySelector('table')
    const removeAfterClone = this.table?.dataset?.removeAfterClone
    const tbody = this.table?.querySelector('tbody')
    const tfoot = this.table?.querySelector('tfoot')

    if (tfoot !== null && tfoot !== undefined) {
      tfoot.classList.remove('hidden')
    }

    this.lastRow = tbody?.lastElementChild?.cloneNode(true)

    if (this.creatable || removeAfterClone) {
      tbody?.lastElementChild?.remove()
    }

    if (this.reindex && this.table) {
      this.resolveReindex()
    }

    if (this.sortable && this.table) {
      sortableFunction(
        this.table?.dataset?.sortableUrl ?? null,
        this.table?.dataset?.sortableGroup ?? null,
        tbody,
        this.table?.dataset?.sortableEvents ?? null,
        this.table?.dataset,
      ).init(() => {
        if (this.reindex) {
          this.resolveReindex()
        }
      })
    }
  },
  add(force = false) {
    if (!this.creatable && !force) {
      return
    }

    const total = this.table.querySelectorAll('tbody > tr').length
    const limit = this.table.dataset?.creatableLimit

    if (limit && parseInt(total) >= parseInt(limit)) {
      return
    }

    this.table.querySelector('tbody').appendChild(this.lastRow.cloneNode(true))

    if (!force && this.reindex) {
      this.resolveReindex()
    }
  },
  remove() {
    this.$el.closest('tr').remove()
    if (this.reindex) {
      this.resolveReindex()
    }
  },
  resolveReindex() {
    if (!this.table) {
      return
    }

    function findRoot(element) {
      let parent = element.parentNode.closest('table')

      if (parent) {
        return findRoot(parent)
      }

      return element
    }

    let table = findRoot(this.table)

    this.$nextTick(() => {
      MoonShine.iterable.reindex(table, 'tbody > tr:not(tr tr)', 'tr')
    })
  },
  asyncFormRequest() {
    const urlObject = new URL(this.$el.getAttribute('action'))
    let urlSeparator = urlObject.search === '' ? '?' : '&'

    this.asyncUrl =
      urlObject.href + urlSeparator + crudFormQuery(this.$el.querySelectorAll('[name]'))

    this.asyncRequest()
  },
  asyncRequest() {
    listComponentRequest(this, this.$root?.dataset?.pushstate)
  },
  asyncRowRequest(key, index) {
    const t = this
    const tr = this.table.querySelector('[data-row-key="' + key + '"]')

    if (tr === null) {
      return
    }

    axios
      .get(t.asyncUrl + `&_key=${key}&_index=${index}`)
      .then(response => {
        tr.outerHTML = response.data
      })
      .catch(error => {})
  },
  actions(type, id) {
    let all = this.$root.querySelector('.' + id + '-actionsAllChecked')

    if (all === null) {
      return
    }

    let checkboxes = this.$root.querySelectorAll('.' + id + '-tableActionRow')
    let ids = document.querySelectorAll(
      '.hidden-ids[data-for-component=' + this.table.getAttribute('data-name') + ']',
    )
    let bulkButtons = document.querySelectorAll(
      '[data-button-type=bulk-button][data-for-component=' +
        this.table.getAttribute('data-name') +
        ']',
    )

    //TODO Delete this block after updating the HiddenIds component
    if (ids.length === 0) {
      ids = document.querySelectorAll('.hidden-ids:not([data-for-component])')
    }

    ids.forEach(function (value) {
      value.innerHTML = ''
    })

    let values = []

    for (let i = 0, n = checkboxes.length; i < n; i++) {
      if (type === 'all') {
        checkboxes[i].checked = all.checked
      }

      if (checkboxes[i].checked && checkboxes[i].value) {
        values.push(checkboxes[i].value)
      }
    }

    for (let i = 0, n = ids.length; i < n; i++) {
      values.forEach(function (value) {
        ids[i].insertAdjacentHTML(
          'beforeend',
          `<input type="hidden" name="ids[]" value="${value}"/>`,
        )
      })
    }

    for (let i = 0, n = bulkButtons.length; i < n; i++) {
      let url = bulkButtons[i].getAttribute('href')
      if (!url) {
        continue
      }

      const urlObject = new URL(url)
      let urlSeparator = urlObject.search === '' ? '?' : '&'
      urlObject.searchParams.delete('ids[]')

      const addIds = []
      values.forEach(function (value) {
        addIds.push('ids[]=' + value)
      })

      url = urlObject.href + urlSeparator + addIds.join('&')
      bulkButtons[i].setAttribute('href', url)
    }

    this.actionsOpen = !!(all.checked || values.length)
  },
  rowClickAction(event) {
    const isIgnoredElement = event
      .composedPath()
      .some(
        path =>
          path instanceof HTMLAnchorElement ||
          path instanceof HTMLButtonElement ||
          path instanceof HTMLInputElement ||
          path instanceof HTMLLabelElement,
      )

    if (isIgnoredElement || window.getSelection()?.toString()) {
      return
    }

    const rowElement = this.$el.parentNode

    switch (this.table.dataset.clickAction) {
      case 'detail':
        rowElement.querySelector('.detail-button')?.click()
        break
      case 'edit':
        rowElement.querySelector('.edit-button')?.click()
        break
      case 'select':
        rowElement.querySelector('.tableActionRow[type="checkbox"]')?.click()
        break
    }
  },
})
