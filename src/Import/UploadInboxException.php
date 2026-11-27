<?php

namespace App\Import;

/**
 * Fehler der Upload-Schnittstelle mit stabilem Code für die App, verständlicher Meldung und HTTP-Status.
 */
final class UploadInboxException extends \RuntimeException
{
    public const INVALID_REQUEST = 'INVALID_REQUEST';
    public const INVALID_NAME = 'INVALID_NAME';
    public const INVALID_FILE = 'INVALID_FILE';
    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    public const INCOMPLETE = 'INCOMPLETE';
    public const CHECKSUM_MISMATCH = 'CHECKSUM_MISMATCH';
    public const UPLOAD_NOT_FOUND = 'UPLOAD_NOT_FOUND';
    public const VERIFY_FAILED = 'VERIFY_FAILED';
    public const STORAGE_UNAVAILABLE = 'STORAGE_UNAVAILABLE';
    public const STORAGE_ERROR = 'STORAGE_ERROR';

    /**
     * @param array<string, mixed> $details zusätzliche Angaben für die App (z. B. die fehlenden Dateien)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
