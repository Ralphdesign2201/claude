// Erinnerungen als Systembenachrichtigung (Browser-API, nur solange das Fenster offen ist)
import { S, api, pref, toast, pad, todayStr, addDays, parseD } from './util.js';

export const RULES = [['day', 'Am Fälligkeitstag (8:00)'], ['day-1', '1 Tag vorher (8:00)'], ['hour-1', '1 Stunde vorher']];
const fired = () => pref.get('fired', {});
const dueMoment = c => { const [h, m] = (c.due_time || '09:00').split(':').map(Number), d = parseD(c.due_date); d.setHours(h, m, 0, 0); return d; };
const at8 = s => { const d = parseD(s); d.setHours(8, 0, 0, 0); return d; };

export async function checkReminders() {
  const rules = pref.get('reminders', ['day']); if (!rules.length) return;
  let cards; try { cards = await api('GET', '/reminders'); } catch { return; }
  const f = fired(), now = new Date(); let changed = false;
  for (const c of cards) {
    const due = dueMoment(c);
    const when = { day: at8(c.due_date), 'day-1': at8(addDays(c.due_date, -1)), 'hour-1': new Date(due.getTime() - 3600e3) };
    for (const r of rules) {
      const key = `${c.id}|${r}|${c.due_date}|${c.due_time || ''}`;
      if (f[key] || now < when[r] || now > new Date(due.getTime() + 864e5)) continue;
      f[key] = Date.now(); changed = true;
      const txt = { day: 'Heute fällig', 'day-1': 'Morgen fällig', 'hour-1': 'In einer Stunde fällig' }[r] + (c.due_time ? ` (${c.due_time})` : '');
      if ('Notification' in window && Notification.permission === 'granted') { try { new Notification(c.title, { body: txt, tag: key }); } catch { toast(`${c.title}: ${txt}`, 8000); } } else toast(`⏰ ${c.title}: ${txt}`, 8000);
    }
  }
  if (changed) { for (const k of Object.keys(f)) if (Date.now() - f[k] > 7 * 864e5) delete f[k]; pref.set('fired', f); }
}
export const startReminders = () => { checkReminders(); setInterval(checkReminders, 60000); };
export async function askPermission() { if (!('Notification' in window)) return 'nicht unterstützt'; return Notification.permission === 'default' ? Notification.requestPermission() : Notification.permission; }
