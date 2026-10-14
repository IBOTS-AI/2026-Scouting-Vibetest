(() => {
  const table = document.getElementById('pitDisplayTable');
  if (!table) return;
  const body = table.tBodies[0], rows = [...body.rows];
  const sort = document.getElementById('pitDisplaySort');
  const direction = document.getElementById('pitDisplayDirection');
  const search = document.getElementById('pitTeamSearch');
  const suggestions = document.getElementById('pitTeamSuggestions');
  const selections = document.getElementById('pitTeamSelections');
  const combo = document.getElementById('pitTeamCombo');
  const teams = [...new Set(rows.map(row => row.dataset.number))].sort((a, b) => Number(a) - Number(b));
  const selected = new Set();
  let candidates = [], active = 0;
  const filters = [...document.querySelectorAll('#pitDisplayControls [data-filter]')];
  let ascending = true;
  const key = value => value.replace(/_([a-z])/g, (_, char) => char.toUpperCase());
  const value = (row, field) => row.dataset[key(field)] ?? '';
  function render() {
    let shown = 0;
    rows.sort((a, b) => {
      const av = value(a, sort.value), bv = value(b, sort.value);
      if (av === '' || bv === '') return av === bv ? Number(a.dataset.number) - Number(b.dataset.number) : av === '' ? 1 : -1;
      const diff = sort.value === 'name' ? av.localeCompare(bv, undefined, { sensitivity: 'base' }) : Number(av) - Number(bv);
      return (ascending ? diff : -diff) || Number(a.dataset.number) - Number(b.dataset.number);
    });
    for (const row of rows) {
      const matches = (!selected.size || selected.has(row.dataset.number)) && filters.every(input => {
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
  sort.addEventListener('change', render);
  direction.addEventListener('click', () => { ascending = !ascending; render(); });
  function closeSuggestions() {
    suggestions.hidden = true; search.setAttribute('aria-expanded', 'false'); search.removeAttribute('aria-activedescendant');
  }
  function showSuggestions(reset = true) {
    const typed = search.value.trim();
    candidates = teams.filter(number => !selected.has(number) && number.startsWith(typed));
    if (reset) active = Math.max(0, candidates.indexOf(typed));
    active = Math.max(0, Math.min(active, candidates.length - 1));
    suggestions.replaceChildren();
    candidates.forEach((number, index) => {
      const option = document.createElement('button'); option.type = 'button'; option.id = `pit-team-option-${number}`;
      option.setAttribute('role', 'option'); option.setAttribute('aria-selected', String(index === active)); option.tabIndex = -1; option.textContent = number;
      option.addEventListener('mousedown', event => event.preventDefault());
      option.addEventListener('click', () => addTeam(number)); suggestions.append(option);
    });
    if (!candidates.length) { const empty = document.createElement('div'); empty.textContent = 'No matching teams'; suggestions.append(empty); }
    suggestions.hidden = false; search.setAttribute('aria-expanded', 'true');
    if (candidates[active]) search.setAttribute('aria-activedescendant', `pit-team-option-${candidates[active]}`); else search.removeAttribute('aria-activedescendant');
  }
  function showSelections() {
    selections.replaceChildren();
    [...selected].sort((a, b) => Number(a) - Number(b)).forEach(number => {
      const chip = document.createElement('button'); chip.type = 'button'; chip.textContent = `${number} ×`; chip.setAttribute('aria-label', `Remove team ${number}`);
      chip.addEventListener('click', () => { selected.delete(number); showSelections(); render(); }); selections.append(chip);
    });
  }
  function addTeam(number) {
    selected.add(number); search.value = ''; showSelections(); render(); closeSuggestions(); search.focus();
  }
  search.addEventListener('focus', () => showSuggestions());
  search.addEventListener('click', () => showSuggestions());
  search.addEventListener('input', () => showSuggestions());
  search.addEventListener('blur', closeSuggestions);
  search.addEventListener('keydown', event => {
    if (event.key === 'Escape') { closeSuggestions(); return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (suggestions.hidden) showSuggestions();
      else { active += event.key === 'ArrowDown' ? 1 : -1; showSuggestions(false); }
    }
    if ((event.key === 'Enter' || (event.key === 'Tab' && search.value.trim())) && !suggestions.hidden && candidates[active]) {
      if (event.key === 'Enter') event.preventDefault();
      addTeam(candidates[active]);
    }
  });
  document.addEventListener('click', event => { if (!combo.contains(event.target)) closeSuggestions(); });
  filters.forEach(input => input.addEventListener('input', render));
  document.getElementById('pitDisplayReset').addEventListener('click', () => {
    sort.value = 'number'; ascending = true; selected.clear(); search.value = ''; showSelections(); closeSuggestions(); filters.forEach(input => { input.value = ''; }); render();
  });
  render();
})();
