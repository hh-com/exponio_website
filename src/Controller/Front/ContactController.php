<?php

namespace App\Controller\Front;

use App\Entity\Inquiry;
use App\Service\InquiryNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Kontaktseite und JSON-Endpunkt für Nachrichten.
 *
 * Datensparsam: nur Name, E-Mail und Nachricht; keine IP-Adresse wird gespeichert.
 * Schutz: Same-Origin-CSRF (zustandslos, kein Cookie), Honeypot, Rate-Limit pro (gehashter) IP.
 */
final class ContactController extends FrontController
{
    #[Route(['de' => '/kontakt', 'en' => '/contact'], name: 'contact', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('front/contact/index.html.twig');
    }

    #[Route('/api/inquiry', name: 'api_inquiry', methods: ['POST'])]
    public function submit(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        InquiryNotifier $notifier,
        TranslatorInterface $translator,
        RateLimiterFactoryInterface $inquiryLimiter,
        #[Autowire('%kernel.secret%')]
        string $secret,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['ok' => false, 'error' => $translator->trans('inquiry.error_generic')], 400);
        }
        if (!$this->isCsrfTokenValid('submit', (string) ($data['token'] ?? ''))) {
            return $this->json(['ok' => false, 'error' => $translator->trans('inquiry.error_generic')], 403);
        }
        // Honeypot: Bots füllen das unsichtbare Feld aus – stillschweigend "erfolgreich"
        if ('' !== trim((string) ($data['website'] ?? ''))) {
            return $this->json(['ok' => true]);
        }
        // Rate-Limit: die IP wird nur gehasht und kurzzeitig im Cache gehalten
        $client = hash('sha256', ($request->getClientIp() ?? 'unknown').$secret);
        if (!$inquiryLimiter->create($client)->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'error' => $translator->trans('inquiry.error_rate')], 429);
        }

        $input = [
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'message' => trim((string) ($data['message'] ?? '')),
        ];
        $violations = $validator->validate($input, new Assert\Collection([
            'name' => [new Assert\NotBlank(message: 'inquiry.v_name'), new Assert\Length(max: 120)],
            'email' => [new Assert\NotBlank(message: 'inquiry.v_email'), new Assert\Email(message: 'inquiry.v_email'), new Assert\Length(max: 180)],
            'message' => [new Assert\NotBlank(message: 'inquiry.v_message'), new Assert\Length(min: 10, max: 5000, minMessage: 'inquiry.v_message')],
        ]));
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $field = trim($violation->getPropertyPath(), '[]');
                $template = $violation->getMessageTemplate();
                $errors[$field] ??= str_starts_with($template, 'inquiry.') ? $translator->trans($template) : (string) $violation->getMessage();
            }

            return $this->json(['ok' => false, 'errors' => $errors], 422);
        }

        $inquiry = new Inquiry($input['name'], $input['email'], $input['message'], $request->getLocale());
        $em->persist($inquiry);
        $em->flush();
        $notifier->notify($inquiry);

        return $this->json(['ok' => true]);
    }
}
