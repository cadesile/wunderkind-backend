<?php

namespace App\Controller\Admin;

use App\Entity\Transfer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TransferCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transfer::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setDefaultSort(['occurredAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('player');
        yield AssociationField::new('academy');
        yield TextField::new('typeValue', 'Type');
        yield IntegerField::new('fee')
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—');
        yield IntegerField::new('agentCommission')
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—');
        yield IntegerField::new('netProceeds')
            ->formatValue(fn($v) => $v !== null ? '£' . number_format((int) $v / 100) : '—');
        yield TextField::new('destinationClubName');
        yield DateTimeField::new('occurredAt')->setFormat('yyyy-MM-dd HH:mm');
    }
}
