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
    chip.className = 'team-tag tag-tone-' + choice.selectedOptions[0].dataset.tone;
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
