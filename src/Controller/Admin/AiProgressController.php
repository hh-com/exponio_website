<?php

namespace App\Controller\Admin;

use App\Ai\AiProgress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zwischenstand einer laufenden KI-Aufgabe (App\Ai\AiProgress), abgefragt vom Toast im Backend (assets/admin/aiProgress.ts).
 */
#[IsGranted('ROLE_ADMIN')]
final class AiProgressController extends AbstractController
{
    #[Route('/admin/ai-progress', name: 'admin_ai_progress', methods: ['GET'])]
    public function __invoke(Request $request, AiProgress $progress): JsonResponse
    {
        // Session sofort freigeben: Die Abfrage läuft parallel zur langen KI-Anfrage
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }

        $response = $this->json(['steps' => $progress->steps((string) $request->query->get('id', ''))]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
