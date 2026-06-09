<?php

namespace App\Controller\Admin;

use App\Entity\BetaRequest;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class BetaRequestCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BetaRequest::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Beta Request')
            ->setEntityLabelInPlural('Beta Requests')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('valid'))
            ->add(TextFilter::new('email'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnIndex();
        yield EmailField::new('email');
        yield BooleanField::new('valid')->renderAsSwitch(false);
        yield IntegerField::new('attempts');
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('verifiedAt')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('expiresAt')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex();
    }
}
