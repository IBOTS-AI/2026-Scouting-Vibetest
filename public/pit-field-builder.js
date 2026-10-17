(() => {
  const form = document.getElementById('pitFieldBuilder');
  if (!form) return;
  const list = document.getElementById('pitFieldList');
  let fields = JSON.parse(document.getElementById('pitFieldData').textContent);
  const original = new Map(fields.map(f => [f.id, f.type]));
  const types = {dropdown:'Dropdown', number:'Number', text:'Text input', textarea:'Long text', multiselect:'Multiple select', tags:'Tags', image:'Image'};
  const hasChoices = type => ['dropdown','multiselect','tags'].includes(type);
  const button = (text, action, disabled = false) => {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = text; b.disabled = disabled;
    b.addEventListener('click', action); return b;
  };
  function render(focusId) {
    list.replaceChildren();
    fields.forEach((field, index) => {
      const panel = document.createElement('details'); panel.className = 'admin-rollup pit-field-editor'; panel.open = field.id === focusId;
      panel.dataset.fieldId = field.id;
      const summary = document.createElement('summary');
      const title = document.createElement('span'); title.textContent = `${index + 1}. ${field.label}`;
      const kind = document.createElement('small'); kind.textContent = types[field.type]; summary.append(title, kind); panel.append(summary);
      const body = document.createElement('div'); body.className = 'pit-editor-body';
      const row = document.createElement('div'); row.className = 'admin-field-row';
      const label = document.createElement('label'); label.textContent = 'Field name';
      const input = document.createElement('input'); input.value = field.label; input.maxLength = 100; input.required = true;
      input.addEventListener('input', () => { input.setCustomValidity(input.value.trim() ? '' : 'Enter a field name.'); field.label = input.value; title.textContent = `${index + 1}. ${field.label}`; }); label.append(input);
      const typeLabel = document.createElement('label'); typeLabel.textContent = 'Input type';
      const type = document.createElement('select');
      Object.entries(types).forEach(([value, text]) => { const o = document.createElement('option'); o.value = value; o.textContent = text; type.append(o); });
      type.value = field.type; type.disabled = field.id === 'robot_photo'; typeLabel.append(type); row.append(label, typeLabel); body.append(row);
      const optionsLabel = document.createElement('label'); optionsLabel.textContent = 'Choices (one per line)';
      const options = document.createElement('textarea'); options.value = field.options.join('\n'); options.rows = 5; options.maxLength = 4100;
      optionsLabel.append(options); optionsLabel.hidden = !hasChoices(field.type); options.required = hasChoices(field.type);
      const validateOptions = () => { field.options = options.value.split(/\r?\n/).map(v => v.trim()).filter(Boolean); const values = field.options; options.setCustomValidity(!hasChoices(field.type) ? '' : !values.length ? 'Add at least one choice.' : values.length > 50 ? 'Use up to 50 choices.' : values.some(v => v.length > 80) ? 'Keep each choice under 81 characters.' : new Set(values.map(v => v.toLowerCase())).size !== values.length ? 'Remove duplicate choices.' : ''); };
      options.addEventListener('input', validateOptions);
      type.addEventListener('change', () => { field.type = type.value; kind.textContent = types[type.value]; optionsLabel.hidden = !hasChoices(type.value); options.required = hasChoices(type.value); validateOptions(); });
      body.append(optionsLabel);
      if (field.id === 'robot_photo') { const note = document.createElement('small'); note.textContent = 'This image supplies robot thumbnails throughout the app.'; body.append(note); }
      const actions = document.createElement('div'); actions.className = 'pit-builder-actions';
      actions.append(button('↑ Move up', () => { [fields[index-1],fields[index]] = [fields[index],fields[index-1]]; render(field.id); }, index === 0),
        button('↓ Move down', () => { [fields[index+1],fields[index]] = [fields[index],fields[index+1]]; render(field.id); }, index === fields.length-1),
        button('Remove field', () => { if (!confirm(`Remove “${field.label}” from the pit form? Saved values will be retained.`)) return; fields.splice(index,1); render(); }));
      body.append(actions); panel.append(body); list.append(panel);
    });
    if (!fields.length) { const empty = document.createElement('p'); empty.textContent = 'No fields. Add a field to begin.'; list.append(empty); }
    document.getElementById('pitFieldAdd').disabled = fields.length >= 50;
    if (focusId) { const target = [...list.children].find(n => n.dataset.fieldId === focusId); target?.querySelector('input')?.focus(); }
  }
  document.getElementById('pitFieldAdd').addEventListener('click', () => {
    const random = Array.from(crypto.getRandomValues(new Uint8Array(12)), n => n.toString(16).padStart(2,'0')).join('');
    const id = `custom_${random}`; fields.push({id,label:'New field',type:'text',options:[]}); render(id);
  });
  form.addEventListener('invalid', e => { const panel = e.target.closest('details'); if (panel) panel.open = true; }, true);
  form.addEventListener('submit', e => {
    const changed = fields.filter(f => original.has(f.id) && original.get(f.id) !== f.type);
    if (changed.length && !confirm('Change input types for: ' + changed.map(f => f.label).join(', ') + '? Existing values are retained until a scout saves a new value for that field.')) { e.preventDefault(); return; }
    document.getElementById('pitFieldSchema').value = JSON.stringify(fields);
  });
  render();
})();
