<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @extends AbstractCrudController<User>
 */
class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Benutzer')
            ->setEntityLabelInPlural('Benutzer')
            ->setDefaultSort(['email' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'E-Mail (Login)');
        yield TextField::new('plainPassword', 'Neues Passwort')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp(Crud::PAGE_EDIT === $pageName ? 'Leer lassen, um das Passwort zu behalten. Mindestens 10 Zeichen.' : 'Mindestens 10 Zeichen.')
            ->onlyOnForms();
        yield DateTimeField::new('createdAt', 'Angelegt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPassword(User $user): void
    {
        $plain = $user->getPlainPassword();
        if (null !== $plain && '' !== $plain) {
            $user->setPassword($this->hasher->hashPassword($user, $plain));
        }
        $user->setPlainPassword(null);
    }
}
