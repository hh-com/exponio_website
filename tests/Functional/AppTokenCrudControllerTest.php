<?php

namespace App\Tests\Functional;

use App\Controller\Admin\AppTokenCreatedController;
use App\Controller\Admin\AppTokenCrudController;
use App\Controller\Admin\DashboardController;
use App\Entity\AppToken;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Test\AbstractCrudTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Backend → System → App-Zugänge: Token anlegen, genau einmal anzeigen, nur als Hash speichern.
 *
 * @extends AbstractCrudTestCase<AppTokenCrudController>
 */
final class AppTokenCrudControllerTest extends AbstractCrudTestCase
{
    protected function getControllerFqcn(): string
    {
        return AppTokenCrudController::class;
    }

    protected function getDashboardFqcn(): string
    {
        return DashboardController::class;
    }

    public function testCreatesTokenAndShowsItOnce(): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        self::assertNotNull($user);
        $this->client->loginUser($user);
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        $createdPage = $router->generate('admin_app_token_created_show');

        $crawler = $this->client->request('GET', $this->generateNewFormUrl());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter($this->getEntityFormSelector())->form([$this->getFormEntity().'[label]' => 'Pixel Test']);
        $this->client->submit($form);
        self::assertResponseRedirects($createdPage);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $token = (string) $crawler->filter('#app-token')->attr('value');
        self::assertMatchesRegularExpression('/^exp_[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame('https://localhost', $crawler->filter('#app-url')->attr('value'), 'die App spricht nur HTTPS');

        $this->client->request('GET', $createdPage);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent(), 'nur einmal sichtbar');

        $entity = $this->entityManager->getRepository(AppToken::class)->findOneBy(['tokenHash' => AppToken::hash($token)]);
        self::assertNotNull($entity, 'gespeichert wird der Hash');
        $this->client->request('GET', $this->generateIndexUrl());
        self::assertResponseIsSuccessful();
        $this->assertIndexColumnExists('label');
        $this->assertIndexColumnExists('lastUsedAt');
        $this->assertIndexEntityActionExists(Action::DELETE, (int) $entity->getId());
        $this->assertIndexEntityActionNotExists(Action::EDIT, (int) $entity->getId());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Pixel Test', $html);
        self::assertStringContainsString('…'.substr($token, -4), $html);
        self::assertStringContainsString('noch nie', $html);
        self::assertStringNotContainsString($token, $html);

        $this->client->request('GET', $router->generate('api_app_status'), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful('die App meldet sich mit dem neuen Token an');
    }

    public function testSiteUrlForTheAppIsAlwaysHttpsWithoutDefaultPort(): void
    {
        $script = ['SCRIPT_NAME' => '/sammlung/index.php', 'SCRIPT_FILENAME' => '/var/www/sammlung/index.php'];
        self::assertSame('https://exponio.example.org', AppTokenCreatedController::siteUrl(Request::create('http://exponio.example.org/admin/app-token-created')), 'TLS-Proxy ohne trusted_proxies');
        self::assertSame('https://exponio.example.org', AppTokenCreatedController::siteUrl(Request::create('http://exponio.example.org:443/admin/app-token-created')), 'Proxy reicht „:443“ durch');
        self::assertSame('https://exponio.example.org:8443', AppTokenCreatedController::siteUrl(Request::create('https://exponio.example.org:8443/admin/app-token-created')));
        self::assertSame('https://exponio.example.org/sammlung', AppTokenCreatedController::siteUrl(Request::create('https://exponio.example.org/sammlung/admin/app-token-created', server: $script)));
        self::assertSame('https://exponio.example.org/sammlung', AppTokenCreatedController::siteUrl(Request::create('https://exponio.example.org/sammlung/index.php/admin/app-token-created', server: $script)), 'ohne index.php');

        // Proxy mit SYMFONY_TRUSTED_PROXIES: Host, Port und Präfix kommen aus den X-Forwarded-Kopfzeilen
        $proxies = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX);
        try {
            $request = Request::create('http://backend:8080/admin/app-token-created', server: [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'exponio.example.org',
                'HTTP_X_FORWARDED_PORT' => '443',
                'HTTP_X_FORWARDED_PREFIX' => '/sammlung',
            ]);
            self::assertSame('https://exponio.example.org/sammlung', AppTokenCreatedController::siteUrl($request));
        } finally {
            // -1 (Symfonys „nie gesetzt“) wirkt ohne vertrauenswürdige Proxys genauso wie 0
            Request::setTrustedProxies($proxies, $headers >= 0 && $headers <= 63 ? $headers : 0);
        }
    }
}
