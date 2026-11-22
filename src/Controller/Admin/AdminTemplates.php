<?php

namespace App\Controller\Admin;

/**
 * Eigene Vorlagen für das Backend.
 */
final class AdminTemplates
{
    /** Berechnete Spalte, deren Inhalt formatValue() liefert (ohne passende Eigenschaft in der Entity). */
    public const COMPUTED = 'admin/field/computed.html.twig';

    /** Upload-Historie eines Objekts mit Aktionen (Rückgängig, Fotos zeigen, Abtrennen) */
    public const UPLOADS = 'admin/field/uploads.html.twig';
}
