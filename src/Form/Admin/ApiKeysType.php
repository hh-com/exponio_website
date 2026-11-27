<?php

namespace App\Form\Admin;

use App\Ai\AiCredentials;
use App\Ai\AiProvider;
use App\Service\SettingsProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * API-Keys aller Anbieter (Einstellungen → KI): je Anbieter ein Feld für einen neuen Key und, wenn einer gespeichert
 * ist, „entfernen“. Die Hilfe zeigt, woher der Key kommt und wie er endet. Übernommen wird in
 * SettingsCrudController::updateEntity() (Settings::takeNewApiKeys()), verschlüsselt mit App\Ai\SecretBox.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class ApiKeysType extends AbstractType
{
    private const LINKS = [
        AiProvider::GEMINI => 'https://aistudio.google.com/apikey',
        AiProvider::ANTHROPIC => 'https://console.anthropic.com/settings/keys',
        AiProvider::MISTRAL => 'https://console.mistral.ai/api-keys',
        AiProvider::OPENAI => 'https://platform.openai.com/api-keys',
    ];

    public function __construct(
        private readonly AiCredentials $credentials,
        private readonly SettingsProvider $settings,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $used = [];
        foreach ($this->settings->get()->recognizerConfigs() as $row) {
            if ($row->active) {
                $used[$row->provider] = true;
            }
        }

        foreach (AiProvider::CHOICES as $label => $provider) {
            $entry = $builder->create($provider, FormType::class, [
                'label' => $label.(isset($used[$provider]) ? ' · aktiv genutzt' : ''),
                'required' => false,
                'row_attr' => ['class' => 'api-key-entry'],
            ]);
            $entry->add('key', PasswordType::class, [
                'label' => 'Neuer Key',
                'required' => false,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password', 'spellcheck' => 'false'],
                'help' => $this->status($provider).' Key erstellen: '.self::LINKS[$provider],
            ]);
            if ($this->credentials->hasStored($provider)) {
                $entry->add('remove', CheckboxType::class, [
                    'label' => 'Gespeicherten Key entfernen',
                    'required' => false,
                ]);
            }
            $builder->add($entry);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'required' => false]);
    }

    private function status(string $provider): string
    {
        return match (true) {
            AiCredentials::SOURCE_ENV === $this->credentials->source($provider) => sprintf('Kommt aus %s in .env.local (%s) und hat Vorrang.', AiCredentials::envName($provider), $this->credentials->hint($provider)),
            $this->credentials->isUnreadable($provider) => 'Der gespeicherte Key lässt sich nicht mehr entschlüsseln (APP_SECRET wurde geändert). Bitte neu eingeben.',
            null !== $this->credentials->source($provider) => sprintf('Hinterlegt, endet auf %s.', $this->credentials->hint($provider)),
            default => 'Noch kein Key hinterlegt.',
        };
    }
}
