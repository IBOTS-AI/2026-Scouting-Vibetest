(() => {
  const table = document.getElementById('teamDashboard');
  if (!table) return;
  const body = table.tBodies[0];
  const filters = [...table.querySelectorAll('.column-filter')];
  let sortColumn = 0;
  let ascending = true;
  const numeric = new Set([0, 2, 3, 4, 5, 6]);
  const value = (row, col) => row.cells[col].dataset.value || '';
  function accepts(cell, term, col) {
    if (!term) return true;
    const raw = value(cell, col);
    if (!raw) return false;
    if (numeric.has(col)) {
      const n = Number(raw);
      const comparison = term.match(/^(>=|<=|>|<|=)\s*(\d+(?:\.\d+)?)$/);
      if (comparison) {
        const x = Number(comparison[2]);
        return ({'>=':n>=x,'<=':n<=x,'>':n>x,'<':n<x,'=':n===x})[comparison[1]];
      }
      const range = term.match(/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)$/);
      if (range) return n >= Number(range[1]) && n <= Number(range[2]);
    }
    return raw.toLowerCase().includes(term.toLowerCase());
  }
  function render() {
    const rows = [...body.rows];
    rows.sort((a, b) => {
      const av = value(a, sortColumn), bv = value(b, sortColumn);
      if (!av) return bv ? 1 : 0;
      if (!bv) return -1;
      const cmp = numeric.has(sortColumn) ? Number(av) - Number(bv) : av.localeCompare(bv);
      return (ascending ? 1 : -1) * cmp;
    });
    for (const row of rows) {
      row.hidden = filters.some(input => !accepts(row, input.value.trim(), Number(input.dataset.col)));
      body.append(row);
    }
    for (const button of table.querySelectorAll('.sort-head')) {
      button.closest('th').setAttribute('aria-sort', Number(button.dataset.col) === sortColumn ? (ascending ? 'ascending' : 'descending') : 'none');
    }
  }
  for (const input of filters) input.addEventListener('input', render);
  for (const button of table.querySelectorAll('.sort-head')) button.addEventListener('click', () => {
    const col = Number(button.dataset.col);
    ascending = col === sortColumn ? !ascending : true;
    sortColumn = col;
    render();
  });
  render();
})();
