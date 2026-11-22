<?php

namespace App\Controller\Admin;

use App\Duplicate\DuplicateMerger;
use App\Repository\ItemUploadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aktionen der Upload-Historie (Detailseite eines Objekts, Vorlage admin/field/uploads.html.twig):
 * Fotos eines Uploads zeigen, Ersetzen rückgängig machen, Vorschlag ablehnen, Upload als eigenes Objekt abtrennen.
 */
#[IsGranted('ROLE_ADMIN')]
final class UploadController extends AbstractController
{
    public const CSRF_PREFIX = 'upload';

    #[Route('/admin/upload/{id}/{action}', name: 'admin_upload_action', requirements: ['id' => '\d+', 'action' => 'use|undo|reject|split'], methods: ['POST'])]
    public function action(int $id, string $action, Request $request, ItemUploadRepository $uploads, DuplicateMerger $merger): Response
    {
        $upload = $uploads->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }
        $itemId = $upload->getItem()->getId();

        try {
            if ('use' === $action) {
                $merger->useImages($upload);
                $this->addFlash('success', 'Die Fotos dieses Uploads werden jetzt gezeigt. Die bisherigen bleiben als „früher“ erhalten.');
            } elseif ('undo' === $action) {
                $merger->undo($upload);
                $this->addFlash('success', 'Rückgängig gemacht: Die vorherigen Fotos werden wieder gezeigt.');
            } elseif ('reject' === $action) {
                $merger->rejectProposal($upload);
                $this->addFlash('success', 'Vorschlag abgelehnt. Der Upload bleibt gezählt, die Fotos bleiben ausgeblendet.');
            } else {
                $item = $merger->split($upload);
                $this->addFlash('success', sprintf('Als eigenes Objekt #%d angelegt (Entwurf). app:scan bzw. „Prüfen & freigeben“ erkennt es ohne erneute Duplikatprüfung.', $item->getId()));

                return $this->redirectToRoute('admin_item_edit', ['entityId' => $item->getId()]);
            }
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('admin_item_detail', ['entityId' => $itemId]);
    }
}
