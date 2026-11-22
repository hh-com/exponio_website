<?php

namespace App\Form\Admin;

use App\Entity\AbstractTranslation;
use App\Entity\AttributeDefinitionTranslation;
use App\Repository\LanguageRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formular für eine Übersetzung (alle Sprachen außer Deutsch). Die Felder ergeben sich aus
 * der Übersetzungsklasse. Wird ein Text geändert, gilt die Übersetzung als „von Hand“ und wird
 * erst wieder von der KI überschrieben, wenn sich der deutsche Text ändert.
 *
 * @extends AbstractType<AbstractTranslation>
 */
final class TranslationType extends AbstractType
{
    private const LABELS = [
        'title' => 'Titel',
        'description' => 'Beschreibung',
        'series' => 'Serie / Ausgabe',
        'defects' => 'Mängel',
        'metaDescription' => 'Meta-Beschreibung (Suchmaschinen)',
        'name' => 'Name',
        'label' => 'Bezeichnung',
        'alt' => 'Alt-Text',
        'content' => 'Inhalt (HTML)',
        'tagline' => 'Untertitel',
        'intro' => 'Einleitung Startseite',
        'footerText' => 'Text im Fußbereich',
    ];
    private const LONG = ['description', 'defects', 'metaDescription', 'content', 'intro', 'footerText'];

    /** @var \WeakMap<AbstractTranslation, array<string, string>> Texte vor dem Absenden */
    private \WeakMap $before;

    public function __construct(private readonly LanguageRepository $languages)
    {
        $this->before = new \WeakMap();
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $translation = $event->getData();
            if (!$translation instanceof AbstractTranslation) {
                return;
            }
            $this->before[$translation] = $translation->values();
            $form = $event->getForm();
            $language = $this->languages->find($translation->getLocale());
            $form->add('language', TextType::class, [
                'label' => 'Sprache',
                'mapped' => false,
                'disabled' => true,
                'data' => sprintf('%s (%s) · %s · %s', $language?->getNameDe() ?? $translation->getLocale(), $translation->getLocale(), $translation->getOrigin()->label(), $translation->getUpdatedAt()->format('d.m.Y')),
            ]);
            foreach ($translation::fields() as $field) {
                $long = \in_array($field, self::LONG, true);
                $form->add($field, $long ? TextareaType::class : TextType::class, [
                    'label' => self::LABELS[$field] ?? $field,
                    'required' => false,
                    'attr' => $long ? ['rows' => 'content' === $field ? 8 : 3] : [],
                ]);
            }
            if ($translation instanceof AttributeDefinitionTranslation) {
                $form->add('optionLabelsText', TextareaType::class, [
                    'label' => 'Auswahlwerte',
                    'required' => false,
                    'help' => 'Eine Zeile pro Wert: wert | Text',
                    'attr' => ['rows' => 4],
                ]);
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $translation = $event->getData();
            if ($translation instanceof AbstractTranslation && ($this->before[$translation] ?? null) !== $translation->values()) {
                $translatable = $translation->getTranslatable();
                if (null !== $translatable) {
                    $translation->markManual($translatable->sourceHash());
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AbstractTranslation::class, 'label' => false]);
    }
}
