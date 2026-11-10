<?php
// Manager : liste les projets de htdocs avec leur techno principale et leur dernière modification.

require_once __DIR__ . '/vhosts.php';

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
    foreach (projectNames() as $name) {
        $projects[] = ['name' => $name] + scanProject(ROOT . '/' . $name);
    }
    $scannedAt = time();
    $versions = stackVersions();
    @file_put_contents($cacheFile, json_encode(['at' => $scannedAt, 'projects' => $projects, 'versions' => $versions]));
}
$versions ??= $cache['versions'] ?? stackVersions();

$techs = array_count_values(array_column($projects, 'tech'));
arsort($techs);

$vhosts = loadVhosts();

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manager htdocs</title>
    <link rel="icon" type="image/svg+xml" href="/manager/favicon.svg">
    <link rel="stylesheet" href="/manager/style.css">
</head>
<body>
<header>
    <h1><span>htdocs</span> manager</h1>
    <div class="versions">
        <?php foreach ($versions as $name => $version): ?>
            <span class="version"><?= h($name) ?> <b><?= h($version) ?></b></span>
        <?php endforeach; ?>
    </div>
    <p class="sub"><?= count($projects) ?> projets &middot; <a href="/manager/config.php">vhosts</a>
        &middot; <a href="/dashboard/">dashboard XAMPP</a> &middot; <a href="/phpmyadmin/">phpMyAdmin</a>
        &middot; analysé à <?= date('H:i', $scannedAt) ?> (<a href="?refresh=1">rafraîchir</a>)</p>
    <?php if (!isApplied($vhosts)): ?>
        <div class="banner">Les vhosts ont changé : redémarrez Apache (XAMPP Control Panel &rarr; Apache &rarr; Stop puis Start).</div>
    <?php endif; ?>
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
<main class="grid" id="grid">
    <?php foreach ($projects as $p): $vhost = $vhosts[$p['name']] ?? null; ?>
        <a class="card" style="--tech: <?= TECH_COLORS[$p['tech']] ?? '#888' ?>"
           <?php if ($vhost): ?>href="<?= h(vhostUrl($vhost)) ?>" target="_blank" rel="noopener"
           <?php else: ?>href="/<?= rawurlencode($p['name']) ?>/"<?php endif; ?>
           data-name="<?= h(strtolower($p['name'])) ?>" data-tech="<?= h($p['tech']) ?>" data-mtime="<?= $p['mtime'] ?>">
            <div class="name"><?= h($p['name']) ?></div>
            <span class="badge"><?= h($p['tech']) ?></span>
            <?php if ($vhost): ?>
                <div class="vhost"><?= h($vhost['host']) ?> &#8599;</div>
            <?php endif; ?>
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
