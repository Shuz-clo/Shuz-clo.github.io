<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/AuthSchema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

ensure_auth_schema($conn);
require_role(['rater']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a Scoring Sheet</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 32px 16px;
            background: #f4f6f9;
            color: #202124;
            font-family: Arial, Helvetica, sans-serif;
        }
        main { max-width: 900px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
        h1 { margin: 0 0 8px; font-size: 26px; }
        p { color: #555; line-height: 1.5; }
        .user-bar { color: #555; font-size: 14px; }
        .user-bar a { color: #107c41; font-weight: 700; }
        #status { padding: 14px 16px; background: #fff; border: 1px solid #d5d9e0; border-radius: 8px; }
        #status.error { color: #7a1f16; background: #fdecea; border-color: #f5c2c0; }
        #templates { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }
        .template {
            display: block;
            padding: 20px;
            color: inherit;
            text-decoration: none;
            background: #fff;
            border: 1px solid #d5d9e0;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }
        .template:hover, .template:focus { border-color: #107c41; box-shadow: 0 0 0 3px rgba(16,124,65,.12); }
        .template h2 { margin: 0 0 10px; color: #107c41; font-size: 18px; }
        .template p { margin: 0; font-size: 14px; }
        @media (max-width: 520px) { header { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Choose a Scoring Sheet</h1>
            <p>Available sheets are filtered by your registered region, field, and salary grade.</p>
        </div>
        <div class="user-bar">
            Signed in as <strong><?php echo htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
            · <a href="logout.php">Log out</a>
        </div>
    </header>
    <div id="status" role="status" aria-live="polite">Loading sheets…</div>
    <section id="templates" aria-label="Available scoring sheets"></section>
</main>
<script>
    const statusEl = document.getElementById('status');
    const templatesEl = document.getElementById('templates');

    async function loadTemplates() {
        try {
            const response = await fetch('sheets_api.php?action=template_list', { credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.message || 'Unable to load available sheets.');
            }

            templatesEl.replaceChildren();
            if (data.templates.length === 0) {
                statusEl.textContent = 'No shared sheets match your registered region, field, and salary grade. Please contact your administrator.';
                return;
            }

            data.templates.forEach(template => {
                const link = document.createElement('a');
                link.className = 'template';
                link.href = 'index.php?template_id=' + encodeURIComponent(template.id);
                const title = document.createElement('h2');
                title.textContent = template.title;
                const details = document.createElement('p');
                details.textContent = 'Salary grades ' + template.min_salary_grade + '–' + template.max_salary_grade
                    + ' · Choose a candidate within this range';
                link.append(title, details);
                templatesEl.appendChild(link);
            });
            statusEl.textContent = 'Select a sheet to start rating.';
        } catch (error) {
            statusEl.classList.add('error');
            statusEl.textContent = error.message;
        }
    }

    loadTemplates();
</script>
</body>
</html>
