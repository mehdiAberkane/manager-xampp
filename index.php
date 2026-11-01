<?php
// Manager : liste les projets de htdocs avec leur techno principale et leur dernière modification.

const ROOT = __DIR__ . '/..';
const MAX_FILES = 4000;
const CACHE_TTL = 600;
const IGNORED_DIRS = ['node_modules', 'vendor', '.git', '.svn', '.idea', '.vscode', 'dist', 'build',
    'target', '__pycache__', 'venv', '.venv', '.next', '.angular', 'bower_components', 'coverage'];

const EXT_TECH = [
    'php' => 'PHP', 'js' => 'JavaScript', 'mjs' => 'JavaScript', 'jsx' => 'React', 'ts' => 'TypeScript',
    'tsx' => 'React', 'vue' => 'Vue.js', 'py' => 'Python', 'java' => 'Java', 'kt' => 'Kotlin',
    'html' => 'HTML/CSS', 'htm' => 'HTML/CSS', 'css' => 'HTML/CSS', 'scss' => 'HTML/CSS',
    'ps1' => 'PowerShell', 'psm1' => 'PowerShell', 'sh' => 'Shell', 'go' => 'Go', 'rb' => 'Ruby',
    'cs' => 'C#', 'c' => 'C', 'cpp' => 'C++', 'rs' => 'Rust', 'md' => 'Markdown',
];

const TECH_COLORS = [
    'WordPress' => '#21759b', 'PHP' => '#777bb4', 'Symfony' => '#5a5a5a', 'Laravel' => '#f05340',
    'JavaScript' => '#d4b400', 'TypeScript' => '#3178c6', 'Node.js' => '#3c873a', 'Express' => '#3c873a',
    'React' => '#149eca', 'Angular' => '#dd0031', 'Vue.js' => '#41b883', 'Electron' => '#47848f',
    'Python' => '#3572a5', 'Java' => '#b07219', 'Kotlin' => '#a97bff', 'HTML/CSS' => '#e34c26',
    'PowerShell' => '#2a5aa8', 'Shell' => '#4eaa25', 'Extension Chrome' => '#4285f4',
    'Go' => '#00add8', 'Ruby' => '#cc342d', 'C#' => '#68217a', 'C' => '#555555', 'C++' => '#f34b7d',
    'Rust' => '#dea584', 'Markdown' => '#083fa1',
];

function scanProject(string $dir): array
{
    $counts = [];
    $latest = filemtime($dir);
    $seen = 0;
    $stack = [$dir];

    while ($stack && $seen < MAX_FILES) {
        $current = array_pop($stack);
        $entries = @scandir($current);
        if ($entries === false) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $current . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                if (!in_array(strtolower($entry), IGNORED_DIRS, true)) {
                    $stack[] = $path;
                }
                continue;
            }
            $seen++;
            $latest = max($latest, (int) @filemtime($path));
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (isset(EXT_TECH[$ext])) {
                $tech = EXT_TECH[$ext];
                $counts[$tech] = ($counts[$tech] ?? 0) + 1;
            }
        }
    }

    return [
        'tech' => detectFramework($dir) ?? dominantTech($counts),
        'mtime' => $latest,
        'files' => $seen,
        'truncated' => $seen >= MAX_FILES,
    ];
}

// Les fichiers marqueurs priment sur le simple comptage d'extensions.
function detectFramework(string $dir): ?string
{
    if (is_file("$dir/wp-config.php") || is_dir("$dir/wp-content")) {
        return 'WordPress';
    }
    if (is_file("$dir/package.json")) {
        $pkg = json_decode((string) file_get_contents("$dir/package.json"), true) ?: [];
        $deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);
        foreach (['@angular/core' => 'Angular', 'electron' => 'Electron', 'react' => 'React',
                     'vue' => 'Vue.js', 'express' => 'Express'] as $dep => $tech) {
            if (isset($deps[$dep])) {
                return $tech;
            }
        }
        return isset($deps['typescript']) ? 'TypeScript' : 'Node.js';
    }
    if (is_file("$dir/composer.json")) {
        $composer = (string) file_get_contents("$dir/composer.json");
        if (str_contains($composer, 'laravel/framework')) {
            return 'Laravel';
        }
        return str_contains($composer, 'symfony/') ? 'Symfony' : 'PHP';
    }
    if (is_file("$dir/pom.xml") || is_file("$dir/build.gradle")) {
        return 'Java';
    }
    if (is_file("$dir/manifest.json")
        && str_contains((string) file_get_contents("$dir/manifest.json"), 'manifest_version')) {
        return 'Extension Chrome';
    }
    if (is_file("$dir/requirements.txt") || is_file("$dir/pyproject.toml")) {
        return 'Python';
    }
    return null;
}

function dominantTech(array $counts): string
{
    if (!$counts) {
        return 'Inconnu';
    }
    // Le Markdown ne compte que s'il n'y a rien d'autre.
    if (count($counts) > 1) {
        unset($counts['Markdown']);
    }
    arsort($counts);
    return array_key_first($counts);
}

function stackVersions(): array
{
    $xamppDir = dirname(__DIR__, 2);
    $ini = @parse_ini_file("$xamppDir/properties.ini", true) ?: [];
    $xampp = preg_replace('/-\d+$/', '', $ini['General']['base_stack_version'] ?? '') ?: '?';

    // mysqld --version : "...mysqld.exe  Ver 10.4.32-MariaDB for Win64..."
    $db = ['MySQL', '?'];
    $out = (string) @shell_exec('"' . $xamppDir . '/mysql/bin/mysqld.exe" --version 2>&1');
    if (preg_match('/Ver\s+([\d.]+)(-MariaDB)?/i', $out, $m)) {
        $db = [!empty($m[2]) ? 'MariaDB' : 'MySQL', $m[1]];
    }

    return ['XAMPP' => $xampp, 'PHP' => PHP_VERSION, $db[0] => $db[1]];
}

function relativeDate(int $ts): string
{
    $days = (int) floor((time() - $ts) / 86400);
    return match (true) {
        $days < 1 => "aujourd'hui",
        $days === 1 => 'hier',
        $days < 30 => "il y a $days jours",
        $days < 365 => 'il y a ' . floor($days / 30) . ' mois',
        default => 'il y a ' . floor($days / 365) . ' an' . ($days >= 730 ? 's' : ''),
    };
}

// Le scan complet est lent sous Windows : on garde le résultat en cache quelques minutes.
$cacheFile = sys_get_temp_dir() . '/htdocs-manager-cache.json';
$cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;

if (!isset($_GET['refresh']) && $cache && time() - $cache['at'] < CACHE_TTL) {
    $projects = $cache['projects'];
    $scannedAt = $cache['at'];
} else {
    $projects = [];
    foreach (scandir(ROOT) as $entry) {
        $path = ROOT . '/' . $entry;
        if ($entry[0] === '.' || $entry === 'manager' || !is_dir($path)) {
            continue;
        }
        $projects[] = ['name' => $entry] + scanProject($path);
    }
    usort($projects, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    $scannedAt = time();
    $versions = stackVersions();
    @file_put_contents($cacheFile, json_encode(['at' => $scannedAt, 'projects' => $projects, 'versions' => $versions]));
}
$versions ??= $cache['versions'] ?? stackVersions();

$techs = array_count_values(array_column($projects, 'tech'));
arsort($techs);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manager htdocs</title>
    <link rel="icon" type="image/svg+xml" href="/manager/favicon.svg">
    <style>
        :root {
            --bg: #f5f6f8; --card: #fff; --text: #1d2330; --muted: #6b7280; --border: #e3e6eb; --accent: #fb7a24;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #14161b; --card: #1d2027; --text: #e6e8ec; --muted: #9aa0aa; --border: #2c3039; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); }
        header { padding: 28px 24px 12px; max-width: 1200px; margin: 0 auto; }
        h1 { margin: 0 0 4px; font-size: 1.6rem; }
        h1 span { color: var(--accent); }
        .sub { color: var(--muted); margin: 0 0 18px; }
        .versions { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 12px; }
        .version {
            background: var(--card); border: 1px solid var(--border); border-radius: 6px;
            padding: 4px 10px; font-size: .85rem; color: var(--muted);
        }
        .version b { color: var(--text); font-weight: 600; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        input[type=search], select {
            padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px;
            background: var(--card); color: var(--text); font-size: .95rem;
        }
        input[type=search] { flex: 1; min-width: 200px; }
        main {
            max-width: 1200px; margin: 0 auto; padding: 12px 24px 40px;
            display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px;
        }
        .card {
            background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px;
            text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: 10px;
            border-top: 4px solid var(--tech); transition: transform .12s, box-shadow .12s;
        }
        .card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.08); }
        .name { font-weight: 600; font-size: 1.05rem; word-break: break-word; }
        .badge {
            align-self: flex-start; background: var(--tech); color: #fff; font-size: .78rem;
            padding: 3px 9px; border-radius: 99px; font-weight: 500;
        }
        .date { color: var(--muted); font-size: .85rem; margin-top: auto; }
        .empty { grid-column: 1 / -1; text-align: center; color: var(--muted); padding: 40px; display: none; }
    </style>
</head>
<body>
<header>
    <h1><span>htdocs</span> manager</h1>
    <div class="versions">
        <?php foreach ($versions as $name => $version): ?>
            <span class="version"><?= h($name) ?> <b><?= h($version) ?></b></span>
        <?php endforeach; ?>
    </div>
    <p class="sub"><?= count($projects) ?> projets &middot; <a href="../dashboard/" style="color:var(--muted)">dashboard XAMPP</a> &middot; <a href="/phpmyadmin/" style="color:var(--muted)">phpMyAdmin</a>
        &middot; analysé à <?= date('H:i', $scannedAt) ?> (<a href="?refresh=1" style="color:var(--muted)">rafraîchir</a>)</p>
    <div class="toolbar">
        <input type="search" id="q" placeholder="Rechercher un projet…" autofocus>
        <select id="tech">
            <option value="">Toutes les technos</option>
            <?php foreach ($techs as $tech => $n): ?>
                <option value="<?= h($tech) ?>"><?= h($tech) ?> (<?= $n ?>)</option>
            <?php endforeach; ?>
        </select>
        <select id="sort">
            <option value="name">Nom (A-Z)</option>
            <option value="date">Plus récents</option>
        </select>
    </div>
</header>
<main id="grid">
    <?php foreach ($projects as $p): ?>
        <a class="card" href="../<?= rawurlencode($p['name']) ?>/"
           style="--tech: <?= TECH_COLORS[$p['tech']] ?? '#888' ?>"
           data-name="<?= h(strtolower($p['name'])) ?>" data-tech="<?= h($p['tech']) ?>" data-mtime="<?= $p['mtime'] ?>">
            <div class="name"><?= h($p['name']) ?></div>
            <span class="badge"><?= h($p['tech']) ?></span>
            <div class="date" title="<?= $p['files'] ?><?= $p['truncated'] ? '+' : '' ?> fichiers analysés">
                Modifié le <?= date('d/m/Y à H:i', $p['mtime']) ?> &middot; <?= relativeDate($p['mtime']) ?>
            </div>
        </a>
    <?php endforeach; ?>
    <p class="empty" id="empty">Aucun projet ne correspond.</p>
</main>
<script>
    const grid = document.getElementById('grid');
    const cards = [...grid.querySelectorAll('.card')];
    const q = document.getElementById('q'), tech = document.getElementById('tech'), sort = document.getElementById('sort');

    function update() {
        const term = q.value.trim().toLowerCase();
        let visible = 0;
        cards.forEach(c => {
            const show = c.dataset.name.includes(term) && (!tech.value || c.dataset.tech === tech.value);
            c.style.display = show ? '' : 'none';
            visible += show;
        });
        document.getElementById('empty').style.display = visible ? 'none' : 'block';
    }

    function reorder() {
        const by = sort.value;
        cards.sort((a, b) => (by === 'date' ? b.dataset.mtime - a.dataset.mtime : 0)
            || a.dataset.name.localeCompare(b.dataset.name));
        cards.forEach(c => grid.insertBefore(c, document.getElementById('empty')));
    }

    // Les filtres sont mémorisés dans le navigateur pour survivre à l'actualisation.
    const STORAGE_KEY = 'htdocs-manager-filters';

    function save() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({q: q.value, tech: tech.value, sort: sort.value}));
        } catch (e) {}
    }

    function restore() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
            q.value = saved.q || '';
            if ([...tech.options].some(o => o.value === saved.tech)) tech.value = saved.tech;
            if ([...sort.options].some(o => o.value === saved.sort)) sort.value = saved.sort;
        } catch (e) {}
    }

    q.addEventListener('input', () => { update(); save(); });
    tech.addEventListener('change', () => { update(); save(); });
    sort.addEventListener('change', () => { reorder(); save(); });

    restore();
    reorder();
    update();
</script>
</body>
</html>
