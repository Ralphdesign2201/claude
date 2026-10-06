// Ziehen und Ablegen per Maus und Touch (Zeiger-Ereignisse)
import { el } from './util.js';

/** o: {node, container(sel), items(sel), fixed?, horizontal?, immediate?, endMarker?, accept?, onClick?, onDrop({container,before,after}), ghostClass?} */
export function startDrag(e, o) {
  if ((e.button > 0 && e.pointerType === 'mouse') || e.target.closest('input,textarea,select,a,[data-nodrag]')) return;
  const { node } = o, isTouch = e.pointerType === 'touch', sx = e.clientX, sy = e.clientY;
  const rect = node.getBoundingClientRect(), ox = sx - rect.left, oy = sy - rect.top;
  let started = false, ghost, ph, timer, cont = null;
  const scrollers = () => [document.querySelector('#boardScroll'), cont].filter(Boolean);

  const begin = () => {
    started = true;
    ghost = node.cloneNode(true); ghost.classList.add('ghost-card'); ghost.removeAttribute('id');
    ghost.style.cssText = `width:${rect.width}px;height:${rect.height}px;left:${rect.left}px;top:${rect.top}px`;
    document.body.append(ghost);
    ph = el('div', { class: 'ph', style: o.horizontal ? `width:${rect.width}px;min-height:${rect.height}px;flex:none` : `height:${rect.height}px` });
    node.after(ph); node.hidden = true;
    document.body.classList.add('dragging');
  };
  const place = ev => {
    ghost.style.left = ev.clientX - ox + 'px'; ghost.style.top = ev.clientY - oy + 'px';
    const under = document.elementFromPoint(ev.clientX, ev.clientY);
    const c = o.fixed || under?.closest(o.container);
    if (c && (!o.accept || o.accept(c))) {
      cont = c;
      const kids = [...c.children].filter(k => k !== node && k !== ph && k.matches(o.items));
      const before = kids.find(k => { const r = k.getBoundingClientRect(); return o.horizontal ? ev.clientX < r.left + r.width / 2 : ev.clientY < r.top + r.height / 2; });
      if (before) { if (ph.nextElementSibling !== before) c.insertBefore(ph, before); }
      else if (o.endMarker && c.contains(o.endMarker)) c.insertBefore(ph, o.endMarker);
      else c.append(ph);
    }
    for (const s of scrollers()) {
      const r = s.getBoundingClientRect();
      if (ev.clientX > r.right - 50) s.scrollLeft += 14; else if (ev.clientX < r.left + 50) s.scrollLeft -= 14;
      if (ev.clientY > r.bottom - 50) s.scrollTop += 14; else if (ev.clientY < r.top + 50) s.scrollTop -= 14;
    }
  };
  const blockScroll = ev => { if (started) ev.preventDefault(); };
  const noMenu = ev => ev.preventDefault();
  const move = ev => {
    if (!started) {
      if (Math.hypot(ev.clientX - sx, ev.clientY - sy) > 6) { if (isTouch && !o.immediate) return cleanup(); begin(); } else return;
    }
    place(ev);
  };
  const finish = (ev) => {
    const was = started; cleanup();
    if (!was) { if (ev.type === 'pointerup') o.onClick?.(ev); return; }
    const sib = (dir) => { let x = ph[dir]; while (x && (x === node || !x.matches(o.items))) x = x[dir]; return x; };
    const info = { container: ph.parentElement, before: sib('nextElementSibling'), after: sib('previousElementSibling') };
    ph.remove(); node.hidden = false;
    o.onDrop?.(info);
  };
  function cleanup() {
    clearTimeout(timer);
    removeEventListener('pointermove', move); removeEventListener('pointerup', finish); removeEventListener('pointercancel', cancel);
    removeEventListener('touchmove', blockScroll); removeEventListener('contextmenu', noMenu);
    ghost?.remove(); document.body.classList.remove('dragging');
  }
  function cancel() { const was = started; cleanup(); if (was) { ph?.remove(); node.hidden = false; } }
  addEventListener('pointermove', move); addEventListener('pointerup', finish); addEventListener('pointercancel', cancel);
  if (isTouch) {
    addEventListener('touchmove', blockScroll, { passive: false }); addEventListener('contextmenu', noMenu);
    if (!o.immediate) timer = setTimeout(() => { begin(); navigator.vibrate?.(15); }, 350);
  } else e.preventDefault();
}
