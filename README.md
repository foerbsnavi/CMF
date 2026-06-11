# Content Management Frame

Minimalistisches, dateibasiertes CMS fuer schnelle, saubere HTML-Webseiten.

Kein Framework. Keine Datenbank. Keine Build-Tools.

Nur: PHP, JSON, HTML5, wenig CSS, optional JavaScript.

---

## Eigenschaften

* Dateibasiert – Inhalte als JSON, keine Datenbank
* Sauberes HTML5 Rendering
* Blockbasiertes Content-System (heading, text, image, list, buttons, columns, html, blog_overview)
* Automatische Navigation aus veroeffentlichten Seiten
* REST-API fuer externe Tools und KI
* Media-Upload mit automatischer Pfadvergabe
* Theme-Konfiguration ueber JSON (Farben, Schriften, Abstaende)
* Custom CSS fuer wiederverwendbare Klassen
* Header/Footer als globale Inhalte
* Blog mit eigenem Admin-Bereich und API
* Export/Import (einzelne Seiten oder komplette Webseite)

---

## Architektur

```
project/
  app/
    core/       Bootstrap, Router, Renderer, Auth, ApiAuth, PageSchema, Theme, Storage, Sanitizer, Sitemap, Slug, Csrf
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
    files/              Downloads (cmf_latest.zip, README_KI.md)
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

## Navigation

Automatisch aus veroeffentlichten Seiten erzeugt. Sortiert nach `nav.order`. Untermenues ueber `nav.parent` (Seiten-ID). `nav.show` steuert Sichtbarkeit.

---

## Theme

Design ueber `config/styles.json`: Container, Abstaende, Radien, Farben, Schriftgroessen, Fonts. Beim Speichern wird `theme.css` automatisch generiert. Eigene Klassen in `custom.css`.

---

## Admin Backend

Login: `/admin.php`. Bereiche: Seiten, Blog, Header/Footer, Theme, Media, Benutzer, Einstellungen.

---

## Sicherheit

CSRF-Schutz, Passwort-Hashes, API-Token-Hash, HTML-Sanitizing fuer Textbloecke,
SVG-Bereinigung beim Upload, Schutz-.htaccess fuer `config/` und `content/`.

---

## Hinweise zur Installation

- Installationsanleitung: https://cmf.brosemedien.de/installationsanleitung
- Das Paket enthaelt bewusst **keine Favicons** — eigene Dateien
  (`favicon.ico`, `apple-touch-icon.png`, `site.webmanifest` etc.)
  einfach in `public/` ablegen.

---

## API

Einstiegspunkt: `/api.php` mit Bearer-Token-Authentifizierung.

Vollstaendige API-Referenz: **README_KI.md**

---

## Philosophie

> Inhalte zuerst. Struktur vor Design. Technik statt CMS-Overhead.
