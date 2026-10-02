(() => {
  const namespace = 'http://www.w3.org/2000/svg';
  const node = (tag, attributes = {}) => {
    const element = document.createElementNS(namespace, tag);
    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value));
    return element;
  };
  document.querySelectorAll('.playoff-board').forEach((board, index) => {
    const svg = board.querySelector('.playoff-connectors');
    if (!svg) return;
    const cards = [...board.querySelectorAll('[data-match]')];
    const byMatch = new Map(cards.map(card => [card.dataset.match, card]));
    const draw = () => {
      const rect = board.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      svg.replaceChildren();
      svg.setAttribute('viewBox', `0 0 ${rect.width} ${rect.height}`);
      const defs = node('defs');
      const dark = document.documentElement.classList.contains('dark-mode');
      ['winner', 'loser'].forEach(kind => {
        const marker = node('marker', { id: `playoff-${kind}-${index}`, viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 6, markerHeight: 6, orient: 'auto' });
        marker.append(node('path', { d: 'M0 0 L10 5 L0 10 Z', fill: kind === 'winner' ? (dark ? '#73d796' : '#23864a') : (dark ? '#ffb36b' : '#c76a17') }));
        defs.append(marker);
      });
      svg.append(defs);
      let lane = 0;
      cards.forEach(target => {
        const sources = [...(target.dataset.source || '').matchAll(/(Winner|Loser) M(\d+)/g)];
        sources.forEach(([_, outcome, number], port) => {
          const source = byMatch.get(number);
          if (!source) return;
          const from = source.getBoundingClientRect(), to = target.getBoundingClientRect();
          const sx = from.right - rect.left, sy = from.top + from.height / 2 - rect.top;
          const tx = to.left - rect.left, ty = to.top + to.height * (port === 0 ? .35 : .7) - rect.top;
          if (tx <= sx) return;
          const kind = outcome.toLowerCase();
          let route;
          if (tx - sx < 100) {
            const middle = sx + (tx - sx) * (port === 0 ? .4 : .6);
            route = `M${sx} ${sy} H${middle} V${ty} H${tx - 3}`;
          } else {
            // Skip-round routes travel above cards, rather than through intervening matches.
            const y = 5 + (lane++ % 4) * 7;
            route = `M${sx} ${sy} H${sx + 18} V${y} H${tx - 18} V${ty} H${tx - 3}`;
          }
          const path = node('path', { d: route, class: `${kind}-edge`, fill: 'none', 'stroke-width': 2, 'stroke-linejoin': 'round', 'marker-end': `url(#playoff-${kind}-${index})` });
          const title = node('title');
          title.textContent = `${outcome} of match ${number} advances to match ${target.dataset.match}`;
          path.append(title);
          svg.append(path);
        });
      });
    };
    let frame;
    const schedule = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(draw); };
    if (typeof ResizeObserver !== 'undefined') {
      const observer = new ResizeObserver(schedule);
      observer.observe(board);
      cards.forEach(card => observer.observe(card));
    }
    window.addEventListener('resize', schedule);
    board.closest('details')?.addEventListener('toggle', schedule);
    document.fonts?.ready.then(schedule);
    schedule();
  });
})();
