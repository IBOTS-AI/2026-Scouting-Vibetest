(() => {
  const form = document.querySelector('.dynamic-pit-form');
  if (!form) return;
  const meta = form.querySelector('[data-robot-meta]');
  const custom = form.querySelector('[data-robot-meta-other]');
  if (meta && custom) {
    const update = () => { custom.hidden = meta.value !== 'Other'; custom.querySelector('input').required = meta.value === 'Other'; };
    meta.addEventListener('change', update); update();
  }
  form.querySelectorAll('input[type="file"]').forEach(input => input.addEventListener('change', () => {
    input.setCustomValidity(input.files[0]?.size > 4 * 1024 * 1024 ? 'Choose an image up to 4 MB.' : '');
    input.reportValidity();
  }));
})();
