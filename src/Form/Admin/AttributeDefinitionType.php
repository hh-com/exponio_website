<?php

namespace App\Form\Admin;

use App\Entity\AttributeDefinition;
use App\Enum\AttributeType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Ein Merkmal einer Objektart im Backend-Formular (Bezeichnung, Code, Typ, Auswahlwerte, KI-Hinweis, Übersetzungen).
 *
 * @extends AbstractType<AttributeDefinition>
 */
final class AttributeDefinitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'Bezeichnung'])
            ->add('code', TextType::class, [
                'label' => 'Code',
                'help' => 'Technischer Name, z. B. "michel" – nicht mehr ändern, sobald Objekte Werte haben.',
            ])
            ->add('type', EnumType::class, [
                'label' => 'Typ',
                'class' => AttributeType::class,
                'choice_label' => static fn (AttributeType $t): string => match ($t) {
                    AttributeType::Text => 'Text',
                    AttributeType::Number => 'Zahl',
                    AttributeType::Select => 'Auswahl',
                    AttributeType::Boolean => 'Ja/Nein',
                },
            ])
            ->add('optionsText', TextareaType::class, [
                'label' => 'Optionen (nur bei Auswahl)',
                'required' => false,
                'attr' => ['rows' => 4, 'placeholder' => "mnh | postfrisch (**)\nused | gestempelt"],
                'help' => 'Eine Option pro Zeile: wert | Bezeichnung. Der Wert bleibt fest, Übersetzungen erstellt die KI.',
            ])
            ->add('unit', TextType::class, ['label' => 'Einheit', 'required' => false, 'help' => 'z. B. mm, g'])
            ->add('aiHint', TextType::class, ['label' => 'Hinweis für die KI', 'required' => false])
            ->add('filterable', CheckboxType::class, ['label' => 'Als Filter im Katalog (nur Auswahl/Ja-Nein)', 'required' => false])
            ->add('showInCard', CheckboxType::class, ['label' => 'Auf Karten anzeigen', 'required' => false])
            ->add('position', IntegerType::class, ['label' => 'Reihenfolge', 'required' => false])
            ->add('otherTranslations', CollectionType::class, [
                'label' => 'Übersetzungen',
                'entry_type' => TranslationType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AttributeDefinition::class]);
    }
}
