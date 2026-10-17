// Normalize editable color codes before sending them to the server.
function parsePitColor(value) {
  const text = value.trim().toLowerCase();
  if (/^#?[0-9a-f]{6}$/.test(text)) return '#' + text.replace('#','');
  if (/^#?[0-9a-f]{3}$/.test(text)) return '#' + [...text.replace('#','')].map(c => c+c).join('');
  const match = text.match(/^(rgb|hsv|hsl)\(\s*([^()]+)\s*\)$/);
  if (!match) return null;
  const parts = match[2].trim().split(/[\s,]+/);
  if (parts.length !== 3 || parts.some(p => !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)%?$/.test(p))) return null;
  const values = parts.map(parseFloat); let rgb;
  if (match[1] === 'rgb') {
    if (parts.some((p,i) => values[i] < 0 || values[i] > (p.endsWith('%') ? 100 : 255))) return null;
    rgb = values.map((v,i) => parts[i].endsWith('%') ? v*255/100 : v);
  } else {
    if (parts[0].endsWith('%') || values[0] < 0 || values[0] > 360 || values[1] < 0 || values[1] > 100 || values[2] < 0 || values[2] > 100) return null;
    const [h,s,v] = [values[0]/60, values[1]/100, values[2]/100];
    const c = match[1] === 'hsv' ? v*s : (1-Math.abs(2*v-1))*s;
    const x = c*(1-Math.abs(h%2-1)); const m = match[1] === 'hsv' ? v-c : v-c/2;
    rgb = (h < 1 ? [c,x,0] : h < 2 ? [x,c,0] : h < 3 ? [0,c,x] : h < 4 ? [0,x,c] : h < 5 ? [x,0,c] : [c,0,x]).map(n => (n+m)*255);
  }
  return '#' + rgb.map(n => Math.round(n).toString(16).padStart(2,'0')).join('');
}
(() => {
  const form = document.getElementById('pitFieldBuilder');
  if (!form) return;
  const list = document.getElementById('pitFieldList');
  let fields = JSON.parse(document.getElementById('pitFieldData').textContent);
  const palette = JSON.parse(document.getElementById('pitFieldPalette')?.textContent || '{}');
  const original = new Map(fields.map(f => [f.id, f.type]));
  const types = {dropdown:'Dropdown', number:'Number', text:'Text input', textarea:'Long text', multiselect:'Multiple select', tags:'Tags', image:'Image'};
  const hasChoices = type => ['dropdown','multiselect','tags'].includes(type);
  const status = document.getElementById('pitBuilderStatus');
  const button = (text, action) => {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = text;
    b.addEventListener('click', action); return b;
  };
  function updateOrder() {
    fields = [...list.querySelectorAll('.pit-field-editor')].map(panel => fields.find(f => f.id === panel.dataset.fieldId));
    list.querySelectorAll('.pit-field-editor').forEach((panel,i) => { panel.querySelector('.pit-field-title').textContent = `${i+1}. ${fields[i].label}`; });
  }
  function attachHandle(handle, panel, field) {
    let drag = null, frame = 0;
    const position = y => {
      const before = [...list.querySelectorAll('.pit-field-editor')].find(item => item !== panel && y < item.getBoundingClientRect().top + item.getBoundingClientRect().height/2);
      if (panel.nextElementSibling !== (before || null)) { list.insertBefore(panel, before || null); if (drag) handle.setPointerCapture(drag.pointerId); handle.focus({preventScroll:true}); }
    };
    const scroll = () => {
      if (!drag?.active) return;
      const delta = drag.y < 65 ? -12 : drag.y > innerHeight-65 ? 12 : 0;
      if (delta) { window.scrollBy(0,delta); position(drag.y); }
      frame = requestAnimationFrame(scroll);
    };
    const escapeDrag = e => { if (e.key === 'Escape' && drag) { e.preventDefault(); finish(true); } };
    const finish = cancel => {
      if (!drag) return;
      cancelAnimationFrame(frame);document.removeEventListener('keydown',escapeDrag);
      if (cancel) drag.order.forEach(node => list.append(node));
      panel.classList.remove('is-dragging');panel.open = drag.open;
      const pointerId = drag.pointerId, wasActive = drag.active; drag = null;
      if (handle.hasPointerCapture(pointerId)) handle.releasePointerCapture(pointerId);
      updateOrder();
      if (wasActive && status) status.textContent = cancel ? 'Reorder canceled.' : `${field.label} moved to position ${fields.indexOf(field)+1}. Save the configuration to apply.`;
    };
    handle.addEventListener('pointerdown', e => {
      if (e.button !== 0 || drag) return;
      e.preventDefault();e.stopPropagation();handle.focus({preventScroll:true});
      drag = {pointerId:e.pointerId, startY:e.clientY, y:e.clientY, active:false, open:panel.open, order:[...list.children]};
      handle.setPointerCapture(e.pointerId);document.addEventListener('keydown',escapeDrag);
    });
    handle.addEventListener('pointermove', e => {
      if (!drag || drag.pointerId !== e.pointerId) return;
      drag.y = e.clientY;
      if (!drag.active && Math.abs(e.clientY-drag.startY) < 5) return;
      if (!drag.active) { drag.active = true; panel.open = false; panel.classList.add('is-dragging'); frame = requestAnimationFrame(scroll); }
      e.preventDefault();position(e.clientY);
    });
    handle.addEventListener('pointerup', () => finish(false));
    handle.addEventListener('pointercancel', () => finish(true));
    handle.addEventListener('lostpointercapture', () => { if (drag) finish(true); });
    handle.addEventListener('click', e => {e.preventDefault();e.stopPropagation();});
    handle.addEventListener('dragstart', e => e.preventDefault());
    handle.addEventListener('keydown', e => {
      if (e.key === 'Escape' && drag) { e.preventDefault();finish(true);return; }
      if (!['ArrowUp','ArrowDown','Home','End'].includes(e.key) || drag) return;
      e.preventDefault();e.stopPropagation();
      const index = fields.indexOf(field);
      if (e.key === 'ArrowUp' && panel.previousElementSibling) list.insertBefore(panel,panel.previousElementSibling);
      if (e.key === 'ArrowDown' && panel.nextElementSibling) list.insertBefore(panel,panel.nextElementSibling.nextElementSibling);
      if (e.key === 'Home') list.prepend(panel);
      if (e.key === 'End') list.append(panel);
      updateOrder();handle.focus();if (status && index !== fields.indexOf(field)) status.textContent = `${field.label} moved to position ${fields.indexOf(field)+1}. Save the configuration to apply.`;
    });
  }
  function render(focusId) {
    const openIds = new Set([...list.querySelectorAll('details[open]')].map(p => p.dataset.fieldId));
    list.replaceChildren();
    fields.forEach(field => {
      const panel = document.createElement('details'); panel.className = 'admin-rollup pit-field-editor'; panel.open = field.id === focusId || openIds.has(field.id);
      panel.dataset.fieldId = field.id;
      const summary = document.createElement('summary');
      const handle = document.createElement('button');handle.type = 'button';handle.className = 'pit-field-handle';handle.textContent = '⠿';handle.setAttribute('aria-label',`Drag to reorder ${field.label}`);handle.title = 'Drag to reorder. Use arrow keys to move with the keyboard.';
      const title = document.createElement('span'); title.className = 'pit-field-title';title.textContent = `${fields.indexOf(field)+1}. ${field.label}`;
      const kind = document.createElement('small'); kind.textContent = types[field.type]; summary.append(handle,title,kind);panel.append(summary);attachHandle(handle,panel,field);
      const body = document.createElement('div'); body.className = 'pit-editor-body';
      const row = document.createElement('div'); row.className = 'admin-field-row';
      const label = document.createElement('label'); label.textContent = 'Field name';
      const input = document.createElement('input');input.value = field.label;input.maxLength = 100;input.required = true;
      input.addEventListener('input', () => {input.setCustomValidity(input.value.trim() ? '' : 'Enter a field name.');field.label = input.value;title.textContent = `${fields.indexOf(field)+1}. ${field.label}`;handle.setAttribute('aria-label',`Drag to reorder ${field.label}`);});label.append(input);
      const typeLabel = document.createElement('label');typeLabel.textContent = 'Input type';
      const type = document.createElement('select');
      Object.entries(types).forEach(([value,text]) => {const o = document.createElement('option');o.value = value;o.textContent = text;type.append(o);});
      type.value = field.type;type.disabled = field.id === 'robot_photo';typeLabel.append(type);row.append(label,typeLabel);body.append(row);
      const optionsLabel = document.createElement('label');optionsLabel.textContent = 'Choices (one per line)';
      const options = document.createElement('textarea');options.value = field.options.join('\n');options.rows = 5;options.maxLength = 4100;
      optionsLabel.append(options);optionsLabel.hidden = !hasChoices(field.type);options.required = hasChoices(field.type);
      const colors = document.createElement('fieldset');colors.className = 'pit-choice-colors';
      const drawColors = () => {
        colors.hidden = !hasChoices(field.type);colors.replaceChildren();
        const legend = document.createElement('legend');legend.textContent = 'Option colors';colors.append(legend);
        const note = document.createElement('small');note.textContent = 'Pick a color or enter HEX, RGB, HSV, or HSL, for example #33aa66, rgb(51, 170, 102), or hsv(146, 70%, 67%).';colors.append(note);
        if (!hasChoices(field.type)) return;
        field.colors = Object.fromEntries(field.options.map(option => [option,parsePitColor(field.colors && Object.hasOwn(field.colors,option) ? field.colors[option] : '') || parsePitColor(Object.hasOwn(palette,option) ? palette[option] : '') || '#d9f3f0']));
        field.options.forEach(option => {
          const line = document.createElement('div');line.className = 'pit-choice-color-row';
          const name = document.createElement('span');name.className = 'team-tag';name.textContent = option;
          const pickerLabel = document.createElement('label');pickerLabel.textContent = 'Pick color';
          const picker = document.createElement('input');picker.type = 'color';picker.value = field.colors[option];picker.setAttribute('aria-label',`Pick color for ${option}`);pickerLabel.append(picker);
          const codeLabel = document.createElement('label');codeLabel.textContent = 'Color code';
          const code = document.createElement('input');code.type = 'text';code.value = field.colors[option];code.maxLength = 80;code.required = true;code.setAttribute('aria-label',`Color code for ${option}`);codeLabel.append(code);
          const preview = hex => {name.style.backgroundColor = hex;name.style.borderColor = hex;const channels = [1,3,5].map(i => parseInt(hex.slice(i,i+2),16));name.style.color = (channels[0]*299+channels[1]*587+channels[2]*114)/1000 > 150 ? '#172438' : '#ffffff';};preview(picker.value);
          code.addEventListener('input', () => {const hex = parsePitColor(code.value);code.setCustomValidity(hex ? '' : 'Enter HEX, rgb(0–255, 0–255, 0–255), hsv(0–360, 0–100%, 0–100%), or hsl(0–360, 0–100%, 0–100%).');if(hex){field.colors[option] = hex;picker.value = hex;preview(hex);}});
          picker.addEventListener('input', () => {field.colors[option] = picker.value;code.value = picker.value;code.setCustomValidity('');preview(picker.value);});
          line.append(name,pickerLabel,codeLabel);colors.append(line);
        });
      };
      const validateOptions = () => {field.options = options.value.split(/\r?\n/).map(v => v.trim()).filter(Boolean);const values = field.options;options.setCustomValidity(!hasChoices(field.type) ? '' : !values.length ? 'Add at least one choice.' : values.length > 50 ? 'Use up to 50 choices.' : values.some(v => v.length > 80) ? 'Keep each choice under 81 characters.' : new Set(values.map(v => v.toLowerCase())).size !== values.length ? 'Remove duplicate choices.' : '');};
      options.addEventListener('input', () => {validateOptions();drawColors();});
      type.addEventListener('change', () => {field.type = type.value;kind.textContent = types[type.value];optionsLabel.hidden = !hasChoices(type.value);options.required = hasChoices(type.value);validateOptions();drawColors();});
      body.append(optionsLabel,colors);drawColors();
      if(field.id === 'robot_photo'){const note = document.createElement('small');note.textContent = 'This image supplies robot thumbnails throughout the app.';body.append(note);}
      const actions = document.createElement('div');actions.className = 'pit-builder-actions';
      actions.append(button('Remove field', () => {if(!confirm(`Remove “${field.label}” from the pit form? Saved values will be retained.`))return;fields.splice(fields.indexOf(field),1);render();}));
      body.append(actions);panel.append(body);list.append(panel);
    });
    if(!fields.length){const empty = document.createElement('p');empty.textContent = 'No fields. Add a field to begin.';list.append(empty);}
    document.getElementById('pitFieldAdd').disabled = fields.length >= 50;
    if(focusId){const target = [...list.children].find(n => n.dataset.fieldId === focusId);target?.querySelector('input')?.focus();}
  }
  document.getElementById('pitFieldAdd').addEventListener('click', () => {
    const random = Array.from(crypto.getRandomValues(new Uint8Array(12)),n => n.toString(16).padStart(2,'0')).join('');
    const id = `custom_${random}`;fields.push({id,label:'New field',type:'text',options:[]});render(id);
  });
  form.addEventListener('invalid', e => {const panel = e.target.closest('details');if(panel)panel.open = true;},true);
  form.addEventListener('submit', e => {
    const changed = fields.filter(f => original.has(f.id) && original.get(f.id) !== f.type);
    if(changed.length && !confirm('Change input types for: ' + changed.map(f => f.label).join(', ') + '? Existing values are retained until a scout saves a new value for that field.')){e.preventDefault();return;}
    document.getElementById('pitFieldSchema').value = JSON.stringify(fields);
  });
  render();
})();
