<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Excursion;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Component\Validator\Constraints\Image;

class ExcursionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Excursion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Excursion')
            ->setEntityLabelInPlural('Excursions')
            ->setDefaultSort(['costPerPersonPence' => 'ASC'])
            ->setHelp('index', 'Team trips the manager books to lift morale. Cost is PER ATTENDEE and is multiplied by headcount in-app, so keep per-head figures small — a squad of 20 turns £20/head into £400.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('slug')
            ->setHelp('Stable identifier used by the app. Never change it once shipped — bookings reference it.');
        yield TextField::new('title')
            ->setHelp('Display name on the booking card, 2–5 words.');
        yield TextareaField::new('body')
            ->setHelp('Flavour text on the card. Sell the idea and hint at what could go wrong.')
            ->hideOnIndex();

        yield ImageField::new('imagePath', 'Image')
            ->setBasePath('/uploads/excursions')
            ->setUploadDir('public/uploads/excursions')
            ->setUploadedFileNamePattern('[slug]-[contenthash].[extension]')
            ->setFileConstraints(new Image(
                maxSize: '2M',
                mimeTypes: ['image/png', 'image/jpeg', 'image/webp'],
                mimeTypesMessage: 'Please upload a valid PNG, JPG, or WebP image (max 2MB).',
            ))
            ->setHelp('Shown on the booking card. PNG/JPG/WebP, max 2MB. Leave empty for the default placeholder.')
            ->hideOnIndex();

        yield IntegerField::new('costPerPersonPence', 'Cost per person (pence)')
            ->setHelp('PENCE per attendee. 100 = £1. Guide: 300–2,000 cheap, 3,000–10,000 mid, 25,000+ blowout.');

        yield IntegerField::new('effectValue', 'Effect (1–100)')
            ->setHelp('Size of the morale payoff when the trip goes well. Scaled against a 20-point ceiling in-app, so 50 ≈ +10 morale.');

        yield IntegerField::new('negativeFrequency', 'Risk (1–10)')
            ->setHelp('Chance of staff/player friction, applied as risk/10 per attendee. Friction eats into the payoff rather than adding a separate penalty. Keep the safest options below the strongest ones, or there is no reason to gamble.');

        yield ChoiceField::new('targetAudience', 'Attendees')
            ->setChoices(Excursion::AUDIENCES)
            ->setHelp('Drives both headcount cost and who is affected. Friction needs both sides present, so players-only and staff-only trips cannot generate it.');

        yield BooleanField::new('postSeasonOnly', 'Close season only')
            ->setHelp('Bookable at any time, but only pays out during end-of-season processing. Use for tours and long camps.');

        yield IntegerField::new('cooldownWeeks', 'Cooldown (weeks)')
            ->setHelp('Minimum weeks before this can be booked again. Scale it with cost and impact — 2–4 for cheap trips, 40+ for a season tour.');

        yield BooleanField::new('active')
            ->setHelp('Inactive excursions are filtered out of the API response and disappear from the app.');

        yield DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('updatedAt')->hideOnForm()->hideOnIndex();
    }
}
