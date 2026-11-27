<?php

namespace App\Security;

use App\Repository\AppTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Prüft das Bearer-Token der Exponio-App (Firewall „app_api“ in config/packages/security.yaml) gegen die App-Zugänge.
 * Angemeldet ist danach kein Backend-Benutzer, sondern nur der Zugang mit der Rolle ROLE_APP_UPLOAD.
 */
final class AppTokenHandler implements AccessTokenHandlerInterface
{
    public const ROLE = 'ROLE_APP_UPLOAD';

    public function __construct(
        private readonly AppTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->findOneByToken($accessToken) ?? throw new BadCredentialsException('Unbekanntes App-Token.');
        if ($token->touch(new \DateTimeImmutable())) {
            $this->em->flush();
        }

        return new UserBadge('app-token-'.$token->getId(), static fn (string $identifier): InMemoryUser => new InMemoryUser($identifier, null, [self::ROLE]));
    }
}
