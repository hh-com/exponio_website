<?php

namespace App\Form\Admin;

use App\Ai\AiProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Eine Zeile „Modelle für die Erkennung“ (Einstellungen → KI), als Array wie App\Ai\RecognizerConfig::toArray().
 * Dargestellt als Kachel in templates/admin/form/theme.html.twig (Block ai_recognizer_row).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class AiRecognizerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $price = static fn (string $label, string $help): array => [
            'label' => $label,
            'required' => false,
            'scale' => 4,
            'help' => $help,
            'constraints' => [new Assert\PositiveOrZero()],
            'attr' => ['placeholder' => 'z. B. 0,75', 'inputmode' => 'decimal'],
        ];

        $builder
            ->add('active', CheckboxType::class, [
                'label' => 'Aktiv',
                'required' => false,
                'help' => 'Aus = wird nicht gefragt, die Zeile bleibt für später.',
            ])
            ->add('provider', ChoiceType::class, [
                'label' => 'Anbieter',
                'choices' => AiProvider::CHOICES,
                'constraints' => [new Assert\NotBlank(), new Assert\Choice(choices: array_values(AiProvider::CHOICES))],
            ])
            ->add('model', TextType::class, [
                'label' => 'Modell',
                'required' => false,
                'help' => 'Leer = Standardmodell des Anbieters (siehe unten).',
                'attr' => ['placeholder' => 'Standard', 'maxlength' => 80, 'spellcheck' => 'false', 'autocomplete' => 'off'],
                'constraints' => [new Assert\Length(max: 80)],
            ])
            ->add('priceInput', NumberType::class, $price('Preis Eingabe', 'US-Dollar je Million Tokens, optional.'))
            ->add('priceOutput', NumberType::class, $price('Preis Ausgabe', 'US-Dollar je Million Tokens, mit Denk-Tokens.'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_data' => static fn (): array => ['active' => true, 'provider' => AiProvider::GEMINI, 'model' => null, 'priceInput' => null, 'priceOutput' => null],
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'ai_recognizer';
    }
}
