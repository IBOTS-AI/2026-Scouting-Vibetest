(() => {
  const list = document.getElementById('pickCards');
  const form = document.getElementById('pickOrderForm');
  if (!list || !form) return;
  const cards = () => [...list.querySelectorAll('.pick-card')];
  const order = () => cards().map(card => Number(card.dataset.team));
  const save = () => {
    document.getElementById('pickOrder').value = JSON.stringify(order());
    form.requestSubmit();
  };
  const unpicked = card => card && card.classList.contains('pick-card') && card.dataset.picked === '0' && card.dataset.dnp === '0';
  const targetAt = (element, y, movingCard) => {
    const direct = element?.closest?.('.pick-card');
    if (direct === movingCard) return null;
    if (unpicked(direct)) return direct;
    const available = cards().filter(card => unpicked(card) && card !== movingCard);
    return available.reduce((best, card) => Math.abs(card.getBoundingClientRect().top + card.getBoundingClientRect().height / 2 - y) < Math.abs(best.getBoundingClientRect().top + best.getBoundingClientRect().height / 2 - y) ? card : best, available[0] || null);
  };
  const move = (card, target, y) => {
    if (!unpicked(card) || !unpicked(target) || card === target) return;
    const rect = target.getBoundingClientRect();
    list.insertBefore(card, y < rect.top + rect.height / 2 ? target : target.nextSibling);
  };

  // The entire card is draggable with a mouse. Keep links and controls clickable.
  let mouseTarget = null, dragging = null, startingOrder = '', dropped = false;
  list.addEventListener('pointerdown', event => { if (event.pointerType === 'mouse') mouseTarget = event.target; });
  list.addEventListener('dragstart', event => {
    const card = event.target.closest('.pick-card');
    if (!unpicked(card) || (mouseTarget?.closest('a,input,select,textarea,form,details,summary,button') && !mouseTarget.closest('.pick-drag'))) { event.preventDefault(); return; }
    dragging = card;startingOrder = JSON.stringify(order());dropped = false;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', card.dataset.team);
    card.classList.add('dragging');
  });
  list.addEventListener('dragover', event => {
    if (!dragging) return;
    event.preventDefault();event.dataTransfer.dropEffect = 'move';
    move(dragging, targetAt(event.target, event.clientY, dragging), event.clientY);
  });
  list.addEventListener('drop', event => {
    if (!dragging) return;
    event.preventDefault();dropped = true;
    move(dragging, targetAt(event.target, event.clientY, dragging), event.clientY);
    if (JSON.stringify(order()) !== startingOrder) save();
  });
  list.addEventListener('dragend', () => {
    dragging?.classList.remove('dragging');
    if (dragging && !dropped) {
      const byTeam = new Map(cards().map(card => [Number(card.dataset.team), card]));
      for (const team of JSON.parse(startingOrder)) list.appendChild(byTeam.get(team));
    }
    dragging = null;mouseTarget = null;
  });

  // Pointer events on the handle also support touch and stylus without blocking page scroll.
  for (const handle of list.querySelectorAll('.pick-drag')) {
    let touchCard = null, touchOrder = '';
    handle.addEventListener('keydown', event => {
      if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
      event.preventDefault();
      const card = handle.closest('.pick-card');
      const available = cards().filter(unpicked);
      const index = available.indexOf(card);
      const target = available[index + (event.key === 'ArrowUp' ? -1 : 1)];
      if (!target) return;
      const before = JSON.stringify(order());
      const rect = target.getBoundingClientRect();
      move(card, target, event.key === 'ArrowUp' ? rect.top : rect.bottom);
      if (JSON.stringify(order()) !== before) save();
    });
    handle.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse') return;
      event.preventDefault();touchCard = handle.closest('.pick-card');touchOrder = JSON.stringify(order());
      touchCard.classList.add('dragging');handle.setPointerCapture(event.pointerId);
    });
    handle.addEventListener('pointermove', event => {
      if (!touchCard) return;
      event.preventDefault();
      if (event.clientY < 70) window.scrollBy(0, -18);
      if (event.clientY > window.innerHeight - 70) window.scrollBy(0, 18);
      move(touchCard, targetAt(document.elementFromPoint(event.clientX, event.clientY), event.clientY, touchCard), event.clientY);
    });
    const finish = () => {
      if (!touchCard) return;
      touchCard.classList.remove('dragging');touchCard = null;
      if (JSON.stringify(order()) !== touchOrder) save();
    };
    handle.addEventListener('pointerup', finish);
    handle.addEventListener('pointercancel', finish);
  }
})();
