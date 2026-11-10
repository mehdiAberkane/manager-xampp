# htdocs manager

Petite page d'accueil pour XAMPP qui liste tous les dossiers de `htdocs` sous forme de fiches.

Chaque fiche affiche :

- le **nom du dossier** (la fiche entière est un lien vers `http://localhost/<dossier>/`, ou vers son vhost dans un nouvel onglet s'il en a un) ;
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

## Vhosts

La page http://localhost/manager/config.php (lien **vhosts** en haut du manager) permet de donner un nom de domaine à chaque projet :

- **Nom de domaine** : par exemple `mon-projet.localhost`. Le bouton « Utiliser … » propose un nom à partir du dossier. Laisser vide pour supprimer le vhost.
- **Dossier web** (optionnel) : sous-dossier servi par Apache, par exemple `public` pour Symfony ou Laravel (proposé automatiquement si `public/` existe).

Une fois enregistré, la fiche du projet ouvre `http://<domaine>/` dans un nouvel onglet.

### Fonctionnement

- Les vhosts sont stockés dans `manager/vhosts.json` (ignoré par git).
- Le manager génère `apache/conf/extra/httpd-manager-vhosts.conf` et ajoute une fois pour toutes la ligne `IncludeOptional "conf/extra/httpd-manager-vhosts.conf"` à la fin de `httpd-vhosts.conf`.
- Avant d'enregistrer, la conf est vérifiée avec `httpd.exe -t`. Si Apache la refuse, l'ancienne est remise en place et rien n'est modifié.
- Un vhost `localhost` vers `htdocs` est toujours déclaré en premier, pour que http://localhost/ continue d'afficher le manager.
- **Apache doit être redémarré** pour prendre en compte les changements (XAMPP Control Panel → Apache → Stop puis Start). Un bandeau le rappelle tant que ce n'est pas fait : la conf générée pose une variable `MANAGER_VHOSTS` (hash de la conf) que la page compare à la conf enregistrée.

### Domaines

- Les domaines en **`.localhost`** pointent automatiquement sur 127.0.0.1 dans Chrome, Edge et Firefox : rien à configurer.
- Pour un autre domaine (ex. `mon-projet.test`), il faut ajouter `127.0.0.1 mon-projet.test` dans `C:\Windows\System32\drivers\etc\hosts` (en administrateur). La page de config affiche la ligne à ajouter si elle manque.

### Sécurité

La page de configuration modifie la conf d'Apache, elle est donc :

- accessible uniquement depuis la machine (`127.0.0.1` / `::1`) et via `localhost` (protection contre le DNS rebinding) ;
- protégée par un jeton CSRF ;
- limitée aux dossiers existants de `htdocs`, avec des noms de domaine et des dossiers web validés.

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
| `manager/index.php` | La liste des projets |
| `manager/config.php` | La configuration des vhosts |
| `manager/vhosts.php` | Fonctions partagées (liste des projets, génération et vérification des vhosts) |
| `manager/style.css` | Styles communs aux deux pages |
| `manager/favicon.svg` | L'icône (table de mixage) |
| `manager/vhosts.json` | Vhosts configurés (local, non versionné) |
| `apache/conf/extra/httpd-manager-vhosts.conf` | Conf Apache générée |
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
