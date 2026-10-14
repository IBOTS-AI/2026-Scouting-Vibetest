(() => {
  const table = document.getElementById('pitDisplayTable');
  if (!table) return;
  const body = table.tBodies[0], rows = [...body.rows];
  const sort = document.getElementById('pitDisplaySort');
  const direction = document.getElementById('pitDisplayDirection');
  const name = document.getElementById('pitDisplayName');
  const filters = [...document.querySelectorAll('#pitDisplayControls [data-filter]')];
  const choices = [...document.querySelectorAll('[data-team-choice]')];
  const entry = document.getElementById('pitTeamEntry');
  const feedback = document.getElementById('pitTeamFeedback');
  let ascending = true;
  const key = value => value.replace(/_([a-z])/g, (_, char) => char.toUpperCase());
  const value = (row, field) => row.dataset[key(field)] ?? '';
  function render() {
    let shown = 0;
    const selected = new Set(choices.filter(input => input.checked).map(input => input.value));
    document.getElementById('pitTeamSummary').textContent = selected.size ? [...selected].join(', ') : 'All teams';
    rows.sort((a, b) => {
      const av = value(a, sort.value), bv = value(b, sort.value);
      if (av === '' || bv === '') return av === bv ? Number(a.dataset.number) - Number(b.dataset.number) : av === '' ? 1 : -1;
      const diff = sort.value === 'name' ? av.localeCompare(bv, undefined, { sensitivity: 'base' }) : Number(av) - Number(bv);
      return (ascending ? diff : -diff) || Number(a.dataset.number) - Number(b.dataset.number);
    });
    for (const row of rows) {
      const matches = (!selected.size || selected.has(row.dataset.number)) && row.dataset.name.toLowerCase().includes(name.value.trim().toLowerCase()) && filters.every(input => {
        if (input.value === '' || !input.validity.valid) return true;
        const raw = value(row, input.dataset.filter);
        return raw !== '' && (input.dataset.bound === 'min' ? Number(raw) >= Number(input.value) : Number(raw) <= Number(input.value));
      });
      row.hidden = !matches;
      if (matches) shown++;
      body.append(row);
    }
    direction.textContent = ascending ? 'Ascending ↑' : 'Descending ↓';
    direction.setAttribute('aria-label', ascending ? 'Sort descending' : 'Sort ascending');
    document.getElementById('pitDisplayStatus').textContent = `${shown} of ${rows.length} teams shown`;
  }
  choices.forEach(input => input.addEventListener('change', render));
  function addTeam() {
    const raw = entry.value.trim();
    const number = /^\d+$/.test(raw) ? String(Number(raw)) : '';
    const choice = choices.find(input => input.value === number);
    if (!choice) { feedback.textContent = 'Choose a team attending this event.'; return; }
    choice.checked = true; entry.value = ''; feedback.textContent = ''; render(); entry.focus();
  }
  document.getElementById('pitTeamAdd').addEventListener('click', addTeam);
  entry.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); addTeam(); } });
  document.getElementById('pitTeamClear').addEventListener('click', () => {
    choices.forEach(input => { input.checked = false; }); entry.value = ''; feedback.textContent = ''; render();
  });
  sort.addEventListener('change', render);
  direction.addEventListener('click', () => { ascending = !ascending; render(); });
  name.addEventListener('input', render);
  filters.forEach(input => input.addEventListener('input', render));
  document.getElementById('pitDisplayReset').addEventListener('click', () => {
    sort.value = 'number'; ascending = true; name.value = ''; choices.forEach(input => { input.checked = false; }); entry.value = ''; feedback.textContent = ''; filters.forEach(input => { input.value = ''; }); render();
  });
  render();
})();
