# Content Management Frame (CMF)

**Das einfache, dateibasierte Open-Source-CMS — für Menschen, Suchmaschinen und KI-Agenten.**

![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green) ![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue) ![Keine Datenbank](https://img.shields.io/badge/Datenbank-keine-orange)

Kein Framework. Keine Datenbank. Keine Build-Tools.
Nur: PHP, JSON, HTML5, wenig CSS, optional JavaScript.

**Website & Download:** https://cmf.brosemedien.de
**Live-Demos:** https://cmf.brosemedien.de/demos

---

## Warum CMF?

* **Einfach** — ZIP entpacken, per FTP hochladen, loslegen. Kein Installer, keine Datenbank-Konfiguration. ([Mehr](https://cmf.brosemedien.de/einfaches-cms))
* **Ohne Datenbank** — Inhalte als JSON-Dateien. Backup = Ordner kopieren. ([Mehr](https://cmf.brosemedien.de/cms-ohne-datenbank))
* **KI-bereit** — `llms.txt`, jede Seite als Markdown (`.md` an die URL anhängen), REST-API mit 30+ Endpunkten, eigene Anleitung für Maschinen. ([Mehr](https://cmf.brosemedien.de/ki-cms))
* **SEO inklusive** — Sitemap, RSS-Feed, Canonical-URLs, strukturierte Daten (schema.org), Meta-Felder. Automatisch.
* **Wartungsarm** — Ein-Klick-Systemupdate mit automatischem Backup und Rollback. Kein Plugin-System, das brechen könnte.

---

## Funktionen

* Visueller Block-Editor mit 8 Blocktypen (heading, text, image, list, buttons, columns, html, blog_overview)
* Automatische Navigation aus veroeffentlichten Seiten (mit Untermenues)
* Blog/News mit Kategorien, Beitragsbildern und RSS-Feed
* Theme-Editor: Farben, Schriften, Abstaende ohne Code (10 Font-Familien inklusive)
* Custom CSS fuer wiederverwendbare Klassen
* Media-Upload mit MIME-Pruefung und SVG-Bereinigung
* REST-API fuer externe Tools, Skripte und KI-Agenten
* `llms.txt` + Markdown-Endpunkte fuer KI-Crawler
* Live-Suche ueber statischen Such-Index
* Export/Import (einzelne Seiten oder komplette Webseite, ZIP oder API)
* Header/Footer als globale Inhalte

---

## Installation in 3 Schritten

1. **Herunterladen:** ZIP von https://cmf.brosemedien.de/download laden und entpacken
2. **Hochladen:** Ordnerinhalt in das Web-Root-Verzeichnis kopieren (DocumentRoot auf `public/` zeigen lassen)
3. **Loslegen:** Domain im Browser aufrufen — das System ist sofort einsatzbereit

Voraussetzungen: PHP 8.1+, Apache mit mod_rewrite, Schreibrechte auf `content/`, `config/`, `public/media/` sowie `public/` und `public/assets/css/` (dort erzeugt das System `sitemap.xml`, `robots.txt`, `feed.xml`, `llms.txt`, `search-index.json` und `theme.css` zur Laufzeit).
Ausfuehrliche Anleitung: https://cmf.brosemedien.de/installationsanleitung

Hinweis: Das Paket enthaelt bewusst **keine Favicons** — eigene Dateien (`favicon.ico`, `apple-touch-icon.png`, `site.webmanifest`) einfach in `public/` ablegen.

---

## Architektur

```
project/
  app/
    core/       Bootstrap, Router, Renderer, Markdown, Auth, ApiAuth, PageSchema, Theme, Storage, Sanitizer, Sitemap, SearchIndex, Slug, Csrf
    admin/      PagesController, BlogController, MediaController, SettingsController, ThemeController, UsersController, PartialsController, UpdateController
    api/        PagesController, BlogController, MediaController, GlobalsController
    views/      admin/layout.php
  config/
    site.json, styles.json, users.json, login_attempts.json
  content/
    pages.json          Seitenindex
    pages/<id>.json     Seiteninhalt (meta + content.blocks)
    blog.json           Blogindex
    blog/<id>.json      Bloginhalt
    globals/            header.json, footer.json
  public/
    index.php, admin.php, api.php, .htaccess
    assets/css/         base.css, theme.css (auto-generiert), custom.css, admin.css
    assets/fonts/       FontName-Schnitt.ttf (10 Familien, je 3 Schnitte)
    assets/js/          site.js
    media/YYYY/MM/      Uploads
    files/              Downloads
  version.json
```

---

## Datenstruktur

Seitenindex (`content/pages.json`): Array mit id, slug, title, status, nav-Objekt, Zeitstempel.

Seiteninhalt (`content/pages/<id>.json`):

```json
{
  "meta": { "title": "Seitentitel", "description": "Kurzbeschreibung" },
  "content": { "blocks": [{ "id": "h1_start", "type": "heading", "data": { "level": 1, "text": "Titel" } }] }
}
```

Blog: Gleiche Struktur. Index in `content/blog.json` mit slug, categories, posts-Array.

---

## API & KI

Einstiegspunkt: `/api.php` mit Bearer-Token-Authentifizierung (Token im Admin unter Benutzer anlegen).

```
GET  /llms.txt                      Wegweiser fuer KI-Crawler (llmstxt.org)
GET  /{slug}.md                     Jede Seite als Markdown
GET  /api.php?a=pages               Alle Seiten als JSON
POST /api.php?a=page_update&id=…    Seite aendern
POST /api.php?a=blog_create         Beitrag anlegen
```

Vollstaendige API-Referenz: **README_KI.md** (im Paket) bzw. https://cmf.brosemedien.de/api-anleitung
Anleitung fuer KI-Agenten: https://cmf.brosemedien.de/maschinen-anleitung

---

## Theme

Design ueber `config/styles.json` oder den visuellen Theme-Editor: Container, Abstaende, Radien, Farben, Schriftgroessen, Fonts. Beim Speichern wird `theme.css` automatisch generiert. Eigene Klassen in `custom.css`.

---

## Sicherheit

CSRF-Schutz, Passwort-Hashes, API-Token-Hash, HTML-Sanitizing fuer Textbloecke,
SVG-Bereinigung beim Upload, MIME-Pruefung beim Import, Update-Server-Allowlist,
Schutz-.htaccess fuer `config/` und `content/`, Skript-Sperre fuer Upload-Ordner.

---

## Lizenz

MIT — frei nutzen, veraendern, verbreiten, auch kommerziell. Einzige Bedingung: Der Lizenztext bleibt im Projekt erhalten.

---

## Philosophie

> Inhalte zuerst. Struktur vor Design. Technik statt CMS-Overhead.

Mehr dazu: https://cmf.brosemedien.de/philosophie
