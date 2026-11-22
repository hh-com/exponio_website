<?php

namespace App\Controller\Admin;

use App\Recognition\RecognitionService;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Prüf-Warteschlange (Vue-App): KI-Vorschläge kontrollieren, korrigieren und freigeben.
 */
#[IsGranted('ROLE_ADMIN')]
#[AdminRoute('/review', name: 'review')]
final class ReviewController extends AbstractController
{
    #[AdminRoute('/', name: 'index')]
    public function index(RecognitionService $recognition, CsrfTokenManagerInterface $csrf): Response
    {
        return $this->render('admin/review.html.twig', [
            'props' => [
                'api' => $this->generateUrl('admin_api_review_list'),
                'csrf' => $csrf->getToken(ReviewApiController::CSRF_ID)->getValue(),
                'fake' => $recognition->isFake(),
            ],
        ]);
    }
}
