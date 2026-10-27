<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';

ensure_auth_schema($conn);
require_admin();
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Positions</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #202a35;
            --muted: #687584;
            --line: #d5dce2;
            --paper: #fff;
            --canvas: #edf1f3;
            --green: #107c41;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: var(--canvas); color: var(--ink); font: 15px "Segoe UI", Arial, sans-serif; }
        main { max-width: 960px; margin: 0 auto; padding: 24px; background: var(--paper); border: 1px solid var(--line); border-radius: 8px; }
        h1 { margin-top: 0; }
        label { display: block; margin: 16px 0 6px; font-weight: 600; }
        input, textarea, button { font: inherit; }
        input[type="text"], textarea { width: 100%; padding: 10px; border: 1px solid #b8c2cb; border-radius: 4px; }
        textarea { min-height: 260px; resize: vertical; font-family: ui-monospace, Consolas, monospace; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        button, .button { display: inline-block; padding: 9px 14px; border: 1px solid var(--green); border-radius: 4px; background: var(--green); color: #fff; text-decoration: none; cursor: pointer; }
        button.secondary, .button.secondary { background: #fff; color: var(--green); }
        button:disabled { opacity: .6; cursor: wait; }
        .hint, #message { color: var(--muted); }
        #message.error { color: #a5332d; }
        table { width: 100%; margin-top: 18px; border-collapse: collapse; }
        th, td { padding: 8px; border: 1px solid var(--line); text-align: left; }
        th { background: #f4f7f8; }
        .status-error { color: #a5332d; }
        .status-update { color: #805b00; }
        .status-new { color: var(--green); }
        @media (max-width: 600px) { main { padding: 16px; } }
    </style>
</head>
<body>
<main>
    <h1>Import Regional Office Positions</h1>
    <p class="hint">Paste rows as title, salary_grade, level. Excel tab-separated rows are also accepted. Level may be B, I, or blank.</p>
    <label for="scope">Position scope</label>
    <select id="scope">
        <option value="REGIONAL_OFFICE" selected>REGIONAL_OFFICE</option>
    </select>
    <label for="newScope">Or type a new scope code</label>
    <input id="newScope" type="text" maxlength="40" placeholder="For example: PENRO">
    <label for="csv">Positions CSV</label>
    <textarea id="csv" spellcheck="false" placeholder="title,salary_grade,level&#10;Planning Officer III,18,B"></textarea>
    <div class="actions">
        <button type="button" class="secondary" id="previewButton">Preview</button>
        <button type="button" id="importButton">Import</button>
        <a class="button secondary" href="results_2.php">Back to Results</a>
    </div>
    <p id="message" role="status" aria-live="polite"></p>
    <div id="preview"></div>
</main>
<script>
    const csvInput = document.getElementById('csv');
    const scopeSelect = document.getElementById('scope');
    const newScopeInput = document.getElementById('newScope');
    const previewEl = document.getElementById('preview');
    const messageEl = document.getElementById('message');
    const csrfToken = <?php echo json_encode($csrfToken); ?>;
    let existingTitles = new Set();

    function currentScope() {
        return (newScopeInput.value.trim() || scopeSelect.value).toUpperCase();
    }

    function splitLine(line) {
        if (line.includes('\t')) return line.split('\t').map(value => value.trim());
        const fields = [];
        let value = '';
        let quoted = false;
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"' && quoted && line[i + 1] === '"') {
                value += '"';
                i++;
            } else if (char === '"') {
                quoted = !quoted;
            } else if (char === ',' && !quoted) {
                fields.push(value.trim());
                value = '';
            } else {
                value += char;
            }
        }
        fields.push(value.trim());
        return fields;
    }

    function parseForPreview(text) {
        const rows = [];
        const errors = [];
        text.split(/\r\n|\n|\r/).forEach((line, index) => {
            const lineNumber = index + 1;
            if (!line.trim()) return;
            const fields = splitLine(line);
            if (fields.length >= 2
                && ['title', 'position', 'position title'].includes(fields[0].toLowerCase())
                && ['salary_grade', 'salary grade', 'sg'].includes(fields[1].toLowerCase())) return;
            if (fields.length !== 3) {
                errors.push({ line: lineNumber, title: line, status: 'Error', message: 'Expected three columns.' });
                return;
            }
            const [title, grade, rawLevel] = fields;
            const level = rawLevel.toUpperCase();
            if (!title || title.length > 255) {
                errors.push({ line: lineNumber, title, status: 'Error', message: 'Title must contain 1 to 255 characters.' });
            } else if (!/^(?:[1-9]|[12][0-9]|3[0-3])$/.test(grade)) {
                errors.push({ line: lineNumber, title, status: 'Error', message: 'Salary grade must be an integer from 1 to 33.' });
            } else if (!['', 'B', 'I'].includes(level)) {
                errors.push({ line: lineNumber, title, status: 'Error', message: 'Level must be blank, B, or I.' });
            } else {
                rows.push({ line: lineNumber, title, salary_grade: Number(grade), leadership_level: level });
            }
        });
        return { rows, errors };
    }

    async function loadExistingTitles() {
        const params = new URLSearchParams({ action: 'list', scope: currentScope() });
        const response = await fetch('positions_api.php?' + params.toString(), { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Could not load existing positions.');
        existingTitles = new Set(data.positions.map(position => position.title.toLocaleLowerCase()));
    }

    async function preview() {
        messageEl.textContent = 'Loading existing positions…';
        messageEl.className = '';
        try {
            await loadExistingTitles();
            const parsed = parseForPreview(csvInput.value);
            const items = [
                ...parsed.rows.map(row => ({ ...row, status: existingTitles.has(row.title.toLocaleLowerCase()) ? 'Update' : 'New', message: '' })),
                ...parsed.errors
            ].sort((a, b) => a.line - b.line);
            const table = document.createElement('table');
            const thead = document.createElement('thead');
            const header = document.createElement('tr');
            ['Line', 'Title', 'Salary grade', 'Level', 'Status', 'Details'].forEach(label => {
                const th = document.createElement('th');
                th.textContent = label;
                header.appendChild(th);
            });
            thead.appendChild(header);
            table.appendChild(thead);
            const tbody = document.createElement('tbody');
            items.forEach(row => {
                const tr = document.createElement('tr');
                const values = [row.line, row.title, row.salary_grade ?? '', row.leadership_level ?? '', row.status, row.message];
                values.forEach((value, index) => {
                    const td = document.createElement('td');
                    td.textContent = String(value);
                    if (index === 4) td.className = row.status === 'Error' ? 'status-error' : row.status === 'Update' ? 'status-update' : 'status-new';
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            previewEl.replaceChildren(table);
            messageEl.textContent = items.length ? 'Preview ready: ' + parsed.rows.length + ' valid row(s), ' + parsed.errors.length + ' error(s).' : 'No position rows found.';
        } catch (error) {
            messageEl.textContent = error.message;
            messageEl.className = 'error';
        }
    }

    document.getElementById('previewButton').addEventListener('click', preview);
    document.getElementById('importButton').addEventListener('click', async event => {
        const button = event.currentTarget;
        button.disabled = true;
        messageEl.textContent = 'Importing…';
        messageEl.className = '';
        try {
            const body = new FormData();
            body.set('action', 'import');
            body.set('scope', currentScope());
            body.set('csv', csvInput.value);
            body.set('csrf_token', csrfToken);
            const response = await fetch('positions_api.php', { method: 'POST', body, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                const lines = (data.errors || []).map(error => 'Line ' + error.line + ': ' + error.message).join(' ');
                throw new Error((data.message || 'Import failed.') + (lines ? ' ' + lines : ''));
            }
            const lines = (data.errors || []).map(error => 'Line ' + error.line + ': ' + error.message).join(' ');
            messageEl.textContent = 'Import complete: ' + data.inserted + ' inserted, ' + data.updated + ' updated, ' + data.skipped + ' skipped.'
                + (lines ? ' ' + lines : '');
            await loadExistingTitles();
        } catch (error) {
            messageEl.textContent = error.message;
            messageEl.className = 'error';
        } finally {
            button.disabled = false;
        }
    });
    newScopeInput.addEventListener('input', () => { existingTitles = new Set(); });
</script>
</body>
</html>
