<?php

namespace App\Import;

use App\Repository\ScanJobRepository;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Nimmt Uploads der Exponio-App über die Schnittstelle entgegen (App\Controller\Api\AppUploadController) und legt sie
 * im Upload-Verzeichnis (SCAN_DIR) so ab, wie FolderImporter sie erwartet:
 *
 *  1. Jede Datei landet in "_tmp_<id>/" (id = Zeitstempel der App, z. B. 2026-11-20_211530). Sie wird zuerst unter
 *     einem versteckten Namen geschrieben, gegen Größe und SHA-256 geprüft (Bilder zusätzlich auf ihr Format) und erst
 *     dann umbenannt. Eine erneut gesendete Datei ersetzt die alte, so lässt sich ein abgebrochener Upload fortsetzen.
 *  2. „Abschließen“ vergleicht den Ordner mit der Liste der App (Name, Größe, SHA-256), entfernt Fremdes im eigenen
 *     _tmp_-Ordner und benennt ihn atomar in "<id>" um, bei Kollision "<id>_2" usw. Erst dann sieht FolderImporter ihn.
 *  3. Abschließen ist wiederholbar: Ging die Antwort verloren, findet ein zweiter Aufruf den fertigen Ordner an seinem
 *     Inhalt wieder, statt einen zweiten anzulegen.
 *
 * Außerhalb der eigenen _tmp_-Ordner wird nie etwas gelöscht oder überschrieben.
 */
final class UploadInbox
{
    public const ID_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';
    public const TMP_PREFIX = '_tmp_';
    /** Notiz zur Fotogruppe aus der App (UTF-8-Text), beim Import der Hinweis an die KI */
    public const NOTE_FILE = FolderImporter::NOTE_FILE;
    public const MAX_FILE_SIZE = 64 * 1024 * 1024;
    public const MAX_NOTE_SIZE = 64 * 1024;
    public const MAX_FILES = 999;

    /** Fotos heißen 01.webp, 02.webp … (ab 100 Fotos dreistellig) */
    private const IMAGE_NAME = '/^\d{2,3}\.(webp|jpe?g|png)$/';
    private const IMAGE_TYPES = ['webp' => \IMAGETYPE_WEBP, 'jpg' => \IMAGETYPE_JPEG, 'jpeg' => \IMAGETYPE_JPEG, 'png' => \IMAGETYPE_PNG];
    private const MAX_SUFFIX = 99;
    private const CHUNK = 65536;
    private const PROBE_TEXT = "Exponio Schreibtest\n";

    private readonly Filesystem $fs;

    public function __construct(
        private readonly string $scanDir,
        private readonly ScanJobRepository $scanJobs,
        private readonly LockFactory $locks,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * Stellt sicher, dass das Upload-Verzeichnis existiert und beschreibbar ist (legt es bei Bedarf an).
     * Mit $writeTest zusätzlich ein echter Schreibtest: Testordner mit kleiner Datei anlegen und wieder entfernen.
     *
     * @throws UploadInboxException
     */
    public function check(bool $writeTest): void
    {
        $this->ensureStorage();
        if (!$writeTest) {
            return;
        }
        $probe = $this->scanDir.'/.exponio_probe_'.bin2hex(random_bytes(6));
        try {
            $this->fs->mkdir($probe);
            $this->fs->dumpFile($probe.'/probe.txt', self::PROBE_TEXT);
            if (\strlen(self::PROBE_TEXT) !== filesize($probe.'/probe.txt')) {
                throw new UploadInboxException(UploadInboxException::STORAGE_UNAVAILABLE, 'Schreibtest im Upload-Verzeichnis fehlgeschlagen: Die Testdatei wurde nicht vollständig gespeichert.', 503);
            }
        } catch (IOExceptionInterface $e) {
            throw new UploadInboxException(UploadInboxException::STORAGE_UNAVAILABLE, 'Schreibtest im Upload-Verzeichnis fehlgeschlagen. Hat der Webserver Schreibrechte, und ist auf dem Server noch Speicherplatz frei?', 503, previous: $e);
        } finally {
            try {
                $this->fs->remove($probe);
            } catch (IOExceptionInterface) {
            }
        }
    }

    /**
     * Dateien eines offenen (noch nicht abgeschlossenen) Uploads, damit die App nach einem Abbruch nur Fehlendes sendet.
     *
     * @return list<array{name: string, size: int, sha256: string}>|null null = kein offener Upload mit dieser id
     */
    public function openFiles(string $id): ?array
    {
        $this->assertId($id);
        $dir = $this->tmpPath($id);
        if (!is_dir($dir) || is_link($dir)) {
            return null;
        }

        return $this->describeFiles($dir);
    }

    /**
     * Speichert eine Datei im _tmp_-Ordner des Uploads.
     *
     * @param resource $body   Inhalt (Request-Body)
     * @param int|null $length angekündigte Größe (Content-Length), falls bekannt
     *
     * @return array{name: string, size: int, sha256: string}
     *
     * @throws UploadInboxException
     */
    public function storeFile(string $id, string $name, $body, string $sha256, ?int $length): array
    {
        $this->assertId($id);
        $this->assertName($name);
        $sha256 = strtolower(trim($sha256));
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new UploadInboxException(UploadInboxException::INVALID_REQUEST, 'Die Prüfsumme der Datei fehlt oder ist ungültig (Kopfzeile X-Content-SHA256).');
        }
        $max = self::NOTE_FILE === $name ? self::MAX_NOTE_SIZE : self::MAX_FILE_SIZE;
        if (null !== $length && $length > $max) {
            throw $this->tooLarge($name, $max);
        }
        $this->ensureStorage();
        $free = \function_exists('disk_free_space') ? @disk_free_space($this->scanDir) : false;
        if (null !== $length && false !== $free && $free < $length) {
            throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('Auf dem Server ist nicht genug Speicherplatz frei für „%s“.', $name), 507);
        }

        // Gesperrt wird nur kurz (Ordner und Teildatei anlegen, am Ende umbenennen), nicht während der Übertragung:
        // Hängt eine abgebrochene Verbindung noch, muss der nächste Versuch der App nicht auf sie warten.
        $dir = $this->tmpPath($id);
        $part = sprintf('%s/.%s.%s.part', $dir, $name, bin2hex(random_bytes(4)));
        $lock = $this->lock($id);
        try {
            if (!is_dir($dir) && (file_exists($dir) || is_link($dir) || (!@mkdir($dir, 0o775) && !is_dir($dir)))) {
                throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('Der Upload-Ordner „%s“ lässt sich nicht anlegen.', basename($dir)), 500);
            }
            $this->assertOwnDir($dir);
            $out = @fopen($part, 'xb');
            if (false === $out) {
                throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('„%s“ konnte nicht gespeichert werden.', $name), 500);
            }
        } finally {
            $lock->release();
        }

        try {
            [$size, $actual] = $this->receive($body, $out, $max, $name);
            if (null !== $length && $size !== $length) {
                throw new UploadInboxException(UploadInboxException::INCOMPLETE, sprintf('„%s“ ist unvollständig angekommen (%d von %d Bytes).', $name, $size, $length));
            }
            if (!hash_equals($sha256, $actual)) {
                throw new UploadInboxException(UploadInboxException::CHECKSUM_MISMATCH, sprintf('„%s“ wurde bei der Übertragung beschädigt (Prüfsumme stimmt nicht).', $name), 422);
            }
            $this->assertContent($part, $name);

            $lock = $this->lock($id);
            try {
                // inzwischen abgeschlossen? Dann hat complete die Teildatei als überzählig entfernt.
                if (!is_file($part)) {
                    throw new UploadInboxException(UploadInboxException::UPLOAD_NOT_FOUND, sprintf('„%s“ kam zu spät: Der Upload wurde inzwischen abgeschlossen oder verworfen.', $name), 409);
                }
                if (!@rename($part, $dir.'/'.$name)) {
                    throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('„%s“ konnte nicht gespeichert werden.', $name), 500);
                }
            } finally {
                $lock->release();
            }
        } finally {
            if (is_file($part)) {
                @unlink($part);
            }
        }

        return ['name' => $name, 'size' => $size, 'sha256' => $actual];
    }

    /**
     * Schließt den Upload ab: Inhalt prüfen, Fremdes entfernen, _tmp_-Ordner atomar umbenennen.
     *
     * @param list<array{name: string, size: int, sha256: string}> $manifest alle Dateien, die der Ordner enthalten soll
     *
     * @return array{folder: string, count: int, bytes: int, already: bool} count = Anzahl der Fotos
     *
     * @throws UploadInboxException
     */
    public function complete(string $id, array $manifest): array
    {
        $this->assertId($id);
        $manifest = $this->normalizeManifest($manifest);
        $images = \count(array_filter($manifest, static fn (array $f): bool => self::NOTE_FILE !== $f['name']));
        $bytes = array_sum(array_column($manifest, 'size'));
        $this->ensureStorage();

        $lock = $this->lock($id);
        try {
            $tmp = $this->tmpPath($id);
            if (!is_dir($tmp)) {
                // schon abgeschlossen, nur die Antwort ging verloren?
                foreach ($this->candidateNames($id) as $folder) {
                    if (is_dir($this->scanDir.'/'.$folder) && $this->matches($this->scanDir.'/'.$folder, $manifest)) {
                        return ['folder' => $folder, 'count' => $images, 'bytes' => $bytes, 'already' => true];
                    }
                }
                throw new UploadInboxException(UploadInboxException::UPLOAD_NOT_FOUND, 'Zu diesem Upload liegen auf dem Server keine Dateien (mehr). Bitte erneut hochladen.', 409);
            }
            $this->assertOwnDir($tmp);

            $problems = [];
            foreach ($manifest as $file) {
                $path = $tmp.'/'.$file['name'];
                if (!is_file($path) || filesize($path) !== $file['size'] || !hash_equals($file['sha256'], (string) hash_file('sha256', $path))) {
                    $problems[] = $file['name'];
                }
            }
            if ([] !== $problems) {
                $what = 1 === \count($problems) ? 'Eine Datei fehlt oder ist unvollständig' : \count($problems).' Dateien fehlen oder sind unvollständig';
                $names = implode(', ', \array_slice($problems, 0, 10)).(\count($problems) > 10 ? ' …' : '');
                throw new UploadInboxException(UploadInboxException::VERIFY_FAILED, sprintf('%s auf dem Server: %s. Bitte erneut hochladen.', $what, $names), 409, ['files' => $problems]);
            }

            // Reste früherer Versuche (andere Nummerierung, gelöschte Fotos, abgebrochene Teildateien) – nur im eigenen _tmp_-Ordner
            $expected = array_flip(array_column($manifest, 'name'));
            foreach (scandir($tmp) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry && !isset($expected[$entry])) {
                    try {
                        $this->fs->remove($tmp.'/'.$entry);
                    } catch (IOExceptionInterface $e) {
                        throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('Die überzählige Datei „%s“ lässt sich nicht entfernen.', $entry), 500, previous: $e);
                    }
                }
            }

            $folder = $this->freeName($id);
            if (!@rename($tmp, $this->scanDir.'/'.$folder)) {
                throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('Der Upload-Ordner ließ sich nicht in „%s“ umbenennen.', $folder), 500);
            }

            return ['folder' => $folder, 'count' => $images, 'bytes' => $bytes, 'already' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * Liest den Body in die (schon geöffnete) Teildatei, berechnet dabei Größe und SHA-256 und schließt sie.
     *
     * @param resource $body
     * @param resource $out
     *
     * @return array{int, string}
     */
    private function receive($body, $out, int $max, string $name): array
    {
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($body)) {
                $chunk = fread($body, self::CHUNK);
                if (false === $chunk) {
                    throw new UploadInboxException(UploadInboxException::INCOMPLETE, sprintf('Die Übertragung von „%s“ brach ab.', $name));
                }
                if ('' === $chunk) {
                    continue;
                }
                $size += \strlen($chunk);
                if ($size > $max) {
                    throw $this->tooLarge($name, $max);
                }
                hash_update($hash, $chunk);
                // bei voller Platte liefert fwrite false oder zu wenig Bytes (plus Notice, die hier nicht zum 500er werden soll)
                if (@fwrite($out, $chunk) !== \strlen($chunk)) {
                    throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('„%s“ konnte nicht vollständig gespeichert werden. Ist der Speicherplatz voll?', $name), 507);
                }
            }
        } finally {
            fclose($out);
        }

        return [$size, hash_final($hash)];
    }

    /** Bilder müssen zu ihrer Endung passen, die Notiz muss UTF-8 sein – alles andere wird abgelehnt. */
    private function assertContent(string $path, string $name): void
    {
        if (self::NOTE_FILE === $name) {
            if (!mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
                throw new UploadInboxException(UploadInboxException::INVALID_FILE, 'Die Notiz (info.txt) ist kein UTF-8-Text.', 415);
            }

            return;
        }
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        $info = @getimagesize($path);
        if (false === $info || $info[2] !== (self::IMAGE_TYPES[$extension] ?? null)) {
            throw new UploadInboxException(UploadInboxException::INVALID_FILE, sprintf('„%s“ ist kein gültiges %s-Bild.', $name, 'jpg' === $extension ? 'JPEG' : strtoupper($extension)), 415);
        }
    }

    /**
     * @param array<mixed> $manifest
     *
     * @return list<array{name: string, size: int, sha256: string}>
     */
    private function normalizeManifest(array $manifest): array
    {
        $files = [];
        foreach ($manifest as $file) {
            if (!\is_array($file) || !\is_string($file['name'] ?? null) || !\is_int($file['size'] ?? null) || $file['size'] < 0 || !\is_string($file['sha256'] ?? null)) {
                throw new UploadInboxException(UploadInboxException::INVALID_REQUEST, 'Die Dateiliste ist ungültig: Jede Datei braucht name, size und sha256.');
            }
            $this->assertName($file['name']);
            $sha256 = strtolower($file['sha256']);
            if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                throw new UploadInboxException(UploadInboxException::INVALID_REQUEST, sprintf('Die Prüfsumme von „%s“ ist ungültig.', $file['name']));
            }
            if (isset($files[$file['name']])) {
                throw new UploadInboxException(UploadInboxException::INVALID_REQUEST, sprintf('„%s“ steht doppelt in der Dateiliste.', $file['name']));
            }
            $files[$file['name']] = ['name' => $file['name'], 'size' => $file['size'], 'sha256' => $sha256];
        }
        if (\count($files) > self::MAX_FILES + 1) {
            throw new UploadInboxException(UploadInboxException::INVALID_REQUEST, sprintf('Höchstens %d Fotos pro Upload.', self::MAX_FILES));
        }
        if ([] === array_diff(array_keys($files), [self::NOTE_FILE])) {
            throw new UploadInboxException(UploadInboxException::INVALID_REQUEST, 'Der Upload enthält keine Fotos.');
        }

        return array_values($files);
    }

    /**
     * Enthält der Ordner genau die Dateien der Liste (versteckte Dateien zählen nicht)?
     *
     * @param list<array{name: string, size: int, sha256: string}> $manifest
     */
    private function matches(string $dir, array $manifest): bool
    {
        $present = array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => !str_starts_with($f, '.')));
        if (\count($present) !== \count($manifest)) {
            return false;
        }
        foreach ($manifest as $file) {
            $path = $dir.'/'.$file['name'];
            if (!is_file($path) || filesize($path) !== $file['size']) {
                return false;
            }
        }
        foreach ($manifest as $file) {
            if (!hash_equals($file['sha256'], (string) hash_file('sha256', $dir.'/'.$file['name']))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{name: string, size: int, sha256: string}> */
    private function describeFiles(string $dir): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            $path = $dir.'/'.$entry;
            if ($this->isValidName($entry) && is_file($path)) {
                $files[] = ['name' => $entry, 'size' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
            }
        }
        usort($files, static fn (array $a, array $b): int => strnatcmp($a['name'], $b['name']));

        return $files;
    }

    /** Erster Name, den es weder als Ordner gibt noch im Scan-Protokoll (dort bleiben auch Ordner gelöschter Objekte) */
    private function freeName(string $id): string
    {
        $known = $this->scanJobs->findKnownFolders();
        foreach ($this->candidateNames($id) as $folder) {
            if (!isset($known[$folder]) && !file_exists($this->scanDir.'/'.$folder) && !is_link($this->scanDir.'/'.$folder)) {
                return $folder;
            }
        }
        throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('Kein freier Ordnername für „%s“ gefunden.', $id), 409);
    }

    /** @return iterable<string> "<id>", "<id>_2" … – passend zu FolderImporter::FOLDER_PATTERN */
    private function candidateNames(string $id): iterable
    {
        yield $id;
        for ($i = 2; $i <= self::MAX_SUFFIX; ++$i) {
            yield $id.'_'.$i;
        }
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->scanDir)) {
            if (file_exists($this->scanDir) || (!@mkdir($this->scanDir, 0o775, true) && !is_dir($this->scanDir))) {
                throw new UploadInboxException(UploadInboxException::STORAGE_UNAVAILABLE, 'Das Upload-Verzeichnis (SCAN_DIR) fehlt auf dem Server und lässt sich nicht anlegen.', 503);
            }
        }
        if (!is_writable($this->scanDir)) {
            throw new UploadInboxException(UploadInboxException::STORAGE_UNAVAILABLE, 'Die Website darf nicht in das Upload-Verzeichnis (SCAN_DIR) schreiben. Bitte dem Webserver Schreibrechte geben.', 503);
        }
    }

    private function lock(string $id): LockInterface
    {
        $lock = $this->locks->createLock('app-upload-'.$id, 300);
        $lock->acquire(true);

        return $lock;
    }

    private function tmpPath(string $id): string
    {
        return $this->scanDir.'/'.self::TMP_PREFIX.$id;
    }

    /** Ein _tmp_-Ordner, der ein symbolischer Link ist, zeigt womöglich aus SCAN_DIR heraus: dort nichts schreiben oder löschen. */
    private function assertOwnDir(string $dir): void
    {
        if (is_link($dir)) {
            throw new UploadInboxException(UploadInboxException::STORAGE_ERROR, sprintf('„%s“ ist ein symbolischer Link und wird nicht benutzt.', basename($dir)), 500);
        }
    }

    private function isValidName(string $name): bool
    {
        return self::NOTE_FILE === $name || 1 === preg_match(self::IMAGE_NAME, $name);
    }

    private function assertId(string $id): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new UploadInboxException(UploadInboxException::INVALID_NAME, sprintf('Ungültige Upload-Kennung „%s“ (erwartet z. B. 2026-11-20_211530).', mb_substr($id, 0, 40)));
        }
    }

    private function assertName(string $name): void
    {
        if (!$this->isValidName($name)) {
            throw new UploadInboxException(UploadInboxException::INVALID_NAME, sprintf('Ungültiger Dateiname „%s“ (erlaubt: 01.webp, 02.jpg … und info.txt).', mb_substr($name, 0, 40)));
        }
    }

    private function tooLarge(string $name, int $max): UploadInboxException
    {
        $limit = $max >= 1024 * 1024 ? intdiv($max, 1024 * 1024).' MB' : intdiv($max, 1024).' KB';

        return new UploadInboxException(UploadInboxException::FILE_TOO_LARGE, sprintf('„%s“ ist zu groß (höchstens %s).', $name, $limit), 413);
    }
}
