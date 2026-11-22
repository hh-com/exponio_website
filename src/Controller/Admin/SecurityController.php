<?php

namespace App\Controller\Admin;

use App\Media\BrandAssets;
use App\Service\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/admin/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, SettingsProvider $settings, BrandAssets $brand): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        return $this->render('@EasyAdmin/page/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
            'page_title' => $settings->get()->getSiteName().' · Verwaltung',
            'favicon_path' => $brand->url('favicon'),
            'csrf_token_intention' => 'authenticate',
            'action' => $this->generateUrl('admin_login'),
            'target_path' => $this->generateUrl('admin'),
            'username_label' => 'E-Mail',
            'password_label' => 'Passwort',
            'sign_in_label' => 'Anmelden',
            'remember_me_enabled' => true,
            'remember_me_label' => 'Angemeldet bleiben',
        ]);
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Wird von der Firewall abgefangen.');
    }
}
