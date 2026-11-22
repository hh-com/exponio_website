<?php

namespace App\Form\Admin;

use App\Entity\ItemImage;
use App\Enum\ImageSide;
use App\Media\MediaStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Ein Bild im Objekt-Formular: Datei, Seite (Vorder-/Rückseite/Detail), Alt-Text, Reihenfolge
 * und die gewünschte Drehung und der Zuschnitt (versteckte Felder, gesetzt von den Knöpfen in admin/form/theme.html.twig
 * und assets/admin/imageEditing.ts). Ausgeführt wird beides beim Speichern (ItemCrudController::processImages()).
 *
 * @extends AbstractType<ItemImage>
 */
final class ItemImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Datei',
                'help' => 'JPEG, PNG, WebP oder AVIF, höchstens 25 MB.',
                'required' => false,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp,image/avif'],
            ])
            ->add('side', EnumType::class, [
                'label' => 'Seite',
                'class' => ImageSide::class,
                'required' => false,
                'placeholder' => 'unbekannt',
                'choice_label' => static fn (ImageSide $side): string => $side->label(),
            ])
            ->add('alt', TextType::class, [
                'label' => 'Alt-Text (für Screenreader)',
                'required' => false,
                'help' => 'Kurz beschreiben, was zu sehen ist (höchstens 255 Zeichen). Die KI füllt das Feld vor.',
                'attr' => ['maxlength' => 255],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Reihenfolge',
                'required' => false,
                'help' => 'Kleinere Zahl steht weiter vorne.',
            ])
            ->add('rotate', HiddenType::class, [
                'attr' => ['data-image-rotate' => ''],
            ])
            ->add('cropChange', HiddenType::class, [
                'required' => false,
                'attr' => ['data-image-crop' => ''],
            ]);

        // Grad im Uhrzeigersinn; nur 0, 90, 180 und 270 kommen durch
        $builder->get('rotate')->addModelTransformer(new CallbackTransformer(
            static fn (?int $degrees): string => (string) ($degrees ?? 0),
            static fn (?string $value): int => MediaStorage::normalizeRotation((int) $value),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ItemImage::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'item_image';
    }
}
