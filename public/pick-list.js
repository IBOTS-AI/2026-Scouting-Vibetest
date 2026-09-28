(() => {
  const list = document.getElementById('pickCards');
  const form = document.getElementById('pickOrderForm');
  if (!list || !form) return;
  let dragging = null;
  for (const handle of list.querySelectorAll('.pick-drag')) {
    handle.addEventListener('dragstart', event => {
      dragging = handle.closest('.pick-card');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', dragging.dataset.team);
      dragging.classList.add('dragging');
    });
    handle.addEventListener('dragend', () => {
      dragging?.classList.remove('dragging');
      dragging = null;
    });
  }
  list.addEventListener('dragover', event => {
    const target = event.target.closest('.pick-card');
    if (!dragging || !target || target === dragging || target.dataset.picked === '1') return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
  });
  list.addEventListener('drop', event => {
    const target = event.target.closest('.pick-card');
    if (!dragging || !target || target === dragging || target.dataset.picked === '1') return;
    event.preventDefault();
    const before = event.clientY < target.getBoundingClientRect().top + target.getBoundingClientRect().height / 2;
    list.insertBefore(dragging, before ? target : target.nextSibling);
    document.getElementById('pickOrder').value = JSON.stringify([...list.querySelectorAll('.pick-card')].map(card => Number(card.dataset.team)));
    form.requestSubmit();
  });
})();
