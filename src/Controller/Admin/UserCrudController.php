<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $deleteAction = Action::new('confirmDeleteUser', 'Delete', 'fa fa-user-slash')
            ->linkToUrl(fn(User $entity) => $this->generateUrl('admin_user_delete_info', ['id' => $entity->getId()]))
            ->setHtmlAttributes(['data-delete-trigger' => '1', 'data-delete-mode' => 'user'])
            ->setCssClass('btn btn-sm btn-outline-danger');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $deleteAction);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield ArrayField::new('roles');
        yield IntegerField::new('clubs.count', 'Clubs')
            ->formatValue(fn($v, User $u) => $u->getClubs()->count())
            ->setSortable(false);
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm');
    }
}
