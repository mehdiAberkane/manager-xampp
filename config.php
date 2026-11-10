<?php
// Configuration des vhosts : un nom de domaine (et éventuellement un dossier web) par projet.

require_once __DIR__ . '/vhosts.php';

// Cette page modifie la conf d'Apache : elle n'est accessible que depuis la machine elle-même,
// et via localhost uniquement (protège aussi contre le DNS rebinding).
$hostHeader = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !in_array($hostHeader, ['localhost', '127.0.0.1', '[::1]'], true)) {
    http_response_code(403);
    exit('Accessible uniquement depuis http://localhost/manager/config.php');
}

session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

$projects = projectNames();
$vhosts = loadVhosts();
$form = $vhosts;
$errors = [];
$message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Jeton de formulaire invalide, rechargez la page.');
    }
    $input = is_array($_POST['vhosts'] ?? null) ? $_POST['vhosts'] : [];
    [$newVhosts, $errors] = validateVhosts($input, $projects);

    if (!$errors) {
        $error = saveVhosts($newVhosts);
        if ($error === null) {
            $_SESSION['flash'] = ['ok', 'Vhosts enregistrés (' . count($newVhosts) . ').'];
            header('Location: config.php', true, 303);
            exit;
        }
        $message = ['error', $error];
    }
    $form = $input;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vhosts htdocs</title>
    <link rel="icon" type="image/svg+xml" href="/manager/favicon.svg">
    <link rel="stylesheet" href="/manager/style.css">
</head>
<body>
<header>
    <h1><span>htdocs</span> vhosts</h1>
    <p class="sub"><a href="/">&larr; retour aux projets</a> &middot; <?= count($vhosts) ?> vhost(s) configuré(s)</p>

    <?php if ($message): ?>
        <div class="banner <?= h($message[0]) ?>"><?= h($message[1]) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="banner error">Certaines lignes sont invalides, rien n'a été enregistré.</div>
    <?php elseif (!isApplied($vhosts)): ?>
        <div class="banner">Redémarrez Apache pour appliquer les changements : XAMPP Control Panel &rarr; Apache &rarr; Stop puis Start.</div>
    <?php endif; ?>

    <p class="sub">
        Les domaines en <b>.localhost</b> fonctionnent directement dans Chrome, Edge et Firefox.
        Pour un autre domaine, il faut ajouter une ligne dans le fichier hosts de Windows (droits administrateur).
        Laissez le domaine vide pour supprimer un vhost.
    </p>
    <div class="toolbar">
        <input type="search" id="q" placeholder="Rechercher un projet…">
    </div>
</header>

<form method="post" class="config">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <table>
        <thead>
        <tr><th>Projet</th><th>Nom de domaine</th><th>Dossier web (optionnel)</th><th>État</th></tr>
        </thead>
        <tbody>
        <?php foreach ($projects as $project):
            $host = (string) ($form[$project]['host'] ?? '');
            $root = (string) ($form[$project]['root'] ?? '');
            $saved = $vhosts[$project] ?? null;
            $field = 'vhosts[' . h($project) . ']';
            ?>
            <tr data-name="<?= h(strtolower($project)) ?>">
                <td class="project"><?= h($project) ?></td>
                <td>
                    <input type="text" name="<?= $field ?>[host]" value="<?= h($host) ?>"
                           placeholder="<?= h(suggestedHost($project)) ?>" spellcheck="false" autocomplete="off">
                    <?php if (isset($errors[$project])): ?>
                        <div class="hint error"><?= h($errors[$project]) ?></div>
                    <?php elseif ($host === ''): ?>
                        <button type="button" class="link-btn suggest"
                                data-root="<?= is_dir(ROOT . "/$project/public") ? 'public' : '' ?>">Utiliser <?= h(suggestedHost($project)) ?></button>
                    <?php elseif ($saved && needsHostsEntry($saved['host'])): ?>
                        <div class="hint warn">À ajouter dans le fichier hosts : 127.0.0.1 <?= h($saved['host']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <input type="text" name="<?= $field ?>[root]" value="<?= h($root) ?>"
                           placeholder="<?= is_dir(ROOT . "/$project/public") ? 'public' : 'racine du projet' ?>" spellcheck="false" autocomplete="off">
                </td>
                <td class="status">
                    <?php if ($saved): ?>
                        <a href="<?= h(vhostUrl($saved)) ?>" target="_blank" rel="noopener">Ouvrir &#8599;</a>
                    <?php else: ?>
                        <span class="hint">&mdash;</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="save-bar"><button type="submit">Enregistrer</button></div>
</form>
<script>
    const rows = [...document.querySelectorAll('tbody tr')];
    document.getElementById('q').addEventListener('input', e => {
        const term = e.target.value.trim().toLowerCase();
        rows.forEach(r => r.style.display = r.dataset.name.includes(term) ? '' : 'none');
    });

    // « Utiliser xxx.localhost » remplit le domaine, et le dossier public/ s'il existe.
    document.querySelectorAll('.suggest').forEach(btn => btn.addEventListener('click', () => {
        const [host, root] = btn.closest('tr').querySelectorAll('input');
        host.value = host.placeholder;
        if (!root.value) root.value = btn.dataset.root;
        btn.remove();
    }));
</script>
</body>
</html>
