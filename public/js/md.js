// Kleiner, sicherer Markdown-Renderer (HTML wird immer maskiert)
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const safeUrl = u => /^(https?:|mailto:|\/|#)/i.test(u.trim()) ? u.trim() : '#';
function inline(s) {
  return s.split(/(`[^`\n]+`)/).map((part, i) => {
    if (i % 2) return `<code>${esc(part.slice(1, -1))}</code>`;
    let t = esc(part);
    t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, a, u) => `<a href="${safeUrl(u.replace(/&amp;/g, '&'))}" target="_blank" rel="noopener noreferrer">${a}</a>`);
    t = t.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, (_, p, u) => `${p}<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`);
    t = t.replace(/\*\*([^*]+)\*\*|__([^_]+)__/g, (_, a, b) => `<strong>${a || b}</strong>`)
      .replace(/(^|[^*\w])\*([^*\n]+)\*/g, '$1<em>$2</em>').replace(/(^|[^_\w])_([^_\n]+)_/g, '$1<em>$2</em>').replace(/~~([^~]+)~~/g, '<del>$1</del>');
    return t;
  }).join('');
}
export function md(src) {
  const lines = String(src || '').replace(/\r/g, '').split('\n'); const out = []; let i = 0;
  const listRe = /^(\s*)([-*+]|\d+\.)\s+(.*)$/;
  while (i < lines.length) {
    const l = lines[i];
    if (/^```/.test(l)) { const buf = []; i++; while (i < lines.length && !/^```/.test(lines[i])) buf.push(lines[i++]); i++; out.push(`<pre><code>${esc(buf.join('\n'))}</code></pre>`); continue; }
    let m;
    if ((m = l.match(/^(#{1,4})\s+(.*)$/))) { out.push(`<h${m[1].length + 1}>${inline(m[2])}</h${m[1].length + 1}>`); i++; continue; }
    if (/^(-{3,}|\*{3,})\s*$/.test(l)) { out.push('<hr>'); i++; continue; }
    if (/^>\s?/.test(l)) { const buf = []; while (i < lines.length && /^>\s?/.test(lines[i])) buf.push(lines[i++].replace(/^>\s?/, '')); out.push(`<blockquote>${inline(buf.join('<br>'))}</blockquote>`); continue; }
    if (listRe.test(l)) {
      const stack = []; // [{indent, tag}]
      while (i < lines.length && (m = lines[i].match(listRe))) {
        const ind = m[1].replace(/\t/g, '  ').length, tag = /\d/.test(m[2]) ? 'ol' : 'ul';
        while (stack.length && ind < stack[stack.length - 1].ind) out.push(`</li></${stack.pop().tag}>`);
        if (!stack.length || ind > stack[stack.length - 1].ind) { out.push(`<${tag}>`); stack.push({ ind, tag }); } else out.push('</li>');
        let body = m[3], cb = body.match(/^\[([ xX])\]\s+(.*)$/);
        out.push(cb ? `<li class="task"><input type="checkbox" disabled ${cb[1] !== ' ' ? 'checked' : ''}> ${inline(cb[2])}` : `<li>${inline(body)}`); i++;
      }
      while (stack.length) out.push(`</li></${stack.pop().tag}>`); continue;
    }
    if (!l.trim()) { i++; continue; }
    const buf = []; while (i < lines.length && lines[i].trim() && !/^(```|#{1,4}\s|>|\s*([-*+]|\d+\.)\s)/.test(lines[i])) buf.push(lines[i++]);
    out.push(`<p>${buf.map(inline).join('<br>')}</p>`);
  }
  return out.join('\n');
}
