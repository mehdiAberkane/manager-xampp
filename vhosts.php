<?php
// Fonctions partagées : liste des projets et gestion des vhosts Apache.

const ROOT = __DIR__ . '/..';
const APACHE_DIR = __DIR__ . '/../../apache';
const VHOSTS_FILE = __DIR__ . '/vhosts.json';
const VHOSTS_CONF = APACHE_DIR . '/conf/extra/httpd-manager-vhosts.conf';
const VHOSTS_INCLUDE_IN = APACHE_DIR . '/conf/extra/httpd-vhosts.conf';
const VHOSTS_INCLUDE = 'IncludeOptional "conf/extra/httpd-manager-vhosts.conf"';
const HOSTS_FILE = 'C:/Windows/System32/drivers/etc/hosts';
const HOST_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function projectNames(): array
{
    $names = [];
    foreach (scandir(ROOT) as $entry) {
        if ($entry[0] !== '.' && $entry !== 'manager' && is_dir(ROOT . '/' . $entry)) {
            $names[] = $entry;
        }
    }
    usort($names, 'strcasecmp');
    return $names;
}

function loadVhosts(): array
{
    return is_file(VHOSTS_FILE) ? (json_decode((string) file_get_contents(VHOSTS_FILE), true) ?: []) : [];
}

function vhostUrl(array $vhost): string
{
    return 'http://' . $vhost['host'] . '/';
}

function suggestedHost(string $project): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($project)), '-') . '.localhost';
}

// Les *.localhost pointent déjà sur 127.0.0.1 dans les navigateurs ; les autres domaines doivent être dans le fichier hosts.
function needsHostsEntry(string $host): bool
{
    if (str_ends_with($host, '.localhost')) {
        return false;
    }
    $hosts = (string) @file_get_contents(HOSTS_FILE);
    return !preg_match('/^\s*(127\.0\.0\.1|::1)\s+([^#\n]*\s)?' . preg_quote($host, '/') . '(\s|$)/mi', $hosts);
}

/**
 * Valide les vhosts envoyés par le formulaire. Seuls les dossiers existants de htdocs sont acceptés.
 * @return array{0: array, 1: array} [vhosts valides, erreurs par projet]
 */
function validateVhosts(array $input, array $projects): array
{
    $vhosts = [];
    $errors = [];
    $usedHosts = [];

    foreach ($projects as $project) {
        $host = strtolower(trim((string) ($input[$project]['host'] ?? '')));
        $root = trim(str_replace('\\', '/', (string) ($input[$project]['root'] ?? '')), " /");
        if ($host === '') {
            continue;
        }
        if (!preg_match(HOST_PATTERN, $host) || strlen($host) > 253) {
            $errors[$project] = 'Nom de domaine invalide (ex. ' . suggestedHost($project) . ').';
        } elseif ($host === 'localhost' || isset($usedHosts[$host])) {
            $errors[$project] = "« $host » est déjà utilisé.";
        } elseif ($root !== '' && (!preg_match('#^[\w .-]+(/[\w .-]+)*$#u', $root)
                || in_array('..', explode('/', $root), true) || !is_dir(ROOT . "/$project/$root"))) {
            $errors[$project] = "Le dossier « $root » n'existe pas dans le projet.";
        }
        $usedHosts[$host] = true;
        $vhosts[$project] = ['host' => $host, 'root' => $root];
    }

    return [$vhosts, $errors];
}

function configHash(array $vhosts): string
{
    return $vhosts ? substr(sha1(json_encode($vhosts)), 0, 12) : '';
}

// Apache expose le hash de la conf chargée via SetEnv : s'il diffère, Apache n'a pas été redémarré.
function isApplied(array $vhosts): bool
{
    return ($_SERVER['MANAGER_VHOSTS'] ?? '') === configHash($vhosts);
}

function renderConf(array $vhosts): string
{
    if (!$vhosts) {
        return "# Généré par htdocs manager (manager/config.php) : ne pas modifier à la main.\n";
    }
    $htdocs = str_replace('\\', '/', realpath(ROOT));
    $hash = configHash($vhosts);
    $conf = <<<CONF
        # Généré par htdocs manager (manager/config.php) : ne pas modifier à la main.

        # Vhost par défaut (le premier déclaré) : garde http://localhost/ sur htdocs.
        <VirtualHost *:80>
            ServerName localhost
            DocumentRoot "$htdocs"
            SetEnv MANAGER_VHOSTS $hash
        </VirtualHost>

        CONF;
    foreach ($vhosts as $project => $vhost) {
        $docRoot = rtrim("$htdocs/$project/{$vhost['root']}", '/');
        $conf .= <<<CONF

            # $project
            <VirtualHost *:80>
                ServerName {$vhost['host']}
                DocumentRoot "$docRoot"
            </VirtualHost>

            CONF;
    }
    return $conf;
}

/** @return array{0: bool, 1: string} [syntaxe OK, sortie de httpd -t] */
function testApacheConfig(): array
{
    // Lancé depuis Apache, httpd.exe hérite de AP_PARENT_PID et se prend pour un processus enfant :
    // on retire les variables AP_* pour qu'il fasse un simple test de syntaxe.
    $env = array_filter(getenv(), fn($key) => !str_starts_with(strtoupper($key), 'AP_'), ARRAY_FILTER_USE_KEY);
    $proc = proc_open([realpath(APACHE_DIR . '/bin/httpd.exe'), '-t'],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, realpath(APACHE_DIR), $env);
    if (!is_resource($proc)) {
        return [false, 'Impossible de lancer httpd.exe -t.'];
    }
    $output = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    return [proc_close($proc) === 0, $output];
}

/** Écrit la conf Apache et vhosts.json. Retourne un message d'erreur, ou null si tout s'est bien passé. */
function saveVhosts(array $vhosts): ?string
{
    $includer = (string) @file_get_contents(VHOSTS_INCLUDE_IN);
    if (!str_contains($includer, VHOSTS_INCLUDE)
        && @file_put_contents(VHOSTS_INCLUDE_IN, "\n# Vhosts gérés par htdocs manager\n" . VHOSTS_INCLUDE . "\n", FILE_APPEND) === false) {
        return 'Impossible de modifier ' . VHOSTS_INCLUDE_IN . '.';
    }

    $previous = is_file(VHOSTS_CONF) ? file_get_contents(VHOSTS_CONF) : null;
    if (@file_put_contents(VHOSTS_CONF, renderConf($vhosts)) === false) {
        return 'Impossible d\'écrire ' . VHOSTS_CONF . '.';
    }

    // On ne garde la nouvelle conf que si Apache l'accepte, sinon il refuserait de redémarrer.
    [$ok, $output] = testApacheConfig();
    if (!$ok) {
        $previous === null ? @unlink(VHOSTS_CONF) : file_put_contents(VHOSTS_CONF, $previous);
        return "Apache refuse la configuration, rien n'a été modifié :\n$output";
    }

    file_put_contents(VHOSTS_FILE, json_encode($vhosts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return null;
}
