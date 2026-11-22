<?php

namespace App\Command;

use App\Enum\AttributeType;

/**
 * Startdaten für "app:setup": Objektarten mit Merkmalen, Länder, Themen und Pflichtseiten.
 * Alles lässt sich danach im Backend ändern.
 */
final class DefaultData
{
    /**
     * "wert|Deutsch|English" je Option.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function options(string ...$lines): array
    {
        return array_map(static function (string $line): array {
            [$value, $de, $en] = array_pad(explode('|', $line, 3), 3, '');

            return [$value, $de, $en];
        }, array_values($lines));
    }

    /**
     * Objektarten mit Merkmalen. Merkmal: [code, Bezeichnung DE, Bezeichnung EN, Typ, Optionen, Filter, Karte, KI-Hinweis, Einheit].
     * Die Merkmale ab „catalog_numbers“ (Briefmarken) usw. stammen aus dem Abgleich mit Colnect (November 2026).
     *
     * @return list<array{de: string, en: string, icon: string, descDe: string, descEn: string, attributes: list<array<int, mixed>>}>
     */
    public static function areas(): array
    {
        $quality = self::options('very_fine|sehr gut|very fine', 'fine|gut|fine', 'average|Gebrauchsspuren|average', 'damaged|beschädigt|damaged');
        $sure = 'nur wenn sicher bekannt oder erkennbar';

        return [
            [
                'de' => 'Briefmarken', 'en' => 'Stamps', 'icon' => 'stamp',
                'descDe' => 'Einzelmarken, Blocks, Bogen und Markenheftchen.',
                'descEn' => 'Single stamps, souvenir sheets, sheets and booklets.',
                'attributes' => [
                    ['michel', 'Michel-Nr.', 'Michel no.', AttributeType::Text, [], false, true, 'Michel-Katalognummer inkl. Zusatz (z. B. 213 A) – nur wenn sicher'],
                    ['catalog_numbers', 'Weitere Katalog-Nr.', 'Other catalogue nos.', AttributeType::Text, [], false, false, 'Scott (Sn), Yvert (Yt), Stanley Gibbons (SG), Colnect, z. B. "Sn:US 123; Yt:FR 45" – nur wenn sicher'],
                    ['face_value', 'Nennwert', 'Face value', AttributeType::Text, [], false, false, 'wie auf der Marke, z. B. 10 Pf, 1 DM, 0,80 €'],
                    ['condition', 'Erhaltung', 'Condition', AttributeType::Select, self::options(
                        'mnh|postfrisch (**)|mint never hinged (MNH)',
                        'mh|ungebraucht mit Falz (*)|mint hinged (MH)',
                        'no_gum|ungebraucht ohne Gummi|unused, no gum',
                        'used|gestempelt|used',
                        'cto|Gefälligkeitsstempel|cancelled to order (CTO)',
                        'first_day|Ersttagsstempel|first day cancel',
                        'on_piece|Briefstück|on piece',
                    ), true, true, 'Rückseite (Gummi, Falz) und Stempel beachten'],
                    ['stamp_type', 'Markenart', 'Stamp type', AttributeType::Select, self::options(
                        'definitive|Dauer-/Freimarke|definitive',
                        'commemorative|Sondermarke|commemorative',
                        'charity|Zuschlagsmarke|charity / semi-postal',
                        'airmail|Flugpostmarke|airmail',
                        'postage_due|Portomarke|postage due',
                        'official|Dienstmarke|official',
                        'regional|Regionalmarke|regional',
                        'overprint|Aufdruckmarke|overprint',
                        'revenue|Fiskal-/Gebührenmarke|revenue',
                    ), true, false, null],
                    ['format', 'Ausgabeform', 'Format', AttributeType::Select, self::options(
                        'single|Einzelmarke|single stamp',
                        'pair|Paar|pair',
                        'strip|Streifen|strip',
                        'block4|Viererblock|block of four',
                        'souvenir_sheet|Block|souvenir sheet',
                        'sheetlet|Kleinbogen|sheetlet',
                        'booklet|Markenheftchen|booklet',
                        'coil|Rollenmarke|coil stamp',
                    ), true, false, null],
                    ['color', 'Farbe', 'Colour', AttributeType::Text, [], false, false, 'Hauptfarbe(n) auf Deutsch, z. B. karminrot'],
                    ['perforation', 'Zähnung', 'Perforation', AttributeType::Text, [], false, false, 'z. B. K 14, gezähnt 13¾, geschnitten'],
                    ['watermark', 'Wasserzeichen', 'Watermark', AttributeType::Text, [], false, false, $sure],
                    ['printing', 'Druckverfahren', 'Printing method', AttributeType::Select, self::options(
                        'offset|Offsetdruck|offset',
                        'intaglio|Stichtiefdruck|intaglio',
                        'photogravure|Rastertiefdruck|photogravure',
                        'letterpress|Buchdruck|letterpress',
                        'lithography|Steindruck|lithography',
                        'combined|Kombinationsdruck|combined printing',
                    ), false, false, null],
                    ['paper', 'Papier', 'Paper', AttributeType::Select, self::options(
                        'ordinary|normales Papier|ordinary paper',
                        'chalky|gestrichenes Papier (Kreidepapier)|chalk-surfaced paper',
                        'fluorescent|fluoreszierendes Papier|fluorescent paper',
                        'fibres|Papier mit Faserstreifen|paper with fibres',
                        'unknown|unbekannt|unknown',
                    ), true, false, $sure],
                    ['gum', 'Gummierung', 'Gum', AttributeType::Select, self::options(
                        'gummed|gummiert|gummed',
                        'self_adhesive|selbstklebend|self-adhesive',
                        'no_gum|ohne Gummierung|no gum',
                    ), true, false, 'nur wenn auf der Rückseite erkennbar'],
                    ['size', 'Größe', 'Size', AttributeType::Text, [], false, false, 'Breite × Höhe in mm, z. B. "27 × 32 mm" – nur wenn sicher bekannt'],
                    ['designer', 'Entwurf', 'Designer', AttributeType::Text, [], false, false, $sure],
                    ['engraver', 'Stich', 'Engraver', AttributeType::Text, [], false, false, $sure],
                    ['printer', 'Druckerei', 'Printer', AttributeType::Text, [], false, false, 'z. B. Bundesdruckerei – '.$sure],
                    ['print_run', 'Auflage', 'Print run', AttributeType::Number, [], false, false, $sure],
                    ['valid_until', 'Gültig bis', 'Valid until', AttributeType::Text, [], false, false, 'Frankaturgültigkeit – '.$sure],
                    ['variety', 'Abart / Plattenfehler', 'Variety / plate flaw', AttributeType::Text, [], false, false, 'nur wenn eindeutig sichtbar'],
                ],
            ],
            [
                'de' => 'Belege & Ganzsachen', 'en' => 'Covers & stationery', 'icon' => 'envelope',
                'descDe' => 'Briefe, Postkarten, Ersttagsbriefe, Ganzsachen und Sonderstempel.',
                'descEn' => 'Letters, postcards, first day covers, postal stationery and special cancellations.',
                'attributes' => [
                    ['cover_type', 'Belegart', 'Cover type', AttributeType::Select, self::options(
                        'letter|Brief|letter',
                        'postcard|Postkarte|postcard',
                        'fdc|Ersttagsbrief (FDC)|first day cover (FDC)',
                        'postal_stationery|Ganzsache|postal stationery',
                        'registered|Einschreiben|registered mail',
                        'airmail|Luftpost|airmail',
                        'field_post|Feldpost|field post',
                        'special_cancel|Sonderstempelbeleg|special cancellation',
                        'maximum_card|Maximumkarte|maximum card',
                    ), true, true, null],
                    ['franking', 'Frankatur', 'Franking', AttributeType::Text, [], false, false, 'verklebte Marken, mit Michel-Nr. falls sicher'],
                    ['postmark', 'Stempel', 'Postmark', AttributeType::Text, [], false, false, 'Ort und Datum des Stempels, wenn lesbar'],
                    ['origin', 'Aufgabeort', 'Place of posting', AttributeType::Text, [], false, false, 'wenn lesbar'],
                    ['travelled', 'Gelaufen', 'Postally used', AttributeType::Boolean, [], true, false, null],
                    ['destination', 'Bestimmungsort', 'Destination', AttributeType::Text, [], false, false, null],
                    ['postal_services', 'Zusatzleistungen', 'Postal services', AttributeType::Text, [], false, false, 'z. B. Einschreiben, Eilboten, Luftpost, Nachnahme – nur wenn erkennbar'],
                    ['correct_postage', 'Portogerecht', 'Correct postage', AttributeType::Select, self::options(
                        'yes|ja|yes', 'no|nein|no', 'unknown|unbekannt|unknown',
                    ), false, false, 'nur wenn Tarif und Frankatur sicher bekannt sind, sonst unknown'],
                    ['censorship', 'Zensur', 'Censorship', AttributeType::Boolean, [], false, false, 'Zensurstempel oder -streifen sichtbar'],
                    ['stationery_number', 'Ganzsachen-Nr.', 'Postal stationery no.', AttributeType::Text, [], false, false, 'z. B. Michel P 12 – nur wenn sicher'],
                    ['quality', 'Erhaltung', 'Condition', AttributeType::Select, $quality, true, true, null],
                ],
            ],
            [
                'de' => 'Münzen', 'en' => 'Coins', 'icon' => 'coin',
                'descDe' => 'Umlauf- und Gedenkmünzen.',
                'descEn' => 'Circulation and commemorative coins.',
                'attributes' => [
                    ['denomination', 'Nominal', 'Denomination', AttributeType::Text, [], false, true, null],
                    ['material', 'Material', 'Metal', AttributeType::Select, self::options(
                        'gold|Gold|gold', 'silver|Silber|silver', 'copper|Kupfer|copper', 'cupronickel|Kupfer-Nickel|cupronickel',
                        'brass|Messing|brass', 'aluminium|Aluminium|aluminium', 'steel|Stahl|steel', 'bimetal|Bimetall|bimetallic', 'other|anderes|other',
                    ), true, false, null],
                    ['fineness', 'Feingehalt', 'Fineness', AttributeType::Text, [], false, false, 'z. B. 925/1000 – '.$sure],
                    ['grade', 'Erhaltung', 'Grade', AttributeType::Select, self::options(
                        'proof|Polierte Platte (PP)|proof', 'unc|Stempelglanz (st)|uncirculated', 'xf|vorzüglich (vz)|extremely fine',
                        'vf|sehr schön (ss)|very fine', 'f|schön (s)|fine', 'g|gut erhalten (ge)|good',
                    ), true, true, null],
                    ['strike_quality', 'Prägequalität', 'Strike quality', AttributeType::Select, self::options(
                        'circulation|Normalprägung|circulation strike', 'bu|Stempelglanz|brilliant uncirculated', 'proof|Polierte Platte|proof',
                    ), true, false, null],
                    ['weight', 'Gewicht', 'Weight', AttributeType::Number, [], false, false, null, 'g'],
                    ['diameter', 'Durchmesser', 'Diameter', AttributeType::Number, [], false, false, null, 'mm'],
                    ['thickness', 'Dicke', 'Thickness', AttributeType::Number, [], false, false, $sure, 'mm'],
                    ['shape', 'Form', 'Shape', AttributeType::Select, self::options(
                        'round|rund|round', 'square|eckig|square', 'polygonal|vieleckig|polygonal', 'scalloped|gewellt|scalloped',
                        'holed|mit Loch|holed', 'other|sonstige|other',
                    ), true, false, null],
                    ['edge', 'Rand', 'Edge', AttributeType::Select, self::options(
                        'plain|glatt|plain', 'reeded|geriffelt|reeded', 'lettered|Randschrift|lettered',
                        'lettered_reeded|Randschrift auf geriffeltem Rand|lettered on reeded edge', 'wavy|gewellt|wavy', 'other|sonstige|other',
                    ), true, false, 'nur wenn der Rand zu sehen ist'],
                    ['orientation', 'Stempelstellung', 'Die orientation', AttributeType::Select, self::options(
                        'medal|Medaillenprägung ↑↑|medal alignment ↑↑', 'coin|Münzprägung ↑↓|coin alignment ↑↓',
                    ), false, false, $sure],
                    ['mint_mark', 'Prägezeichen', 'Mint mark', AttributeType::Text, [], false, false, 'Buchstabe und Prägestätte, z. B. "A (Berlin)"'],
                    ['mintage', 'Auflage', 'Mintage', AttributeType::Number, [], false, false, $sure],
                    ['obverse', 'Vorderseite', 'Obverse', AttributeType::Text, [], false, false, 'Motiv und Umschrift'],
                    ['reverse', 'Rückseite', 'Reverse', AttributeType::Text, [], false, false, 'Motiv und Umschrift'],
                    ['designer', 'Entwurf', 'Designer', AttributeType::Text, [], false, false, $sure],
                    ['demonetized', 'Außer Kurs seit', 'Demonetised', AttributeType::Text, [], false, false, $sure],
                    ['catalog_ref', 'Katalog-Nr.', 'Catalogue no.', AttributeType::Text, [], false, false, 'z. B. KM- oder Jaeger-Nummer, nur wenn sicher'],
                ],
            ],
            [
                'de' => 'Banknoten', 'en' => 'Banknotes', 'icon' => 'banknote',
                'descDe' => 'Papiergeld und Notgeld.',
                'descEn' => 'Paper money and emergency money.',
                'attributes' => [
                    ['denomination', 'Nominal', 'Denomination', AttributeType::Text, [], false, true, null],
                    ['grade', 'Erhaltung', 'Grade', AttributeType::Select, self::options(
                        'unc|kassenfrisch (I)|uncirculated', 'aunc|fast kassenfrisch (I-)|about uncirculated', 'xf|leicht gebraucht (II)|extremely fine',
                        'vf|gebraucht (III)|very fine', 'f|stark gebraucht (IV)|fine', 'poor|schlecht (V)|poor',
                    ), true, true, null],
                    ['material', 'Material', 'Material', AttributeType::Select, self::options(
                        'paper|Papier|paper', 'polymer|Polymer|polymer', 'hybrid|Hybrid|hybrid',
                    ), true, false, null],
                    ['serial', 'Seriennummer', 'Serial number', AttributeType::Text, [], false, false, null],
                    ['replacement', 'Ersatznote', 'Replacement note', AttributeType::Boolean, [], false, false, $sure],
                    ['size', 'Größe', 'Size', AttributeType::Text, [], false, false, 'Breite × Höhe in mm – '.$sure],
                    ['watermark', 'Wasserzeichen', 'Watermark', AttributeType::Text, [], false, false, $sure],
                    ['printer', 'Druckerei', 'Printer', AttributeType::Text, [], false, false, $sure],
                    ['signatures', 'Unterschriften', 'Signatures', AttributeType::Text, [], false, false, 'wenn lesbar'],
                    ['obverse', 'Vorderseite', 'Obverse', AttributeType::Text, [], false, false, 'Motiv'],
                    ['reverse', 'Rückseite', 'Reverse', AttributeType::Text, [], false, false, 'Motiv'],
                    ['pick', 'Pick-Nr.', 'Pick no.', AttributeType::Text, [], false, false, 'nur wenn sicher'],
                    ['catalog_numbers', 'Weitere Katalog-Nr.', 'Other catalogue nos.', AttributeType::Text, [], false, false, 'z. B. Rosenberg, Grabowski – nur wenn sicher'],
                    ['demonetized', 'Ungültig seit', 'Demonetised', AttributeType::Text, [], false, false, $sure],
                ],
            ],
            [
                'de' => 'Ansichtskarten', 'en' => 'Picture postcards', 'icon' => 'postcard',
                'descDe' => 'Historische Ansichts- und Motivkarten.',
                'descEn' => 'Historic picture and topical postcards.',
                'attributes' => [
                    ['place', 'Ort / Motiv', 'Place / subject', AttributeType::Text, [], false, true, null],
                    ['card_type', 'Kartenart', 'Card type', AttributeType::Select, self::options(
                        'photo|Fotokarte|real photo', 'litho|Lithografie|lithograph', 'printed|Druck|printed',
                        'artist|Künstlerkarte|artist card', 'greeting|Glückwunschkarte|greeting card',
                    ), true, false, null],
                    ['printing', 'Herstellungsart', 'Printing method', AttributeType::Select, self::options(
                        'lithography|Lithografie|lithography', 'collotype|Lichtdruck|collotype', 'real_photo|Echtfoto|real photo',
                        'offset|Offsetdruck|offset', 'embossed|Prägedruck|embossed', 'other|sonstige|other',
                    ), true, false, $sure],
                    ['travelled', 'Gelaufen', 'Postally used', AttributeType::Boolean, [], true, false, null],
                    ['postmark', 'Stempel', 'Postmark', AttributeType::Text, [], false, false, 'Ort und Datum, wenn lesbar'],
                    ['publisher', 'Verlag', 'Publisher', AttributeType::Text, [], false, false, null],
                    ['quality', 'Erhaltung', 'Condition', AttributeType::Select, $quality, true, true, null],
                ],
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: array<string, string>}> Name DE => [Name EN, Untergebiete DE => EN] */
    public static function regions(): array
    {
        return [
            'Deutschland' => ['Germany', [
                'Altdeutsche Staaten' => 'Old German States',
                'Deutsches Reich' => 'German Empire',
                'Deutsche Kolonien' => 'German colonies',
                'Alliierte Besetzung' => 'Allied occupation',
                'Bundesrepublik Deutschland' => 'Federal Republic of Germany',
                'Berlin (West)' => 'West Berlin',
                'DDR' => 'German Democratic Republic (GDR)',
            ]],
            'Österreich' => ['Austria', []],
            'Schweiz' => ['Switzerland', []],
            'Liechtenstein' => ['Liechtenstein', []],
            'Frankreich' => ['France', []],
            'Italien' => ['Italy', []],
            'Vereinigtes Königreich' => ['United Kingdom', []],
            'Niederlande' => ['Netherlands', []],
            'Vatikanstadt' => ['Vatican City', []],
            'Vereinigte Staaten' => ['United States', []],
        ];
    }

    /** @return array<string, string> */
    public static function topics(): array
    {
        return [
            'Tiere' => 'Animals',
            'Pflanzen & Blumen' => 'Plants & flowers',
            'Persönlichkeiten' => 'Famous people',
            'Kunst & Gemälde' => 'Art & paintings',
            'Architektur & Bauwerke' => 'Architecture',
            'Sport' => 'Sports',
            'Olympische Spiele' => 'Olympic Games',
            'Eisenbahn' => 'Railways',
            'Schiffe & Seefahrt' => 'Ships & seafaring',
            'Luft- & Raumfahrt' => 'Aviation & space',
            'Geschichte & Ereignisse' => 'History & events',
            'Religion & Weihnachten' => 'Religion & Christmas',
            'Musik' => 'Music',
            'Europa (CEPT)' => 'Europa (CEPT)',
            'Landschaften' => 'Landscapes',
            'Wappen & Flaggen' => 'Coats of arms & flags',
            'Technik & Wissenschaft' => 'Science & technology',
            'Post & Philatelie' => 'Postal history & philately',
        ];
    }

    /**
     * Pflicht- und Infoseiten als Vorlagen. Text in [eckigen Klammern] muss ersetzt werden.
     *
     * @return list<array{key: string, de: string, en: string, contentDe: string, contentEn: string}>
     */
    public static function pages(): array
    {
        $todoDe = '<p><strong>[Bitte vervollständigen: Diese Vorlage passt zu den Funktionen der Website. Ersetzen Sie alle Angaben in eckigen Klammern und lassen Sie den Text im Zweifel rechtlich prüfen. Diesen Absatz danach löschen.]</strong></p>';
        $todoEn = '<p><strong>[Please complete: replace everything in square brackets and have the text checked legally if in doubt. Delete this paragraph afterwards.]</strong></p>';

        return [
            [
                'key' => 'about',
                'de' => 'Über die Sammlung',
                'en' => 'About the collection',
                'contentDe' => '<p>Willkommen in meiner Sammlung! Hier zeige ich Briefmarken, Belege und weitere Sammelstücke aus meinem Besitz – jedes Stück fotografiert, bestimmt und sorgfältig beschrieben. Die Website dient der Präsentation; es wird nichts verkauft.</p><h2>Wie die Beschreibungen entstehen</h2><p>Jedes Objekt wird fotografiert und mit Hilfe einer KI vorbestimmt. Ich prüfe und korrigiere jede Bestimmung, bevor sie hier erscheint. Angegebene Katalognummern und Schätzwerte sind Richtwerte ohne Gewähr.</p><h2>Kontakt</h2><p>Fragen oder Hinweise zu einem Stück? Schreiben Sie mir gern über das Kontaktformular.</p>',
                'contentEn' => '<p>Welcome to my collection! Here I show stamps, covers and other collectibles that I own – each item photographed, identified and carefully described. This website is a presentation only; nothing is for sale.</p><h2>How the descriptions are created</h2><p>Every item is photographed and pre-identified with the help of an AI. I check and correct every identification before it appears here. Catalogue numbers and estimated values are for guidance only.</p><h2>Contact</h2><p>Questions or remarks about an item? Feel free to write to me using the contact form.</p>',
            ],
            [
                'key' => 'imprint',
                'de' => 'Impressum',
                'en' => 'Imprint',
                'contentDe' => $todoDe.'<h2>Angaben zum Betreiber</h2><p>[Vorname Nachname]<br>[Straße Hausnummer]<br>[PLZ Ort]<br>[Land]</p><h2>Kontakt</h2><p>E-Mail: [E-Mail-Adresse]</p><h2>Verantwortlich für den Inhalt</h2><p>[Vorname Nachname], Anschrift wie oben</p>',
                'contentEn' => $todoEn.'<h2>Operator</h2><p>[First name last name]<br>[Street]<br>[Postcode city]<br>[Country]</p><h2>Contact</h2><p>Email: [email address]</p><h2>Responsible for the content</h2><p>[First name last name], address as above</p>',
            ],
            [
                'key' => 'privacy',
                'de' => 'Datenschutz',
                'en' => 'Privacy policy',
                'contentDe' => $todoDe
                    .'<h2>1. Verantwortlicher</h2><p>[Vorname Nachname]<br>[Anschrift]<br>E-Mail: [E-Mail-Adresse]</p>'
                    .'<h2>2. Das Wichtigste in Kürze</h2><ul><li>Diese Website setzt <strong>keine Cookies</strong> und nutzt <strong>kein Tracking</strong> und keine Statistikdienste.</li><li>Es werden <strong>keine externen Inhalte</strong> geladen – Schriften, Skripte und Bilder liegen auf diesem Server.</li><li>Personenbezogene Daten verarbeite ich nur, wenn Sie mir über das Kontaktformular oder per E-Mail schreiben, sowie kurzzeitig in den Server-Protokollen.</li></ul>'
                    .'<h2>3. Hosting und Server-Protokolle</h2><p>Die Website liegt bei [Name und Anschrift des Hosters], mit dem ein Vertrag zur Auftragsverarbeitung (Art. 28 DSGVO) besteht. Beim Aufruf einer Seite verarbeitet der Server technisch notwendige Daten: gekürzte IP-Adresse, Datum und Uhrzeit, aufgerufene Adresse, übertragene Datenmenge sowie Browser- und Betriebssystemangaben. Zweck ist der sichere und stabile Betrieb (Art. 6 Abs. 1 lit. f DSGVO). Die Protokolle werden nach [7] Tagen gelöscht.</p><p>Fertige Seiten werden zur Beschleunigung zwischengespeichert. Dieser Zwischenspeicher enthält keine personenbezogenen Daten.</p>'
                    .'<h2>4. Merkliste</h2><p>Wenn Sie Stücke mit dem Herz merken, speichert Ihr Browser nur deren Nummern im lokalen Speicher (localStorage) Ihres Geräts. Diese Daten werden nicht an mich übertragen. Die Speicherung ist für die von Ihnen gewünschte Funktion unbedingt erforderlich (§ 25 Abs. 2 Nr. 2 TDDDG). Sie können die Liste jederzeit mit „Liste leeren“ oder über die Einstellungen Ihres Browsers löschen.</p>'
                    .'<h2>5. Kontaktformular und E-Mail</h2><p>Wenn Sie mir schreiben, verarbeite ich Ihren Namen, Ihre E-Mail-Adresse, Ihre Nachricht sowie Sprache und Zeitpunkt, um Ihre Anfrage zu beantworten (Art. 6 Abs. 1 lit. b und f DSGVO). Die Nachricht wird per E-Mail an mich weitergeleitet ([E-Mail-Anbieter]). Ihre IP-Adresse wird dabei nicht gespeichert; zum Schutz vor Missbrauch wird sie nur verschlüsselt (gehasht) und für höchstens 30 Minuten zur Begrenzung der Anfragen verwendet. Nachrichten werden nach [6] Monaten automatisch gelöscht, sofern keine gesetzlichen Aufbewahrungspflichten bestehen.</p>'
                    .'<h2>6. KI-Dienst zur Bestimmung und Übersetzung (Google Gemini)</h2><p>Zur Bestimmung der Sammlungsobjekte und zur Übersetzung der Website nutze ich im nicht öffentlichen Verwaltungsbereich den Dienst Gemini von Google ([Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland]). Übermittelt werden ausschließlich Fotos der Objekte und Texte der Website – <strong>keine Daten von Besucherinnen und Besuchern</strong> und keine Kontaktnachrichten. Fotos von Briefen und Postkarten können Namen und Anschriften enthalten; ich prüfe solche Belege vor der Veröffentlichung. Rechtsgrundlage ist mein berechtigtes Interesse an einer effizienten Katalogisierung (Art. 6 Abs. 1 lit. f DSGVO). Eine Verarbeitung in den USA ist möglich; Google ist nach dem EU-US Data Privacy Framework zertifiziert. Laut den Nutzungsbedingungen verwendet Google Eingaben bei der kostenpflichtigen Nutzung nicht zum Training seiner Modelle. [Bitte Tarif und aktuelle Bedingungen prüfen: https://ai.google.dev/gemini-api/terms]</p>'
                    .'<h2>7. Ihre Rechte</h2><p>Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Wenden Sie sich dazu einfach an die oben genannte Adresse. Außerdem können Sie sich bei einer Datenschutz-Aufsichtsbehörde beschweren, z. B. bei [zuständige Aufsichtsbehörde].</p>'
                    .'<p>Stand: [Datum]</p>',
                'contentEn' => $todoEn
                    .'<h2>1. Controller</h2><p>[First name last name]<br>[Address]<br>Email: [email address]</p>'
                    .'<h2>2. Summary</h2><ul><li>This website sets <strong>no cookies</strong> and uses <strong>no tracking</strong> or analytics.</li><li><strong>No external content</strong> is loaded – fonts, scripts and images are served from this server.</li><li>I only process personal data if you write to me via the contact form or by email, and briefly in the server logs.</li></ul>'
                    .'<h2>3. Hosting and server logs</h2><p>The website is hosted by [name and address of the host] under a data processing agreement (Art. 28 GDPR). When you open a page, the server processes technically necessary data: shortened IP address, date and time, requested address, amount of data and browser/operating system details, to operate the site securely (Art. 6(1)(f) GDPR). Logs are deleted after [7] days.</p><p>Finished pages are cached to speed up the website. This cache contains no personal data.</p>'
                    .'<h2>4. Watchlist</h2><p>If you mark items with the heart, your browser stores only their numbers in the local storage of your device. This data is not transmitted to me. Storing it is strictly necessary for the function you requested (Section 25(2) no. 2 TDDDG). You can delete the list at any time with “Clear list” or in your browser settings.</p>'
                    .'<h2>5. Contact form and email</h2><p>If you write to me, I process your name, email address, message, language and time in order to answer you (Art. 6(1)(b) and (f) GDPR). The message is forwarded to me by email ([email provider]). Your IP address is not stored; to prevent abuse it is only used in hashed form for at most 30 minutes to limit requests. Messages are deleted automatically after [6] months unless statutory retention periods apply.</p>'
                    .'<h2>6. AI service for identification and translation (Google Gemini)</h2><p>To identify the items and translate the website, I use Google\'s Gemini service in the non-public admin area ([Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Ireland]). Only photos of the items and texts of the website are transmitted – <strong>no visitor data</strong> and no contact messages. Photos of letters and postcards may contain names and addresses; I check such items before publishing. The legal basis is my legitimate interest in efficient cataloguing (Art. 6(1)(f) GDPR). Processing in the USA is possible; Google is certified under the EU-US Data Privacy Framework. According to its terms, Google does not use inputs of paid services to train its models. [Please check plan and current terms: https://ai.google.dev/gemini-api/terms]</p>'
                    .'<h2>7. Your rights</h2><p>You have the right of access (Art. 15 GDPR), rectification (Art. 16), erasure (Art. 17), restriction of processing (Art. 18), data portability (Art. 20) and objection (Art. 21). Simply contact the address above. You may also lodge a complaint with a data protection supervisory authority, e.g. [competent authority].</p>'
                    .'<p>Last updated: [date]</p>',
            ],
            [
                'key' => 'accessibility',
                'de' => 'Barrierefreiheit',
                'en' => 'Accessibility',
                'contentDe' => '<p>Diese Website soll für alle Menschen gut nutzbar sein – unabhängig von Einschränkungen, Endgerät oder eingesetzter Hilfstechnik.</p><h2>Stand der Barrierefreiheit</h2><p>Die Website wurde nach den Web Content Accessibility Guidelines (WCAG) 2.2, Stufe AA, gestaltet und mit automatischen Prüfungen sowie per Tastatur getestet: Alle Funktionen sind ohne Maus bedienbar, Kontraste sind ausreichend, Bilder haben Textbeschreibungen und die Seiten funktionieren auch bei starker Vergrößerung.</p><h2>Bekannte Einschränkungen</h2><ul><li>Bildbeschreibungen und Übersetzungen werden mit Hilfe einer KI erstellt und können ungenau sein.</li><li>Historische Fotos und Belege enthalten teilweise schwer lesbare Handschrift, die nicht vollständig als Text vorliegt.</li></ul><h2>Feedback</h2><p>Sind Ihnen Barrieren aufgefallen? Schreiben Sie mir über das Kontaktformular – ich kümmere mich darum.</p><p>Stand: [Datum]</p>',
                'contentEn' => '<p>This website aims to be usable by everyone – regardless of disability, device or assistive technology.</p><h2>Accessibility status</h2><p>The website was designed according to the Web Content Accessibility Guidelines (WCAG) 2.2, level AA, and tested automatically and with the keyboard: all functions work without a mouse, contrasts are sufficient, images have text descriptions and pages also work at high zoom levels.</p><h2>Known limitations</h2><ul><li>Image descriptions and translations are created with the help of an AI and may be inaccurate.</li><li>Historic photos and covers partly contain handwriting that is hard to read and not fully available as text.</li></ul><h2>Feedback</h2><p>Have you noticed a barrier? Please write to me using the contact form – I will take care of it.</p><p>Last updated: [date]</p>',
            ],
        ];
    }
}
