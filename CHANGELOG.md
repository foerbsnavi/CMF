# Changelog

Alle nennenswerten Aenderungen am Content Management Frame.
Aktuelle Version und maschinenlesbarer Changelog: `GET api.php?a=version_check` (oeffentlich).

## 1.16.0 — 2026-07-21

- Neu: Formular-Bestaetigungsmail an den Absender (Option "confirm" pro Formular) — der Absender erhaelt eine Kopie seiner Angaben; mit Drossel gegen Missbrauch als Mail-Verstaerker (max. 5/Stunde je IP, 60/Stunde global)
- Neu: Content-Security-Policy pro Installation erweiterbar ueber config/site.json "csp" (fuer externe Widgets/Embeds) — die strenge Basis bleibt, kritische Direktiven sind nicht aufweichbar; CSP wird jetzt dynamisch von PHP gesendet statt statisch in .htaccess
- Sicherheit: konfigurierbare CSP-Quellen werden streng gesaeubert (nur Keywords/Schemata/Host-Quellen, kein CRLF/unsafe-eval)

## 1.15.0 — 2026-07-21

- Neu: Blocktyp "Formular" — Feld-Baukasten (Text, E-Mail, Telefon, Mehrzeilig, Auswahl, Checkbox, Radio) mit Pflichtfeld-Option
- Neu: Admin-Bereich "Einsendungen" — Formular-Einsendungen lesen, als gelesen markieren, loeschen, als CSV exportieren
- Formulare: Speicherung der Einsendungen und optionaler E-Mail-Versand (mit Schutz vor Header-Injection)
- Formulare: Spam-Schutz per Honeypot und signierter Zeit-Falle — ganz ohne Cookie (datenschutz- und cache-freundlich)
- Formulare: barrierefreie Felder (Labels, Pflichtkennzeichnung, Fehlermeldungen nach WCAG) und DSGVO-Einwilligung als Checkbox
- Sicherheit: CSV-Export gegen Formel-Injection abgesichert

## 1.14.0 — 2026-07-18

- Sicherheit: Security-Header via public/.htaccess (CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy)
- Sicherheit: URL-Schema-Whitelist fuer Links in Text- und Button-Bloecken — javascript:-URLs (auch entity-verschleiert) werden neutralisiert
- Sicherheit: Session-Cookie mit HttpOnly, Secure (bei HTTPS) und SameSite=Lax, dazu session.use_strict_mode
- Fix: feed.xml und llms.txt existieren sofort nach der Installation (Erststart-Erzeugung), nicht erst nach dem ersten Speichern
- Robustheit: sitemap.xml, robots.txt, feed.xml, llms.txt, search-index.json und theme.css werden atomar geschrieben; Schreibfehler landen im error_log statt still verschluckt zu werden
- Doku: README nennt jetzt alle noetigen Schreibrechte (auch public/ und public/assets/css/)
- Build: ZIP-Erstellung plattformneutral mit archiver (garantiert Forward-Slashes im Archiv)

## 1.13.1 — 2026-06-11

- Fix: Download-Seite nutzt das helle Design-System (Build-Script schrieb den alten Verlaufs-Hero zurueck)
- Build: Versions-Update auf der Download-Seite schreibt class statt Inline-Style
- custom.css konsolidiert: gemeinsame Basis fuer Glas-Flaechen, Hero/CTA, Lead-Typo, Flex- und Wrap-Reihen

## 1.13.0 — 2026-06-11

- Design-System 'Refined Light-Tech' in custom.css: einheitliche Karten, Tabellen, Boxen, FAQ, Hero, Badges — helle Glas-Optik, Pill-Buttons, 2 Akzentfarben
- Das Design-System wird jetzt mit dem Download-Paket ausgeliefert (custom.css wird beim Build nicht mehr geleert)
- Theme-Standard: Raleway (Ueberschriften) + Lato (Fliesstext), ruhige Typo-Skala, Ink-Farbtoene
- Heller Hero-Stil (hero-banner) ersetzt den dunklen Verlauf — gilt automatisch fuer alle Seiten
- Neue Utility-Klassen: panel, eyebrow, measure, flex-split, img-cover, text-xs
- Sanfte Seiten-Einstiegs-Animation (respektiert prefers-reduced-motion)

## 1.12.0 — 2026-06-11

- Neu: Jede Seite und jeder Blog-Post als Markdown abrufbar — einfach .md an die URL anhaengen
- Neu: llms.txt nach llmstxt.org-Standard, automatisch generiert und gepflegt (KI-Crawler-Wegweiser)
- Neu: RSS-Feed (feed.xml) fuer den Blog, mit alternate-Link im Seitenkopf
- Neu: Strukturierte Daten (ld+json): WebSite, Article fuer Blog-Posts, optional SoftwareApplication per site.json-Flag software_schema
- Neu: Blog-Beitraege koennen per API umsortiert werden (order in blog_update), neue Beitraege erscheinen oben
- Design: neue Custom-CSS-Klassen (Produkt-Hero, Vergleichstabelle, FAQ-Liste, Footer-Spalten)
- Sicherheit: JSON_HEX_TAG gegen Script-Breakout in ld+json, nosniff-Header fuer Markdown-Ausgabe
- site.json: og_image und software_schema bleiben bei site_update erhalten

## 1.11.0 — 2026-06-11

- Fix: Blog-Beitraege erhalten ihre eigene Canonical-URL statt der Blog-Uebersicht
- Fix: Such-Treffer fuer Blog-Beitraege verlinken auf den konfigurierten Blog-Slug
- Fix: Medien-Tabelle colspan, Login-Countdown nutzt json_encode
- Performance: statischer Such-Index (search-index.json), nur aktive Fonts in theme.css
- Performance: immutable-Caching fuer CSS/JS, width/height am Bild-Block gegen Layout-Spruenge
- Sicherheit: Update-Server-Allowlist (HTTPS + Host), realpath-Pruefung beim Entpacken von ZIPs
- Sicherheit: SVG-Uploads werden bereinigt, MIME-Pruefung beim Medien-Import
- Sicherheit: Schutz-.htaccess fuer config/ und content/, Skript-Sperre fuer media/ und files/
- Sicherheit: site_import und pages_import validieren Schema und Seiten-IDs, Custom-CSS-Filter gegen PHP-Tags
- SEO: HTTPS-Redirect, robots.txt mit Disallow fuer admin.php/api.php, sitemap-lastmod per Datei-Datum
- Barrierefreiheit: Hoch/Runter-Buttons fuer Listen-Sortierung, Formular-Labels mit for/id, Fokus-Fallback
- Admin: Inline-Styles durch CSS-Klassen ersetzt (admin.css v3)
- Build: --sync-github spiegelt das Download-Paket ins GitHub-Repo

## 1.2.1 bis 1.10.4 — April 2026

Laufende Weiterentwicklung der Anfangszeit ohne Einzelaufzeichnung.

## 1.2.0 — 2026-04-15

- Custom CSS System: eigene Klassen ueber Admin und API verwalten
- 25+ vordefinierte Utility-Klassen (hero-banner, card, callout, stats-grid, etc.)
- 70 HTML-Bloecke bereinigt: Inline-CSS durch CSS-Klassen ersetzt
- API-Endpunkte: custom_css (GET) und custom_css_update (POST)
- site_bundle liefert jetzt custom_css mit
- 10 Schriftfamilien mit je 3 Schnitten (30 Fonts)
- Alle Dokumentationen aktualisiert (API-Anleitung, Humanoid-Anleitung, READMEs)

## 1.1.0 — 2026-04-15

- Admin: Seitenliste sortiert nach Reihenfolge mit Hierarchie-Anzeige
- Admin: Visueller Theme-Editor mit Color-Pickern, Textfeldern und Font-Dropdowns
- Admin: Media-Thumbnails in der Medienliste
- Admin: Passwort-Aendern-Funktion fuer Benutzer
- Admin: Benutzername im Admin-Header sichtbar
- Admin: Flash-Meldungen nach Speichern/Aendern
- Admin: beforeunload-Warnung bei ungespeicherten Aenderungen
- Admin: Admin-CSS in eigene Datei ausgelagert (admin.css)
- Admin: CSRF-Schutz beim Loeschen von Seiten und Benutzern
- Admin: Sitemap-Regenerierung bei Seiten-Aktionen
- Admin: Slug-Transliteration fuer Umlaute (ae, oe, ue, ss)

## 1.0.1 — 2026-04-15

- baseUrl auf HTTPS korrigiert
- GZIP-Kompression und Browser-Caching via .htaccess
- 404-Fehlerseite mit Navigation und Buttons
- Seitentitel mit Seitenname (Titel | CMF)
- Header/Footer CSS in base.css ausgelagert
- Kontaktseite mit zweispaltigem Layout
- Build-Script fuer automatische Release-Erstellung

## 1.0.0 — 2026-04-15

- Erstveroeffentlichung

