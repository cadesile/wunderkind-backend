<?php

namespace App\Controller\Admin;

use App\Entity\Scout;
use App\Form\Type\AppearanceType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ScoutCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Scout::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setDefaultSort(['name' => 'ASC'])
            ->addFormTheme('admin/form/appearance_theme.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name');
        yield DateField::new('dob')->setLabel('Date of Birth');
        yield TextField::new('nationality');
        yield IntegerField::new('experience');
        yield TextareaField::new('judgementsJson', 'Judgements')
            ->setFormType(TextareaType::class)
            ->setHelp('JSON object of scout judgement attributes. Invalid JSON is silently ignored on save.')
            ->setFormTypeOption('attr', ['rows' => 8, 'class' => 'font-monospace'])
            ->hideOnIndex();

        // ── Panel: Personality ────────────────────────────────────────────────
        yield FormField::addFieldset('Personality', 'fa fa-brain')->hideOnIndex();

        yield IntegerField::new('personality.determination', 'Determination')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.professionalism', 'Professionalism')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.ambition', 'Ambition')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.loyalty', 'Loyalty')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.adaptability', 'Adaptability')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.pressure', 'Pressure')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.temperament', 'Temperament')->setHelp('1–20')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('personality.consistency', 'Consistency')->setHelp('1–20')->setColumns(3)->hideOnIndex();

        // ── Panel: Appearance ─────────────────────────────────────────────────
        yield FormField::addFieldset('Appearance', 'fa fa-user-circle')->hideOnIndex();

        yield Field::new('appearance')
            ->setFormType(AppearanceType::class)
            ->onlyOnForms();
    }
}
