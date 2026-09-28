(() => {
  const table = document.getElementById('teamDashboard');
  if (!table) return;
  const body = table.tBodies[0];
  const status = document.getElementById('dashboardControlsStatus');
  let sortColumn = 0;
  let ascending = true;
  const numeric = new Set([0, 2, 3, 4, 5, 6]);
  const value = (row, col) => row.cells[col].dataset.value || '';
  function render() {
    const rows = [...body.rows];
    rows.sort((a, b) => {
      const av = value(a, sortColumn), bv = value(b, sortColumn);
      if (!av) return bv ? 1 : 0;
      if (!bv) return -1;
      const cmp = numeric.has(sortColumn) ? Number(av) - Number(bv) : av.localeCompare(bv);
      return (ascending ? 1 : -1) * cmp;
    });
    for (const row of rows) body.append(row);
    for (const button of table.querySelectorAll('.sort-head')) {
      button.closest('th').setAttribute('aria-sort', Number(button.dataset.col) === sortColumn ? (ascending ? 'ascending' : 'descending') : 'none');
    }
    if (status) status.textContent = `${rows.length} teams shown. Sorting ready.`;
  }
  for (const button of table.querySelectorAll('.sort-head')) button.addEventListener('click', () => {
    const col = Number(button.dataset.col);
    ascending = col === sortColumn ? !ascending : true;
    sortColumn = col;
    render();
  });
  render();
})();
