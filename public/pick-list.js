(() => {
  const board = document.getElementById('pickBoard');
  const form = document.getElementById('pickOrderForm');
  if (!board || !form) return;
  const buckets = [...board.querySelectorAll('.pick-bucket')];
  const cardsIn = bucket => [...bucket.querySelectorAll('.pick-card')];
  const layout = () => Object.fromEntries(buckets.map(bucket => [bucket.dataset.bucket, cardsIn(bucket).map(card => Number(card.dataset.team))]));
  const snapshot = () => JSON.stringify(layout());
  const save = () => { document.getElementById('pickOrder').value = snapshot(); form.requestSubmit(); };
  const updateCounts = () => { for (const bucket of buckets) bucket.querySelector('.pick-bucket-head span').textContent = cardsIn(bucket).length + ' teams'; };
  const restore = saved => {
    const byTeam = new Map([...board.querySelectorAll('.pick-card')].map(card => [Number(card.dataset.team), card]));
    for (const bucket of buckets) for (const team of saved[bucket.dataset.bucket]) bucket.querySelector('.pick-cards').appendChild(byTeam.get(team));
    updateCounts();
  };
  const destination = (element, x) => {
    let bucket = element?.closest?.('.pick-bucket');
    if (!bucket) {
      const bounds = board.getBoundingClientRect();
      if (x < bounds.left || x > bounds.right) return null;
      bucket = buckets.reduce((nearest, item) => {
        const center = box => { const rect = box.getBoundingClientRect(); return rect.left + rect.width / 2; };
        return Math.abs(center(item) - x) < Math.abs(center(nearest) - x) ? item : nearest;
      }, buckets[0]);
    }
    return bucket?.querySelector('.pick-cards');
  };
  const move = (card, list, y) => {
    if (!card || !list) return;
    const otherCards = [...list.querySelectorAll('.pick-card')].filter(item => item !== card);
    const next = otherCards.find(item => { const rect = item.getBoundingClientRect(); return y < rect.top + rect.height / 2; });
    const reference = next || null;
    if (reference !== card.nextSibling || card.parentElement !== list) list.insertBefore(card, reference);
    updateCounts();
  };
  let mouseTarget = null, dragging = null, startingLayout = '', dropped = false;
  board.addEventListener('pointerdown', event => { if (event.pointerType === 'mouse') mouseTarget = event.target; });
  board.addEventListener('dragstart', event => {
    const card = event.target.closest('.pick-card');
    if (!card || (mouseTarget?.closest('a,input,select,textarea,form,details,summary,button') && !mouseTarget.closest('.pick-drag'))) { event.preventDefault(); return; }
    dragging = card;startingLayout = snapshot();dropped = false;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', card.dataset.team);
    card.classList.add('dragging');
  });
  board.addEventListener('dragover', event => {
    if (!dragging) return;
    const list = destination(event.target, event.clientX);
    if (!list) return;
    event.preventDefault();event.dataTransfer.dropEffect = 'move';
    move(dragging, list, event.clientY);
  });
  board.addEventListener('drop', event => {
    if (!dragging) return;
    const list = destination(event.target, event.clientX);
    if (!list) return;
    event.preventDefault();dropped = true;
    move(dragging, list, event.clientY);
    if (snapshot() !== startingLayout) save();
  });
  board.addEventListener('dragend', () => {
    dragging?.classList.remove('dragging');
    if (dragging && !dropped) restore(JSON.parse(startingLayout));
    dragging = null;mouseTarget = null;
  });
  for (const handle of board.querySelectorAll('.pick-drag')) {
    let touchCard = null, touchLayout = '';
    handle.addEventListener('keydown', event => {
      if (!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key)) return;
      event.preventDefault();
      const card = handle.closest('.pick-card');
      const bucket = card.closest('.pick-bucket');
      const index = buckets.indexOf(bucket);
      const before = snapshot();
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        const next = buckets[index + (event.key === 'ArrowLeft' ? -1 : 1)];
        if (next) next.querySelector('.pick-cards').appendChild(card);
      } else {
        const siblings = cardsIn(bucket);
        const target = siblings[siblings.indexOf(card) + (event.key === 'ArrowUp' ? -1 : 1)];
        if (target) bucket.querySelector('.pick-cards').insertBefore(card, event.key === 'ArrowUp' ? target : target.nextSibling);
      }
      updateCounts();
      if (snapshot() !== before) save();
    });
    handle.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse') return;
      event.preventDefault();touchCard = handle.closest('.pick-card');touchLayout = snapshot();
      touchCard.classList.add('dragging');handle.setPointerCapture(event.pointerId);
    });
    handle.addEventListener('pointermove', event => {
      if (!touchCard) return;
      event.preventDefault();
      if (event.clientY < 70) window.scrollBy(0, -18);
      if (event.clientY > window.innerHeight - 70) window.scrollBy(0, 18);
      const scroller = board.parentElement;const rect = scroller.getBoundingClientRect();
      if (event.clientX < rect.left + 35) scroller.scrollLeft -= 18;
      if (event.clientX > rect.right - 35) scroller.scrollLeft += 18;
      move(touchCard, destination(document.elementFromPoint(event.clientX, event.clientY), event.clientX), event.clientY);
    });
    handle.addEventListener('pointerup', () => {
      if (!touchCard) return;
      touchCard.classList.remove('dragging');touchCard = null;
      if (snapshot() !== touchLayout) save();
    });
    handle.addEventListener('pointercancel', () => {
      if (!touchCard) return;
      touchCard.classList.remove('dragging');touchCard = null;restore(JSON.parse(touchLayout));
    });
  }
})();
