function renderMd(text) {
    const lines = text.split('\n');
    let html = '';
    let i = 0;

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function safeUrl(url) {
        const t = url.trim();
        if (/^javascript:/i.test(t) || /^data:/i.test(t)) return '#';
        return url.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    function inline(raw) {
        const pat = /!\[([^\]]*)\]\(((?:[^()]*|\([^()]*\))*)\)|\[([^\]]*)\]\(((?:[^()]*|\([^()]*\))*)\)|\*\*([^*]+)\*\*|`([^`]+)`/g;
        let result = '', last = 0, m;
        while ((m = pat.exec(raw)) !== null) {
            result += esc(raw.slice(last, m.index));
            last = m.index + m[0].length;
            if      (m[0][0] === '!') result += `<img alt="${esc(m[1])}" src="${safeUrl(m[2])}">`;
            else if (m[0][0] === '[') result += `<a href="${safeUrl(m[4])}">${esc(m[3])}</a>`;
            else if (m[0][0] === '*') result += `<strong>${esc(m[5])}</strong>`;
            else                      result += `<code>${esc(m[6])}</code>`;
        }
        return result + esc(raw.slice(last));
    }

    function isSep(row) {
        return row.split('|').slice(1, -1).every(c => /^[\s\-:]+$/.test(c));
    }

    while (i < lines.length) {
        const line = lines[i];

        // Fenced code block
        if (line.startsWith('```')) {
            const code = [];
            i++;
            while (i < lines.length && !lines[i].startsWith('```')) {
                code.push(esc(lines[i++]));
            }
            html += `<pre><code>${code.join('\n')}</code></pre>\n`;
            i++;
            continue;
        }

        // Headings
        if (line.startsWith('# '))  { html += `<h1>${inline(line.slice(2))}</h1>\n`;  i++; continue; }
        if (line.startsWith('## ')) { html += `<h2>${inline(line.slice(3))}</h2>\n`; i++; continue; }

        // Table: collect consecutive | lines
        if (line.startsWith('|')) {
            const rows = [];
            while (i < lines.length && lines[i].startsWith('|')) rows.push(lines[i++]);
            const sepIdx = rows.findIndex(isSep);
            html += '<table>\n';
            rows.forEach((row, idx) => {
                if (idx === sepIdx) return;
                const cells = row.split('|').slice(1, -1);
                const tag   = (sepIdx < 0 || idx < sepIdx) ? 'th' : 'td';
                html += '<tr>' + cells.map(c => `<${tag}>${inline(c.trim())}</${tag}>`).join('') + '</tr>\n';
            });
            html += '</table>\n';
            continue;
        }

        // List: collect consecutive - lines
        if (line.startsWith('- ')) {
            html += '<ul>\n';
            while (i < lines.length && lines[i].startsWith('- ')) {
                html += `<li>${inline(lines[i++].slice(2))}</li>\n`;
            }
            html += '</ul>\n';
            continue;
        }

        // Blank line
        if (line.trim() === '') { i++; continue; }

        // Paragraph
        html += `<p>${inline(line)}</p>\n`;
        i++;
    }

    return html;
}
