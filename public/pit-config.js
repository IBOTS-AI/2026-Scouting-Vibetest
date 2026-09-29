(() => {
  const form = document.getElementById('pitChoicesForm');
  if (!form) return;
  const original = [...form.querySelectorAll('.config-option[data-original]')].map(row => ({
    key: row.closest('.config-field').dataset.key,
    value: row.dataset.original,
    pit: Number(row.dataset.pitUsage),
    match: Number(row.dataset.matchUsage)
  }));
  const warning = item => `“${item.value}” is used in ${item.pit} pit records and ${item.match} match reports. Removing it from the dropdown will keep those saved records. Continue?`;

  form.addEventListener('click', event => {
    const remove = event.target.closest('.config-remove');
    if (remove) {
      const row = remove.closest('.config-option');
      const group = row.closest('.config-options');
      if (group.children.length === 1) { alert('Keep at least one choice in each dropdown.'); return; }
      const item = original.find(entry => entry.key === row.closest('.config-field').dataset.key && entry.value === row.dataset.original);
      if (item && item.pit + item.match && !confirm(warning(item))) return;
      row.remove();
      return;
    }
    const add = event.target.closest('.config-add');
    if (!add) return;
    const field = add.closest('.config-field');
    const group = field.querySelector('.config-options');
    if (group.children.length >= 30) { alert('Each dropdown supports up to 30 choices.'); return; }
    const row = document.createElement('div'); row.className = 'config-option';
    const label = document.createElement('label');
    const name = document.createElement('input');
    name.name = `choices[${field.dataset.key}][]`; name.required = true;
    name.maxLength = field.dataset.key === 'tags' ? 40 : 80;
    name.placeholder = field.dataset.key === 'tags' ? 'Tag text' : 'Choice text';
    label.append(name); row.append(label);
    if (field.dataset.key === 'tags') {
      const colorLabel = document.createElement('label'); colorLabel.className = 'config-color';
      const caption = document.createElement('span'); caption.textContent = 'Color';
      const color = document.createElement('input'); color.type = 'color'; color.name = 'tag_colors[]'; color.value = '#d9f3f0';
      color.setAttribute('aria-label', 'Tag color'); colorLabel.append(caption, color); row.append(colorLabel);
    }
    const usage = document.createElement('span'); usage.className = 'config-usage'; usage.textContent = 'New'; row.append(usage);
    const button = document.createElement('button'); button.type = 'button'; button.className = 'config-remove'; button.textContent = 'Remove'; row.append(button);
    group.append(row); name.focus();
  });

  form.addEventListener('submit', event => {
    const current = new Map([...form.querySelectorAll('.config-field')].map(field => [field.dataset.key,
      new Set([...field.querySelectorAll('.config-option input[name^="choices["]')].map(input => input.value.trim()))]));
    const removed = original.filter(item => item.pit + item.match && !current.get(item.key).has(item.value));
    if (removed.length && !confirm('These choices are used in saved records:\n\n' + removed.map(item => `${item.key}: ${item.value} — ${item.pit} pit, ${item.match} match`).join('\n') + '\n\nSaved records will be retained. Remove these dropdown choices?')) {
      event.preventDefault(); return;
    }
    document.getElementById('pitChoiceAcknowledged').value = removed.length ? '1' : '0';
  });
})();
