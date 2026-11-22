# Exponio (Website)

Website zur **Präsentation einer eigenen Sammlung** (Briefmarken, Belege & Ganzsachen, Münzen, Banknoten,
Ansichtskarten). Es wird nichts verkauft. Fotos kommen aus der **Exponio-App** (siehe `../exponio_android/README.md`).
Die Website importiert sie automatisch, **Google Gemini** bestimmt sie, du prüfst und veröffentlichst sie.
Danach übersetzt Gemini alles in die Sprachen, die du freischaltest.

- **Stack:** Symfony 7.4 LTS (PHP ≥ 8.2), Doctrine ORM 3, MySQL 8 / MariaDB, EasyAdmin 5, Twig + Vue 3 (Islands),
  Tailwind CSS 4, Vite
- **Sprachen:** alle Sprachen der Welt (ISO 639-1), Freischaltung im Backend, Übersetzung per KI. Deutsch ist die
  Ausgangssprache, Englisch liegt zusätzlich als Datei bei. Das Backend ist auf Deutsch.
- **Qualität:** WCAG 2.2 AA, Lighthouse mobil ≥ 94 / 100 / 100 / 100, PHPStan Stufe 8, Tests (PHPUnit + Playwright/axe)
- **Datenschutz:** keine Cookies im Frontend, keine externen Schriften/Skripte, kein Tracking

---

## 1. Funktionen

| Bereich | Inhalt |
|---|---|
| Navigation | **Katalog ▾** (Alle Objekte + Objektarten mit Anzahl) · **Länder** · **Themen** · **Kontakt**, Sprachwahl, Merkliste |
| Katalog | Volltextsuche, Filter (Objektart, Land als Baum, Thema, Zeitraum, Merkmale), Sortierung, Seiten. Filter ohne Neuladen, teilbare URL |
| Detailseite | Galerie mit Lupe, Seitenangabe (Vorder-/Rückseite/Detail), alle Merkmale, **Mängel**, ähnliche Stücke. Der **Schätzwert** erscheint nur, wenn er beim Objekt freigegeben ist |
| Sprachen | Seite „Sprachen“, `hreflang`, Schrift von rechts nach links (Arabisch, Hebräisch …), Formate nach Sprache |
| SEO / GEO | Titel und Beschreibung je Seite, JSON-LD (WebSite, Person, ItemPage, CollectionPage, BreadcrumbList), Sitemap je Sprache mit Bildern, `robots.txt`, `llms.txt` – alles automatisch |
| Backend | **Prüfen & freigeben**, Objekte (Textsuche + Filter), Objektarten mit Merkmalen, Länder & Gebiete, Themen, Nachrichten, Seiten, **Sprachen**, **Oberflächentexte**, **Einstellungen** (inkl. Name und Logo der Website, KI: Key, Modelle, Prompts) |
| Import | Ein Upload-Ordner der App = ein Objekt. Der Upload-Ordner wird nur gelesen, die Originale bleiben unverändert. Nur **Löschen** unter *Prüfen & freigeben* entfernt ihn zusammen mit dem Objekt |
| Duplikate | Lädst du ein Objekt noch einmal hoch, wird der Upload dem vorhandenen Objekt zugeordnet und gezählt. Bessere Fotos ersetzen die alten (rückgängig machbar), unsichere Fälle entscheidest du unter **Prüfen & freigeben** |

**URLs:** Deutsch hat deutsche Pfade (`/de/katalog`, `/de/katalog/1-briefmarken`, `/de/laender`, `/de/land/12-ddr`, `/de/themen`),
alle anderen Sprachen englische (`/en/catalog`, `/fr/country/12-gdr`, …).

---

## 2. Voraussetzungen

| Werkzeug | Version |
|---|---|
| PHP | ≥ 8.2 mit `intl`, `gd` (mit WebP), `sodium`, `pdo_mysql`, `mbstring`, `ctype`, `iconv` (für Tests zusätzlich `pdo_sqlite` oder `sqlite3`) |
| Composer | 2.x |
| MySQL / MariaDB | MySQL 8.0+ oder MariaDB 10.11+ |
| Node.js | ≥ 22 (nur zum Bauen der Assets) |

---

## 3. Lokal starten (Entwicklung)

```bash
cd website
composer install
npm install && npm run build

# .env.local anlegen (wird nicht eingecheckt) – zum Ausprobieren ohne MySQL:
#   APP_ENV=dev                (in .env steht prod als sichere Voreinstellung)
#   APP_SECRET=<beliebiger langer Zufallswert>
#   DATABASE_URL="sqlite3:///%kernel.project_dir%/var/dev.db"
#   AI_PROVIDER=fake
#   SCAN_DIR=%kernel.project_dir%/var/dev-scan
php bin/console doctrine:schema:create      # nur SQLite; bei MySQL siehe Abschnitt 4
php bin/console app:setup                   # Sprachen, Objektarten, Länder, Themen, Seiten, Einstellungen
php bin/console app:demo --items=36 --scan-folders=2   # optional: Demo-Objekte + Test-Upload-Ordner
php bin/console app:user:create du@example.de

php -S 127.0.0.1:8000 -t public             # oder: symfony serve
```

Website: <http://127.0.0.1:8000/> · Backend: <http://127.0.0.1:8000/admin>

Mit `npm run dev` und `VITE_DEV_SERVER=http://localhost:5173` in `.env.local` lädt der Browser Vue/CSS-Änderungen
sofort neu.

---

## 4. Datenbank (MySQL / MariaDB)

```sql
CREATE DATABASE sammlung CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sammlung'@'localhost' IDENTIFIED BY 'EIN-LANGES-PASSWORT';
GRANT ALL PRIVILEGES ON sammlung.* TO 'sammlung'@'localhost';
```

In `.env.local`:

```dotenv
DATABASE_URL="mysql://sammlung:EIN-LANGES-PASSWORT@127.0.0.1:3306/sammlung?serverVersion=8.0.36&charset=utf8mb4"
# MariaDB z. B.: ...?serverVersion=10.11.6-MariaDB&charset=utf8mb4   (Version: SELECT VERSION();)
```

**Neue, leere Datenbank** (Erstinstallation):

```bash
php bin/console doctrine:schema:create                          # Tabellen passend zur Datenbank anlegen
php bin/console doctrine:migrations:sync-metadata-storage
php bin/console doctrine:migrations:version --add --all --no-interaction   # vorhandene Migrationen als erledigt markieren
php bin/console app:setup                        # Sprachen, Objektarten, Länder, Themen, Seiten, Einstellungen
php bin/console app:user:create du@example.de
```

**Bestehende Datenbank** (Update): `php bin/console doctrine:migrations:migrate`, danach `app:setup`
(ergänzt nur Fehlendes, überschreibt nichts). Nach dem Update mit der Duplikat-Erkennung (`Version20261121180000`)
einmal `php bin/console app:duplicates:scan --dry-run` ausführen und dann ohne `--dry-run`. Das berechnet die
Bild-Hashes der vorhandenen Fotos und markiert mögliche Duplikate im Bestand.

> **Vor jeder Migration eine Datensicherung machen.** `Version20261121120000` überführt die Texte einer
> bestehenden Installation in die neuen Übersetzungstabellen und entfernt danach die alten Spalten.
> Die Migrationen sind für MySQL 8 geschrieben. Bei MariaDB vorher `php bin/console doctrine:schema:validate`
> ausführen.

---

## 5. Produktion

### Ordner auf dem Server

```
domain/
├── app_uploads/   ← die App lädt hierher hoch (ein Ordner je Objekt) – wird von Updates nie berührt
├── media/         ← erzeugte Webbilder (empfohlen außerhalb des Projektordners)
└── live/          ← dieses Projekt (Document Root: live/public)
    └── public/media → ../../media   (Symlink)
```

Einmalig anlegen: `mkdir ../media && ln -s ../../media public/media` (im Ordner `live/`). Gibt es keine Symlinks,
`MEDIA_DIR` leer lassen. Die Bilder liegen dann in `live/public/media` – diesen Ordner beim Update nicht löschen.
Fehlen Webbilder, erzeugt `php bin/console app:media:rebuild` sie aus den Originalen in `app_uploads/` neu.

### `.env.local` auf dem Server

```dotenv
APP_ENV=prod
APP_SECRET=<openssl rand -hex 32>         # nicht mehr ändern – sonst sind die gespeicherten API-Keys unlesbar
DEFAULT_URI=https://deine-domain.de
DATABASE_URL="mysql://…"
MAILER_DSN=smtp://user:pass@smtp.example.de:587
MAILER_FROM=sammlung@deine-domain.de
AI_PROVIDER=gemini
# SCAN_DIR=%kernel.project_dir%/../app_uploads   (Standard)
MEDIA_DIR=%kernel.project_dir%/../media
```

### Paket bauen und hochladen

Auf dem Server sind weder Composer noch Node nötig. Lokal baut `bin/build-release.sh` ein fertiges Paket
(`var/release/live-JJJJ-MM-TT-HHMM.zip`, mit `vendor/`, gebauten Assets und `public/bundles/`):

```bash
bin/build-release.sh
```

Das Zip per SFTP/FTPS in `live/` hochladen und dort entpacken (`unzip -o live-….zip`, oder im Dateimanager von Plesk).
`.env.local`, `var/` und `public/media` sind nicht im Paket und bleiben auf dem Server unangetastet.

### Update einspielen

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:setup
php bin/console cache:clear      # leert auch den Seiten-Cache (neue Inhaltsversion)
```

Ohne Paket geht es auch direkt auf dem Server: `php composer.phar install --no-dev --optimize-autoloader
--classmap-authoritative` (die Assets in `public/build/` müssen trotzdem lokal mit `npm run build` gebaut werden).

Beim Update **nicht überschreiben:** `.env.local`, `var/`, `public/media` (bzw. den Symlink).
Beim **ersten** Update auf diese Version zusätzlich `rm -rf var/share/prod/http_cache` ausführen.

- Schreibrechte für den Webserver: `var/` und den Medienordner. Leserechte auf `app_uploads/`.
- OPcache aktivieren (`opcache.enable=1`, optional `opcache.preload=config/preload.php`).
- Logs: `var/log/prod-JJJJ-MM-TT.log` (14 Tage). Webserver-Logs kurz halten (z. B. 7 Tage) und IPs kürzen.

### Cronjobs

```cron
*/5  * * * *  cd /PFAD/live && php bin/console app:scan --quiet               # neue Upload-Ordner importieren + erkennen
*/10 * * * *  cd /PFAD/live && php bin/console app:translate --quiet          # fehlende/veraltete Übersetzungen
15   3 * * *  cd /PFAD/live && php bin/console app:privacy:cleanup --quiet    # alte Kontaktnachrichten löschen
```

`app:scan` und `app:translate` sperren sich selbst, falls ein Lauf noch nicht fertig ist.

### Apache und nginx

`public/.htaccess` liefert Weiterleitung, Kompression (Brotli/gzip) und lange Cache-Zeiten für Dateien mit Hash.
Für nginx:

```nginx
server {
    server_name deine-domain.de;
    root /var/www/live/public;
    client_max_body_size 30M;
    gzip on; gzip_types text/css application/javascript application/json image/svg+xml text/plain application/xml text/markdown;

    location / { try_files $uri /index.php$is_args$args; }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_read_timeout 300;   # KI-Erkennung aus dem Backend kann dauern
        internal;
    }
    location ~ ^/(build/assets|bundles/easyadmin|media)/ { expires 1y; add_header Cache-Control "public, immutable"; }
    location ~ \.php$ { return 404; }
}
```

---

## 6. Ablauf: App → Website

1. In der App Fotos **eines** Objekts aufnehmen und hochladen. Die App schreibt nach `app_uploads/2026-11-20_211530/01.webp …`
   und benennt den Ordner erst nach vollständigem Upload um. Unfertige `_tmp_…`-Ordner werden ignoriert.
2. `app:scan` (Cron) oder **Backend → Prüfen & freigeben → „Ordner scannen“** importiert neue Ordner als Entwurf.
   Die Webbilder entstehen dabei ohne Metadaten (kein EXIF, keine GPS-Position) und mit Inhalts-Hash im Dateinamen.
3. Gemini bestimmt Objektart, Land, Jahr, Serie, Themen, Merkmale, Titel, Beschreibung, **Mängel**, **Seite und
   Alt-Text je Foto** sowie einen Schätzwert. Der Status ist danach *Zu prüfen*.
4. **Duplikat-Erkennung:** Direkt danach prüft die Website, ob es das Objekt schon gibt.
   - **Vorauswahl ohne KI:** Objektart, Land, Jahr (±1), Katalognummer (Michel, KM#, Pick …), Nennwert und Serie sowie
     ein Wahrnehmungs-Hash der Fotos (dHash mit GD, auch gedreht). Höchstens drei Kandidaten gehen weiter.
   - **Ein Gemini-Aufruf** vergleicht die neuen Fotos mit den Kandidaten. Er liefert: gleiches Objekt, Sicherheit,
     gleiches Stück oder anderes Exemplar, ob Erhaltung und Fotos besser sind, und eine Begründung.
   - **Sicheres Duplikat** (Standard ab 85 %): Es entsteht kein neues Objekt. Der Upload wird beim vorhandenen Objekt
     gezählt und in der Upload-Historie vermerkt. Sind die neuen Fotos **besser** (zuerst die Erhaltung, bei gleicher
     Erhaltung die Fotoqualität), ersetzen sie die alten. Hat sich die Erhaltung geändert, wechseln auch Mängel und
     Exemplar-Merkmale. Titel, Beschreibung, Übersetzungen, Status, Schätzwert-Freigabe, Lagerort und Notizen bleiben.
     Bei **veröffentlichten** Objekten werden bessere Fotos nur **vorgeschlagen**, du bestätigst sie unter
     *Prüfen & freigeben*. Ist der Upload nicht besser, wird er nur gezählt.
   - **Unsicher** (Standard ab 50 %): Das neue Objekt bleibt ein Entwurf mit dem Hinweis „mögliches Duplikat von #ID“.
     Unter *Prüfen & freigeben* stehen „Als Duplikat zusammenführen (Bilder behalten / Bilder ersetzen)“ und
     „Ist ein eigenes Objekt“ zur Auswahl.
   - **Fotos gehen nie verloren:** Ersetzte Fotos bleiben als „früher“ erhalten. In der Upload-Historie
     (*Objekte* → Detailseite → Tab *Uploads*) kannst du ein Ersetzen rückgängig machen, frühere Fotos wieder zeigen
     oder einen Upload als eigenes Objekt abtrennen. Mehrere echte Exemplare derselben Marke gelten als Duplikat, die
     Historie vermerkt „anderes Exemplar“.
5. Unter **Prüfen & freigeben** kontrollieren, korrigieren, **Freigeben & weiter** (Strg+Enter). Den Schätzwert gibst du
   bei Bedarf mit „Schätzwert auf der Website anzeigen“ frei. Standard ist aus.
   Liegt ein Foto quer oder auf dem Kopf, drehst du es mit den Knöpfen am großen Bild (sofort gespeichert) oder im
   Objekt-Formular, Tab *Bilder*, unter der Vorschau (gedreht wird beim Speichern). Gedrehte Bilder bekommen neue
   Dateinamen. Die Drehung wird gespeichert, `app:media:rebuild` behält sie bei. Die Originale in `app_uploads/` bleiben
   unverändert.
   Ist neben der Marke noch Albumtext zu sehen, den die KI mitliest, schneidest du das Foto zu: am großen Bild
   (Zuschneide-Knopf, sofort gespeichert) oder im Objekt-Formular, Tab *Bilder* (Dialog, zugeschnitten wird beim
   Speichern). Rahmen an Ecken und Kanten ziehen oder die Ränder mit Reglern einstellen. Die KI bekommt danach nur den
   Ausschnitt; starte die Erkennung neu („Erneut erkennen“). Der Zuschnitt bezieht sich auf das Original, bleibt beim
   Drehen und bei `app:media:rebuild` erhalten und lässt sich aufheben. Bei im Backend hochgeladenen Bildern (ohne
   Original) ist er endgültig.
   Liegt die KI daneben, trägst du unter *KI-Einschätzung* einen **Hinweis an die KI** ein, z. B. „DDR 1953, 24 Pf Karl
   Marx“ oder „Der Text unter der Marke gehört nicht dazu“, und startest „Mit Hinweis erkennen“. Der Hinweis hat Vorrang
   vor der Einschätzung der KI, den Rest ergänzt sie anhand der Fotos; widerspricht er den Fotos, steht das unter den
   Hinweisen der KI. Er bleibt beim Objekt gespeichert (auch im Objekt-Formular, Tab *Intern & KI*) und gilt bei jeder
   weiteren Erkennung, auch bei „Alle erkennen“.
   **Archivieren** nimmt ein Objekt aus der Liste, ohne etwas zu löschen. Es ist nicht auf der Website und bleibt unter
   *Objekte* (Filter Status „Archiviert“) erhalten. **Löschen** entfernt das Objekt nach einer Rückfrage endgültig:
   mit allen Fotos und den Upload-Ordnern in `app_uploads/`, auch denen zugeordneter Duplikate. Das Scan-Protokoll
   behält die Ordnernamen. Lässt sich ein Ordner nicht löschen (z. B. fehlende Schreibrechte), meldet die Seite das,
   und der Ordner wird trotzdem nicht erneut importiert.
6. `app:translate` übersetzt das Objekt in alle freigeschalteten Sprachen. Das gilt auch für die Alt-Texte neuer Fotos.

Der Upload-Zähler erscheint nur im Backend: Spalte „Uploads“ und Filter „Mehrfach hochgeladen“ bei den Objekten
sowie eine Übersicht auf dem Dashboard.

---

## 7. KI (Google Gemini, Anthropic Claude, Mistral AI oder OpenAI)

- **Anbieter:** Backend → *Einstellungen* → Tab **KI** → „Anbieter“. Er gilt für alle Aufgaben (Erkennung,
  Duplikat-Vergleich, Übersetzung). Ohne Auswahl gilt `AI_PROVIDER` aus `.env`. Beim Wechsel werden die
  Modellnamen zurückgesetzt, weil sie nur für den bisherigen Anbieter gelten.
- **Key:** Im selben Tab → „Neuer API-Key“; er wird für den gewählten Anbieter gespeichert (je Anbieter ein eigener
  Key). Der Key wird mit libsodium verschlüsselt gespeichert (Schlüssel aus `APP_SECRET`) und nie wieder angezeigt.
  Steht `GEMINI_API_KEY`, `ANTHROPIC_API_KEY`, `MISTRAL_API_KEY` bzw. `OPENAI_API_KEY` in `.env.local`, hat dieser
  Vorrang. „KI-Verbindung testen“
  prüft Key und Modell des aktiven Anbieters.
- **Schnittstelle Gemini:** Gemini Interactions API (seit Juni 2026 Googles Standard), Antwort als JSON nach festem
  Schema, Bilder als Base64, `store: false`.
- **Schnittstelle Claude:** Messages API, Bilder als Base64. Das JSON-Schema wird als Werkzeug übergeben, das Claude
  aufrufen muss (`tool_choice`); dessen Eingaben sind das Ergebnis.
- **Schnittstelle Mistral:** Chat Completions API, Antwort als JSON nach Schema (`json_schema`, `strict`), Bilder
  als Data-URI. Mistral nimmt höchstens 8 Bilder pro Anfrage: Die Erkennung schickt ohnehin höchstens 4, der
  Duplikat-Vergleich verteilt die Fotos dann auf neues Objekt und Kandidaten (bei 3 Kandidaten 2 + 3 × 2).
- **Schnittstelle OpenAI (ChatGPT):** Responses API, Antwort als JSON nach Schema (`text.format`, `json_schema`,
  `strict`), Bilder als Data-URI mit `detail: high`, `store: false`.
- **Überlastung:** Bei 429/5xx (Claude auch 529) wiederholt die Website eine Anfrage bis zu dreimal (nach 2, 4 und
  8 s, oder so lange, wie der Anbieter per `Retry-After` verlangt), siehe `App\Ai\AiRetryStrategy`.
- **Wartestatus im Backend:** Löst du im Backend eine KI-Aufgabe aus („KI-Erkennung“, „Jetzt übersetzen“,
  „KI-Verbindung testen“, Erkennung in *Prüfen & freigeben*), erscheint unten rechts ein Hinweis mit Drehsymbol,
  verstrichener Zeit und dem aktuellen Schritt (z. B. „3 Fotos an Claude gesendet – warte auf Antwort …“,
  „Claude ist überlastet (Fehler 529) – Versuch 2 folgt gleich …“, „Duplikatprüfung …“, „Anfrage 2 von max. 5“).
  Technik: Der Browser schickt eine zufällige ID mit (`X-Progress-Id` bzw. Feld `_progress`), die Dienste melden
  ihre Schritte an `App\Ai\AiProgress` (Cache-Pool `ai_progress.cache`), der Hinweis fragt `/admin/ai-progress`
  jede Sekunde ab. Die Aktionen laufen im Hintergrund (fetch): Die Seite bleibt bedienbar, am Ende wird der Hinweis
  zur Ergebnis-Meldung, bei Erkennung und Übersetzung mit Button „Ergebnis anzeigen“ bzw. „Liste aktualisieren“.
  Statt der Weiterleitung antwortet der Server dann mit JSON (`App\EventSubscriber\BackgroundActionSubscriber`).
  Neue Aktionen bekommen das mit `->setHtmlAttributes(['data-ai-progress' => 'Titel'])`, optional
  `'data-ai-result' => 'Button-Text'`.
- **Hinweise (Toasts):** `assets/lib/toast.ts` blendet Meldungen unten rechts ein und aus – Info, Erfolg, Warnung,
  Fehler und „läuft“. Auch alle `addFlash()`-Meldungen des Backends erscheinen so
  (`templates/bundles/EasyAdminBundle/flash_messages.html.twig`). Fehler bleiben bis zum Schließen stehen.
- **Modelle:** Gemini: Standard `gemini-3.8-flash` (Erkennung) und `gemini-3.5-flash-lite` (Übersetzung). Claude:
  Standard `claude-sonnet-5-5` (Erkennung) und `claude-haiku-5-5` (Übersetzung). Mistral: Standard
  `mistral-large-latest` (Erkennung, braucht Bildeingabe) und `mistral-small-latest` (Übersetzung). OpenAI: Standard
  `gpt-6.2-sol` (Erkennung) und `gpt-6-luna` (Übersetzung). Alle änderbar im Backend bzw. in `.env` (`GEMINI_MODEL`,
  `GEMINI_TRANSLATION_MODEL`, `ANTHROPIC_MODEL`, `ANTHROPIC_TRANSLATION_MODEL`, `MISTRAL_MODEL`,
  `MISTRAL_TRANSLATION_MODEL`, `OPENAI_MODEL`, `OPENAI_TRANSLATION_MODEL`). Aktuelle Listen:
  <https://ai.google.dev/gemini-api/docs/models>, <https://docs.claude.com/en/docs/about-claude/models>,
  <https://docs.mistral.ai/models>, <https://developers.openai.com/api/docs/models>.
- **Prompts:** Alle drei Prompts sind im Backend editierbar: Erkennung, Übersetzung und Duplikat-Vergleich.
  Platzhalter `{{objektarten}}`, `{{laender}}`, `{{themen}}` und `{{sprache}}` füllt die Website. Das Antwortformat
  (JSON-Schema) bleibt im Code. Vorschauen zeigen den fertigen Erkennungs-Prompt und den Vergleichsauftrag für das
  zuletzt erkannte Objekt. „Prompts auf Standard“ setzt alle zurück. Der Hinweis an die KI eines Objekts steht nicht
  im Prompt, sondern im Auftrag zum Objekt (der feste Prompt bleibt gleich und kann zwischengespeichert werden).
- **Duplikate:** Unter *Einstellungen → KI → Duplikate* lassen sich die Erkennung an- und ausschalten und die
  Schwellen einstellen: „Sicher ab“ (automatisch zuordnen, Standard 85 %) und „Hinweis ab“ (Standard 50 %). Außerdem
  gibt es dort den Schalter „Veröffentlichte Objekte automatisch aktualisieren“ (Standard aus: nur vorschlagen). Der
  Vergleich nutzt das Modell der Erkennung und kostet einen zusätzlichen Aufruf, aber nur, wenn die Vorauswahl
  Kandidaten findet.
- **Datenschutz:** Nur Objektfotos und Website-Texte gehen an den gewählten Anbieter, keine Besucherdaten. Für Claude,
  Mistral und OpenAI gelten deren API-Bedingungen; prüfe sie vor dem Wechsel. Laut den Gemini-Bedingungen
  (Stand 23.03.2026) gelten für Nutzer im EWR, in der Schweiz und in Großbritannien die Datenregeln der bezahlten
  Dienste. Das heißt: keine Nutzung zum Training. Empfohlen ist trotzdem ein Projekt mit Abrechnungskonto.
  Bedingungen: <https://ai.google.dev/gemini-api/terms>
- Ohne Key zum Ausprobieren: `AI_PROVIDER=fake` (Beispieldaten, keine Kosten). Auch der Duplikat-Vergleich liefert
  dann feste Testergebnisse: Ein nahezu gleiches Foto gilt als sicheres Duplikat.

---

## 8. Sprachen

- Backend → **Sprachen**: alle rund 180 Sprachen. „Aktiv“ einschalten → `app:translate` übersetzt Oberfläche und Inhalte
  (oder sofort mit „Jetzt übersetzen“). Öffentlich wird eine Sprache, sobald ihre Oberfläche vollständig übersetzt ist.
- Jede Übersetzung lässt sich korrigieren: im Tab „Übersetzungen“ der Objekte, Objektarten, Länder, Themen, Seiten
  und Einstellungen sowie unter **Oberflächentexte**. Korrekturen bleiben, bis sich der deutsche Text ändert.
- Ob eine Übersetzung aktuell ist, erkennt die Website am Hash des deutschen Texts. Es wird nur übersetzt, was fehlt
  oder sich geändert hat. Platzhalter wie `%count%` oder HTML-Tags werden nach jeder Übersetzung geprüft.

---

## 9. Caching

| Ebene | Was | Wie lange / wann geleert |
|---|---|---|
| Browser | JS, CSS, Schriften, Bilder (Hash im Dateinamen) | 1 Jahr, `immutable` |
| Browser | HTML-Seiten | prüft jedes Mal per ETag nach, bekommt meist nur „304 Not Modified“ |
| Seiten-Cache | fertige HTML-Seiten (Symfony HttpCache, `var/share/prod/http_cache`) | bis zur nächsten Änderung im Backend bzw. `cache:clear` |
| Anwendung | Zähler, Facetten, Sprachlisten, Sitemaps, `llms.txt`, Oberflächentexte (Pool `content.cache`) | bis zur nächsten Änderung |
| PHP / Doctrine | Container, Metadaten, Abfragen, OPcache | bis zum nächsten Deployment |
| KI | Übersetzungen (Hash des deutschen Texts) | nur bei Änderung neu |

Jede Änderung im Backend erhöht die **Inhaltsversion** (`App\Cache\ContentVersion`): Seiten- und Anwendungs-Cache werden
geleert, ETags ändern sich. Prüfen: `curl -I https://deine-domain.de/de/katalog` → `Cache-Control: public, s-maxage=86400`
und `ETag`.

---

## 10. Datenschutz, Barrierefreiheit, Sicherheit

- **DSGVO:** keine Cookies im Frontend (die Merkliste liegt nur im Browser), keine externen Ressourcen, kein Tracking.
  Das Kontaktformular fragt nur Name, E-Mail und Nachricht ab. IPs werden nicht gespeichert (Rate-Limit nur gehasht).
  Nachrichten werden nach einer einstellbaren Frist gelöscht. Webbilder haben keine Metadaten.
  **Impressum, Datenschutz und Barrierefreiheit** liegen als Vorlagen unter *Backend → Seiten* – Angaben in
  `[eckigen Klammern]` ersetzen und rechtlich prüfen lassen.
- **Barrierefreiheit:** WCAG 2.2 AA (Tastaturbedienung, Fokus, Kontraste, Alt-Texte, Sprache/Leserichtung, Zoom,
  `prefers-reduced-motion`). Geprüft mit axe (Playwright) auf allen Seitentypen.
- **Sicherheitsheader:** Content-Security-Policy ohne Inline-Skripte, HSTS (bei HTTPS), Referrer-Policy,
  Permissions-Policy, `X-Content-Type-Options`, `X-Frame-Options`.

---

## 11. Befehle

| Befehl | Zweck |
|---|---|
| `app:setup` | Sprachen, Objektarten mit Merkmalen, Länder, Themen, Seiten anlegen bzw. Fehlendes ergänzen |
| `app:user:create <email>` | Backend-Benutzer anlegen / Passwort neu setzen |
| `app:scan [--limit=20] [--no-recognize] [--retry-failed]` | Upload-Ordner importieren, erkennen und auf Duplikate prüfen (**Cron**) |
| `app:duplicates:scan [--dry-run] [--limit=20] [--item=ID] [--recheck]` | vorhandene Objekte nachträglich auf Duplikate prüfen. Fehlende Bild-Hashes werden berechnet, Treffer nur als „mögliches Duplikat“ markiert, nie automatisch zusammengeführt. `--dry-run` zeigt nur die Vorauswahl (nichts gespeichert, keine KI-Kosten) |
| `app:translate [--language=xx] [--limit=20]` | fehlende/veraltete Übersetzungen per KI (**Cron**) |
| `app:privacy:cleanup` | Kontaktnachrichten nach Ablauf der Frist löschen (**Cron**) |
| `app:media:rebuild [--all]` | Webbilder aus den Originalen in `app_uploads/` neu erzeugen |
| `app:recognize <id>` | ein Objekt (erneut) erkennen, zeigt die KI-Antwort |
| `app:demo [--items=36] [--scan-folders=2]` | Demo-Objekte / Test-Ordner (nur Entwicklung) |
| `php bin/build-brand-assets.php` | mitgeliefertes Standardlogo (Logo, Favicons, Vorschaubild) aus einer Logo-Quelldatei erzeugen (nur für Maintainer, die Quelle liegt nicht im Repository; die fertigen Dateien sind in `public/brand/` enthalten). Ein eigenes Logo lädst du im Backend unter *Einstellungen → Website → Logo* hoch |

---

## 12. Tests und Codequalität

```bash
composer check        # Code-Stil, PHPStan (Stufe 8), Twig/YAML/Container, Doctrine-Schema, PHPUnit
npm run check         # vue-tsc + ESLint
npm run test:e2e      # Playwright + axe gegen eine laufende Website (E2E_BASE_URL, Standard http://127.0.0.1:8000)
composer fix          # Code-Stil automatisch korrigieren
```

Die PHPUnit-Tests bauen eine SQLite-Datenbank (`var/test.db`) mit Demodaten auf und arbeiten mit `AI_PROVIDER=fake`,
es entstehen keine API-Kosten. Für die Browser-Tests einmalig `npx playwright install chromium` ausführen.

---

## 13. Projektstruktur

```
assets/                            Frontend (Vue-Islands, Tailwind) und Backend-Prüf-App
src/
  Ai/                              AiProvider, AiClients, GeminiClient, AnthropicClient, MistralClient, OpenAiClient, AiCredentials, AiProgress, AiRetryStrategy, SecretBox, AiConfig, DefaultPrompts
  Recognition/                     PromptBuilder, AiRecognizer, FakeRecognizer, RecognitionApplier
  Duplicate/                       CandidateFinder, ComparisonPromptBuilder, AiComparer, FakeComparer, DuplicateDetector, DuplicateMerger
  Translation/                     TranslationRunner, MachineTranslator, PlaceholderGuard, TranslatableTrait
  Localization/                    Locales, UiMessages, DatabaseTranslator
  Routing/FrontRouteLoader.php     /de/… mit deutschen Pfaden, /{sprache}/… für alle anderen
  Cache/                           ContentVersion, ContentCache, ContentChangeListener, DeployCacheClearer
  Seo/                             SitemapBuilder, LlmsTxtBuilder
  EventSubscriber/                 Sprache prüfen, Cache-Header, Sicherheitsheader
  Catalog/  Import/  Media/        Katalogsuche, Ordner-Import, Webbilder
  Entity/  Repository/  Command/   Datenmodell, Abfragen, Konsolenbefehle
  Controller/Front|Site|Admin      Website, Sitemaps/robots/llms.txt, Backend
templates/  translations/          Twig-Vorlagen, Oberflächentexte (de, en)
migrations/  tests/                Datenbank-Migrationen, PHPUnit- und Playwright-Tests
```

---

## 14. Ausblick und Lizenz

- **Globale Plattform:** Geplant ist eine Schnittstelle, über die Sammler ihre Objekte zusätzlich auf einer gemeinsamen
  Plattform zeigen können. Das Datenmodell (Objekte mit stabilen IDs, Merkmale, Übersetzungen, JSON-LD) ist darauf
  vorbereitet, die Schnittstelle selbst gibt es noch nicht.
- **Open Source:** Website und App werden später auf GitHub veröffentlicht. Die Lizenz wird noch festgelegt.

## 15. Vor dem Livegang

- [ ] **Impressum**, **Datenschutz**, **Barrierefreiheit** unter *Backend → Seiten* vervollständigen
- [ ] *Einstellungen*: Name der Website, Logo (optional), Name des Sammlers, Kontakt-E-Mail, KI-Anbieter und API-Key, „KI-Verbindung testen“
- [ ] `MAILER_DSN` / `MAILER_FROM` setzen und eine Testnachricht schicken
- [ ] Gewünschte Sprachen unter *Backend → Sprachen* freischalten
- [ ] HTTPS, Cronjobs, Backup für Datenbank **und** Medienordner (die Originale liegen in `app_uploads/`)

## Lizenz

[MIT](LICENSE) © 2026 Harald Huber
