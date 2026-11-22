<?php

namespace App\Form\Admin;

use App\Entity\CollectionArea;
use App\Enum\AttributeType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Merkmale eines Objekts: je Objektart eine Feldgruppe. Im Formular ist nur die Gruppe
 * der gewählten Objektart sichtbar (assets/admin.ts), gespeichert werden nur deren Werte.
 *
 * @extends AbstractType<array<string, array<string, mixed>>>
 */
final class ItemAttributesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var CollectionArea $area */
        foreach ($options['areas'] as $area) {
            if ($area->getAttributeDefinitions()->isEmpty()) {
                continue;
            }
            $group = $builder->create('area_'.$area->getId(), FormType::class, [
                'label' => $area->getName(),
                'required' => false,
                'row_attr' => ['data-attr-area' => (string) $area->getId(), 'class' => 'attr-area-group'],
            ]);
            foreach ($area->getAttributeDefinitions() as $definition) {
                $label = $definition->getLabel().($definition->getUnit() ? ' ('.$definition->getUnit().')' : '');
                $common = ['label' => $label, 'required' => false];
                match ($definition->getType()) {
                    AttributeType::Text => $group->add((string) $definition->getCode(), TextType::class, $common + ['attr' => ['maxlength' => 191]]),
                    AttributeType::Number => $group->add((string) $definition->getCode(), NumberType::class, $common + ['scale' => 4, 'html5' => true, 'attr' => ['step' => 'any']]),
                    AttributeType::Select => $group->add((string) $definition->getCode(), ChoiceType::class, $common + [
                        'choices' => array_column($definition->getOptions(), 'value', 'label'),
                        'placeholder' => '—',
                    ]),
                    AttributeType::Boolean => $group->add((string) $definition->getCode(), ChoiceType::class, $common + [
                        'choices' => ['Ja' => '1', 'Nein' => '0'],
                        'placeholder' => '—',
                    ]),
                };
            }
            $builder->add($group);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'areas' => [],
            'required' => false,
            'label' => false,
        ]);
        $resolver->setAllowedTypes('areas', 'array');
    }
}
