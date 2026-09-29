(() => {
  const choice = document.getElementById('pitTagChoice');
  const add = document.getElementById('pitTagAdd');
  const selected = document.getElementById('pitSelectedTags');
  const status = document.getElementById('pitTagsStatus');
  if (!choice || !add || !selected) return;

  add.addEventListener('click', () => {
    const tag = choice.value;
    if (!tag) { status.textContent = 'Choose a tag first.'; return; }
    if ([...selected.querySelectorAll('[data-tag]')].some(chip => chip.dataset.tag.toLowerCase() === tag.toLowerCase())) {
      status.textContent = tag + ' is already selected.'; return;
    }
    if (selected.querySelectorAll('[data-tag]').length >= 12) {
      status.textContent = 'Choose up to 12 tags.'; return;
    }
    const chip = document.createElement('span');
    chip.className = 'team-tag';
    const color = choice.selectedOptions[0].dataset.color || '#d9f3f0';
    const rgb = [1, 3, 5].map(index => parseInt(color.slice(index, index + 2), 16));
    chip.style.background = color;
    chip.style.borderColor = color;
    chip.style.color = (rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114) / 1000 > 150 ? '#172438' : '#ffffff';
    chip.dataset.tag = tag;
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'tags[]'; input.value = tag;
    const label = document.createElement('span'); label.textContent = tag;
    const remove = document.createElement('button');
    remove.type = 'button'; remove.className = 'pit-tag-remove';
    remove.setAttribute('aria-label', 'Remove ' + tag); remove.textContent = '×';
    chip.append(input, label, remove);
    selected.append(chip);
    choice.value = '';
    status.textContent = tag + ' added.';
  });
  selected.addEventListener('click', event => {
    const button = event.target.closest('.pit-tag-remove');
    if (!button) return;
    const chip = button.closest('[data-tag]');
    status.textContent = chip.dataset.tag + ' removed.';
    chip.remove();
  });
})();
