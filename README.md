# htdocs manager

Petite page d'accueil pour XAMPP qui liste tous les dossiers de `htdocs` sous forme de fiches.

Chaque fiche affiche :

- le **nom du dossier** (la fiche entière est un lien vers `http://localhost/<dossier>/`) ;
- la **techno prédominante** (badge coloré) ;
- la **date de dernière modification** (date exacte + durée relative).

## Accès

- http://localhost/ : le fichier `htdocs/index.php` inclut directement le manager ;
- http://localhost/manager/ : accès direct.

Le dashboard XAMPP d'origine reste accessible via le lien en haut de page (`/dashboard/`).

## Fonctionnalités

- Versions de XAMPP, PHP et MySQL/MariaDB affichées en haut de page.
- Recherche par nom de dossier.
- Filtre par techno (avec le nombre de projets par techno).
- Tri par nom (par défaut) ou par date de modification.
- Les filtres sont mémorisés dans le `localStorage` du navigateur et retrouvés après actualisation.
- Mode clair / sombre selon le réglage du système.

## Comment c'est calculé

### Techno prédominante

1. Les fichiers marqueurs sont prioritaires :

   | Marqueur | Techno |
   | --- | --- |
   | `wp-config.php` ou `wp-content/` | WordPress |
   | `package.json` | Angular, Electron, React, Vue.js, Express, TypeScript ou Node.js selon les dépendances |
   | `composer.json` | Laravel, Symfony ou PHP |
   | `pom.xml` / `build.gradle` | Java |
   | `manifest.json` avec `manifest_version` | Extension Chrome |
   | `requirements.txt` / `pyproject.toml` | Python |

2. Sinon, c'est l'extension de fichier la plus fréquente qui l'emporte (`.php`, `.js`, `.py`, `.html`/`.css`, `.ps1`…). Le Markdown ne compte que s'il n'y a rien d'autre.
3. Aucun fichier reconnu : « Inconnu ».

### Date de dernière modification

C'est la date du fichier le plus récent du dossier (et non celle du dossier lui-même).

### Versions

- **XAMPP** : `base_stack_version` dans `C:\xampp\properties.ini` ;
- **PHP** : version du PHP qui sert la page (`PHP_VERSION`) ;
- **MySQL / MariaDB** : sortie de `mysql\bin\mysqld.exe --version` (fonctionne même si le serveur MySQL est arrêté).

### Limites du scan

- Les dossiers `node_modules`, `vendor`, `.git`, `dist`, `build`, `target`, `venv`, `__pycache__`… sont ignorés.
- Le scan s'arrête à 4000 fichiers par projet (`MAX_FILES`).

## Cache

Le scan complet prend plusieurs secondes sous Windows. Le résultat est donc mis en cache pendant 10 minutes (`CACHE_TTL`) dans le dossier temporaire du système (`htdocs-manager-cache.json`). Les versions sont mises en cache en même temps.

Pour forcer une nouvelle analyse, cliquer sur **rafraîchir** en haut de page ou ouvrir `?refresh=1`.

## Fichiers

| Fichier | Rôle |
| --- | --- |
| `manager/index.php` | La page (PHP + CSS + JS, sans dépendance) |
| `manager/favicon.svg` | L'icône (table de mixage) |
| `htdocs/index.php` | Inclut le manager à la racine |
| `htdocs/index.php.bak` | Ancien `index.php` (redirection vers `/dashboard/`) |

## Configuration

Les constantes en haut de `manager/index.php` :

- `MAX_FILES` : nombre max de fichiers analysés par projet ;
- `CACHE_TTL` : durée du cache en secondes ;
- `IGNORED_DIRS` : dossiers exclus du scan ;
- `EXT_TECH` : correspondance extension → techno ;
- `TECH_COLORS` : couleur du badge pour chaque techno.

## Revenir au dashboard XAMPP

Remettre l'ancien fichier en place :

```bash
cp /c/xampp/htdocs/index.php.bak /c/xampp/htdocs/index.php
```
