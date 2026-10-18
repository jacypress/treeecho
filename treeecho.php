<?php
// Handle AJAX request first – no output before this
if (isset($_GET['action']) && $_GET['action'] === 'getStructure') {
    header('Content-Type: application/json');
    // Safe root resolution – prevent traversal outside script dir
    $baseDir = realpath('.');
    $requestedRoot = isset($_GET['root']) ? trim($_GET['root'], '/\\') : '.';
    $fullRoot = realpath($requestedRoot === '.' ? $baseDir : $baseDir . '/' . $requestedRoot);
    if ($fullRoot === false || strpos($fullRoot, $baseDir) !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid or inaccessible root path']);
        exit;
    }
    function getDirectoryTree($dir, $excludePatterns = [], $maxDepth = 20, $currentDepth = 0) {
        if ($currentDepth > $maxDepth) return [];
        $result = [];
        if (!is_dir($dir) || !is_readable($dir)) return $result;
        $items = @scandir($dir) ?: [];
        natcasesort($items); // Consistent alphabetical order
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            $relative = substr($path, strlen(realpath('.')) + 1);
            $relative = str_replace('\\', '/', $relative); // Normalize slashes
            // Precise exclusion (full segment match)
            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $relative) || preg_match($pattern, $item)) {
                    continue 2; // Skip this item
                }
            }
            if (is_dir($path)) {
                $result[] = $relative . '/';
                $sub = getDirectoryTree($path, $excludePatterns, $maxDepth, $currentDepth + 1);
                $result = array_merge($result, $sub);
            } else {
                $result[] = $relative;
            }
        }
        return $result;
    }
    // Common exclusions – regex for precision
    $excludePatterns = [
        '#(^|/)\.git($|/)#',
        '#(^|/)(node_modules|\.DS_Store|Thumbs\.db)($|/)#',
    ];
    // Dynamically exclude the current script file itself
    $self = basename(__FILE__);
    $excludePatterns[] = '#(^|/)' . preg_quote($self, '#') . '$#';
    $files = getDirectoryTree($fullRoot, $excludePatterns);
    echo json_encode([
        'success' => true,
        'files' => $files,
        'count' => count($files),
        'path' => $fullRoot,
        'root' => $requestedRoot === '.' ? basename($baseDir) : $requestedRoot
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='4' fill='%231c1c1c'/><path d='M6 8 L6 24' stroke='%23ffffff' stroke-width='2' stroke-linecap='square'/><path d='M6 16 L26 16' stroke='%23ffffff' stroke-width='2' stroke-linecap='square'/></svg>">
    <title>TreeEcho – Folder Structure Generator</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        ::selection { background-color: rgb(0 96 255); color: white; }

        html {
            max-width: 100%;
        }

        body {
            font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
            background:rgb(18 18 18); 
            color:rgb(242 242 242);
            line-height:1.4;
            max-width: 100%;
            overflow-x: clip;
        }

        /* Main responsive container */
        .main-container {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 2rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            min-width: 0;
        }

        /* Grid child containing the tree – must be allowed to shrink */
        main {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }

        /* Right sidebar (controls + info) */
        .sidebar {
            padding: 2rem 2.5rem;
            min-width: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
        }

        /* Left container */
        .structure-container {
            background: transparent;
            border-radius: 8px;
            padding: 2.5rem;
            min-width: 0;
            max-width: 100%;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }

        /* ASCII tree output – clipped, JS applies per-line ellipsis */
        .structure-output {
            font-size: 1rem;
            line-height: 1.6;
            min-height: 200px;
            user-select: text;
            font-family: 'Monaco', 'Menlo', 'Consolas', monospace;

            white-space: pre;
            overflow: hidden;

            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        /* Hidden probe for measuring character width */
        .measure-probe {
            position: absolute;
            visibility: hidden;
            white-space: pre;
            pointer-events: none;
            top: -9999px;
            left: -9999px;
        }

        @media (min-width: 900px) {
            .main-container {
                grid-template-columns: minmax(0, 3fr) minmax(400px, 1fr);
            }
            .sidebar {
                position: sticky;
                top: 0;
                align-self: start;
                height: 100vh;
                overflow-y: auto;
                box-sizing: border-box;
            }
        }

        /* Mobile styles */
        @media (max-width: 899px) {
            .main-container {
                gap: 1rem;
            }

            .sidebar {
                position: static;
            }

            .structure-output {
                font-size: .9rem;
            }

            .exclude-list {
                max-height: 260px;
                flex: 0 0 auto;
            }
        }

        /* Header / Title */
        .header {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .header h1 {
            width: 100%;
            font-size: 1.8rem;
            color: white;
            margin: 0 0 40px 0;
        }

        .controls {
            width: 100%;
            display: flex;
            gap: .8rem;
            flex-wrap: wrap;
        }

        .controls button {
            flex: 1 1 auto;
            min-width: 120px;
        }

        #refreshBtn::before {
            content: '↻';
            font-size: 1.2rem;
            margin-right: 8px;
        }
        #copyBtn::before {
            content: '⎘';
            font-size: 1.2rem;
            margin-right: 8px;
        }

        button {
            background: transparent;
            color: white;
            border: 2px solid white;
            padding: .6rem 1.2rem;
            border-radius: 0;
            cursor: pointer;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            justify-content: center;
            align-items: center;
        }

        button.copy-btn {
            background: white;
            color: black;
        }

        button:hover,
        button.copy-btn:hover {
            opacity: .9;
        }

        button.copy-btn.copied {
            background: rgb(0 96 255);
            color: white;
            border-color: rgb(0 96 255);
        }

        @keyframes pulse {
            0%,100% { transform:scale(1); }
            50% { transform:scale(1.05); }
        }

        /* Structure output highlight */
        #structureOutput.highlight-copied.flash {
            color: rgb(128 192 255);
            transition: none;
        }

        .current {
            color: rgb(173 181 189);
        }

        .path-info {
            color: rgb(255 255 255 / .85);
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }

        .stats {
            color: rgb(173 181 189);
            margin-top: 1.5rem;
        }

        /* Info box */
        .info-box { 
            display: none;
            color: white;
            font-size: .9rem;
            margin-top: 2.5rem;
        }

        .error {
            color: #ff6b6b;
            background: #3a1d1d;
            padding: 10px;
            border-radius: 4px;
            border-left: 4px solid rgb(255 107 107);
        }

        .loading {
            color: rgb(255 255 255 / .85);
            font-style: italic;
        }

        a {
            display: inline-block;
            margin-top: 30px;
            color: rgb(173 181 189);
            text-decoration: none;
        }

        a:hover {
            color: rgb(255 255 255);
            text-decoration: none;
        }

        /* ============ Exclude panel styles ============ */
        .exclude-panel {
            margin-top: 2.5rem;
            padding-top: 1.25rem;
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
        }

        .exclude-panel h2 {
            font-size: .95rem;
            font-weight: 600;
            color: rgb(255 255 255 / .9);
            margin-bottom: .35rem;
            letter-spacing: .02em;
        }

        .exclude-hint {
            font-size: .78rem;
            color: rgb(173 181 189);
            margin-bottom: .9rem;
        }

        .exclude-list {
            overflow-y: auto;
            padding-right: .5rem;
            display: flex;
            flex-direction: column;
            gap: .15rem;
            scrollbar-width: thin;
            scrollbar-color: rgb(255 255 255 / .2) transparent;
            flex: 1 1 auto;
            min-height: 0;
        }

        .exclude-list::-webkit-scrollbar { width: 6px; }
        .exclude-list::-webkit-scrollbar-thumb {
            background: rgb(255 255 255 / .2);
            border-radius: 3px;
        }

        .exclude-item {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .28rem .35rem;
            font-size: .82rem;
            color: rgb(242 242 242 / .9);
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .exclude-item:hover {
            background: rgb(255 255 255 / .07);
        }

        .exclude-item input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            border: 2px solid rgb(255 255 255 / .5);
            border-radius: 0;
            background: transparent;
            cursor: pointer;
            position: relative;
            transition: background .12s, border-color .12s;
        }

        .exclude-item input[type="checkbox"]:checked {
            background: rgb(0 96 255);
            border-color: rgb(0 96 255);
        }

        .exclude-item input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
        }

        /* Indent nested directories visually */
        .exclude-item .depth-pad {
            display: inline-block;
            flex-shrink: 0;
        }

        .exclude-item .folder-icon {
            color: rgb(255 255 255);
            flex-shrink: 0;
        }

        .exclude-item .label {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .exclude-empty {
            font-size: .8rem;
            color: rgb(173 181 189);
            font-style: italic;
        }

        /* Small mobile adjustments */
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .structure-container, .sidebar {
                padding: 1rem;
            }

            .controls button {
                min-width: 100%;
            }

            .header h1 {
                font-size: 1.5rem;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Left: main structure output -->
        <main>
            <div class="structure-container">
                <div class="path-info"><span class="current">Current directory:</span> <span id="currentPath">Loading...</span></div>
                <pre id="structureOutput" class="structure-output">Loading folder structure...</pre>
                <div class="stats" id="stats"></div>
            </div>
        </main>

        <!-- Right: sticky sidebar -->
        <aside class="sidebar">
            <div class="header">
                <h1>├── TreeEcho</h1>
                <div class="controls">
                    <button id="refreshBtn">Refresh</button>
                    <button id="copyBtn" class="copy-btn">Copy</button>
                </div>
            </div>

            <div class="info-box">
                <strong>Usage:</strong> Click "Copy" to get a clean ASCII tree for READMEs, docs, tickets, AI chats, etc.<br>
                Compatible with Markdown code blocks. Refresh after file changes.
            </div>

            <!-- Exclude panel -->
            <div class="exclude-panel">
                <h2>Exclude folders</h2>
                <div class="exclude-hint">Check to hide a folder and its contents from the tree and copy output.</div>
                <div id="excludeList" class="exclude-list">
                    <div class="exclude-empty">Loading…</div>
                </div>
            </div>

            <p class="credit"><a href="https://github.com/jacypress/treeecho" target="_blank">github.com/jacypress/treeecho</a></p>
        </aside>
    </div>

    <!-- Hidden probe used to measure monospace char width -->
    <span id="measureProbe" class="measure-probe" aria-hidden="true">XXXXXXXXXXXXXXXXXXXX</span>

    <script>
        class FolderStructure {
            constructor() {
                this.rawList = [];
                this.visibleList = [];
                this.excluded = new Set();
                this.tree = null;
                this.rootName = 'root';
                this.fullText = '';
                this.outputEl = document.getElementById('structureOutput');
                this.statsEl = document.getElementById('stats');
                this.pathEl = document.getElementById('currentPath');
                this.excludeListEl = document.getElementById('excludeList');
                this.probeEl = document.getElementById('measureProbe');
                this.resizeTimer = null;
                this.init();
            }

            async init() {
                document.getElementById('refreshBtn').onclick = () => this.loadStructure();
                document.getElementById('copyBtn').onclick = () => this.copyToClipboard();

                window.addEventListener('resize', () => {
                    clearTimeout(this.resizeTimer);
                    this.resizeTimer = setTimeout(() => this.fitToWidth(), 100);
                });

                await this.loadStructure();
            }

            async loadStructure() {
                this.showLoading();
                try {
                    const url = new URL(window.location);
                    url.searchParams.set('action', 'getStructure');
                    const res = await fetch(url);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'Failed to load');

                    this.rawList = data.files;

                    const dirSet = new Set(this.rawList.filter(p => p.endsWith('/')));
                    for (const ex of [...this.excluded]) {
                        if (!dirSet.has(ex)) this.excluded.delete(ex);
                    }

                    this.rootName = data.root || 'root';
                    this.buildExcludeUI();
                    this.recomputeVisible();
                    this.render(this.rootName);
                    this.updateStats();
                    this.pathEl.textContent = data.path;
                } catch (err) {
                    this.showError('Error: ' + err.message);
                }
            }

            isExcluded(path) {
                if (this.excluded.size === 0) return false;
                const normalized = path.endsWith('/') ? path : path + '/';
                for (const ex of this.excluded) {
                    if (normalized === ex || normalized.startsWith(ex)) return true;
                }
                return false;
            }

            recomputeVisible() {
                this.visibleList = this.rawList.filter(p => !this.isExcluded(p));
                this.tree = this.buildTree(this.visibleList);
            }

            buildExcludeUI() {
                const dirs = this.rawList.filter(p => p.endsWith('/'));
                this.excludeListEl.innerHTML = '';

                if (dirs.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'exclude-empty';
                    empty.textContent = 'No subfolders found.';
                    this.excludeListEl.appendChild(empty);
                    return;
                }

                const frag = document.createDocumentFragment();

                dirs.forEach(dirPath => {
                    const cleaned = dirPath.replace(/\/$/, '');
                    const parts = cleaned.split('/');
                    const name = parts[parts.length - 1];
                    const depth = parts.length - 1;

                    const label = document.createElement('label');
                    label.className = 'exclude-item';
                    label.title = dirPath;

                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.checked = this.excluded.has(dirPath);
                    cb.addEventListener('change', () => {
                        if (cb.checked) this.excluded.add(dirPath);
                        else this.excluded.delete(dirPath);
                        this.recomputeVisible();
                        this.render(this.rootName || 'root');
                        this.updateStats();
                    });

                    const pad = document.createElement('span');
                    pad.className = 'depth-pad';
                    pad.style.width = (depth * 10) + 'px';

                    const icon = document.createElement('span');
                    icon.className = 'folder-icon';
                    icon.textContent = '▸';

                    const nameSpan = document.createElement('span');
                    nameSpan.className = 'label';
                    nameSpan.textContent = name;

                    label.appendChild(cb);
                    label.appendChild(pad);
                    label.appendChild(icon);
                    label.appendChild(nameSpan);
                    frag.appendChild(label);
                });

                this.excludeListEl.appendChild(frag);
            }

            buildTree(paths) {
                const tree = {};
                paths.forEach(path => {
                    if (!path) return;
                    const parts = path.split('/').filter(Boolean);
                    let node = tree;
                    parts.forEach((part, i) => {
                        const isLast = i === parts.length - 1;
                        const isDir = path.endsWith('/') || !isLast;
                        if (!node[part]) {
                            node[part] = { name: part, children: {}, isDir };
                        }
                        if (!isLast) node = node[part].children;
                    });
                });
                return tree;
            }

            generateTree(node, prefix = '', hasSibling = false) {
                let output = '';
                const entries = Object.entries(node).sort(([,a], [,b]) => {
                    if (a.isDir !== b.isDir) return a.isDir ? -1 : 1;
                    return a.name.localeCompare(b.name);
                });

                entries.forEach(([key, item], idx) => {
                    const isLastItem = idx === entries.length - 1;
                    const linePrefix = prefix + (isLastItem ? '└── ' : '├── ');
                    output += linePrefix + item.name + (item.isDir ? '/' : '') + '\n';

                    if (item.isDir && Object.keys(item.children).length > 0) {
                        const childPrefix = prefix + (isLastItem ? '    ' : '│   ');
                        output += this.generateTree(item.children, childPrefix, !isLastItem);
                    }
                });

                return output;
            }

            render(rootName = 'root') {
                if (!this.tree || !Object.keys(this.tree).length) {
                    this.fullText = '(empty directory)';
                    this.outputEl.textContent = this.fullText;
                    return;
                }
                this.fullText = rootName + '/\n' + this.generateTree(this.tree);
                this.outputEl.textContent = this.fullText;
                this.fitToWidth();
            }

            fitToWidth() {
                if (!this.fullText) return;

                const containerW = this.outputEl.clientWidth;
                if (containerW <= 0) return;

                const probe = this.probeEl;
                probe.style.font = getComputedStyle(this.outputEl).font;
                const probeW = probe.getBoundingClientRect().width;
                const charW = probeW / probe.textContent.length;
                if (!charW || !isFinite(charW)) return;

                const maxChars = Math.max(1, Math.floor(containerW / charW));

                const lines = this.fullText.split('\n');
                const outLines = lines.map(line => {
                    if (line.length <= maxChars) return line;
                    return line.slice(0, maxChars - 1) + '…';
                });

                this.outputEl.textContent = outLines.join('\n');
            }

            updateStats() {
                const folders = this.visibleList.filter(p => p.endsWith('/')).length;
                const files = this.visibleList.length - folders;
                const hidden = this.rawList.length - this.visibleList.length;
                let text = `${folders} folders • ${files} files`;
                if (hidden > 0) text += ` • ${hidden} hidden`;
                this.statsEl.textContent = text;
            }

            showLoading() {
                this.fullText = '';
                this.outputEl.textContent = 'Loading folder structure...';
                this.outputEl.className = 'structure-output loading';
            }

            showError(msg) {
                this.fullText = '';
                this.outputEl.textContent = msg;
                this.outputEl.className = 'structure-output error';
            }

            async copyToClipboard() {
                const text = (this.fullText || this.outputEl.textContent).trim();
                const btn = document.getElementById('copyBtn');
                const output = this.outputEl;

                try {
                    await navigator.clipboard.writeText(text);

                    output.classList.add('highlight-copied', 'flash');

                    setTimeout(() => {
                        output.classList.remove('flash');
                    }, 500);

                    const orig = btn.textContent;
                    btn.textContent = 'Copied!';
                    btn.classList.add('copied');

                    setTimeout(() => {
                        btn.textContent = orig;
                        btn.classList.remove('copied');
                        output.classList.remove('highlight-copied');
                    }, 500);

                } catch (err) {
                    alert('Copy failed: ' + err);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => new FolderStructure());
    </script>
</body>
</html>
