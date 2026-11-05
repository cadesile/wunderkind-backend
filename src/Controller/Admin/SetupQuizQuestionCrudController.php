<?php

namespace App\Controller\Admin;

use App\Entity\Setup\SetupQuizQuestion;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

/**
 * Onboarding quiz questions.
 *
 * THESE ROWS DECIDE A PAYOUT, not just copy. A correct answer is worth a share of
 * `quizBonusMultiplier` x the account's own starting grant, so the help text here spells out
 * the two edits that quietly change what players are paid.
 */
class SetupQuizQuestionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SetupQuizQuestion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Setup Quiz Question')
            ->setEntityLabelInPlural('Setup Quiz')
            ->setDefaultSort(['sortOrder' => 'ASC'])
            ->setHelp(
                'index',
                'Answering the quiz pays a bonus worth the account\'s own starting grant x the '
                . '"Quiz Bonus Multiplier" on Starter Config, scaled by the share answered '
                . 'correctly. Deactivate a bad question rather than deleting it — answers '
                . 'reference it, and bonuses already paid were scored against it.',
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextareaField::new('prompt')
            ->setHelp('The question, as the player reads it. Plain text, not a translation key.');

        yield ArrayField::new('options')
            ->setHelp(
                'The answers offered, in display order. An answer is submitted as its POSITION '
                . 'in this list, so REORDERING options on a question that already has answers '
                . 'changes what those answers meant — withdraw and replace it instead.',
            );

        yield IntegerField::new('correctIndex', 'Correct answer (index)')
            ->setHelp(
                'Zero-based position in the list above: 0 is the first option. A value past the '
                . 'end makes the question unanswerable, and it is then hidden from players '
                . 'rather than served as impossible.',
            );

        yield IntegerField::new('sortOrder')
            ->setHelp('Ascending. Ties are broken by id, so the order served is always stable.');

        yield BooleanField::new('isActive', 'Active')
            ->setHelp('Inactive questions are not served and are excluded from future scores.');

        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
