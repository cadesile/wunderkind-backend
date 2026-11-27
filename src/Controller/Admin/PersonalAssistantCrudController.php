<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PersonalAssistant;
use App\Enum\PersonalAssistantTone;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * World › Personal assistants: the PA roster owners choose from (Courtney, 2026-11-27).
 *
 * Create, edit and activate/deactivate. DELETE IS HIDDEN: a PA can be the stored choice of any
 * number of owners, and switching it off already removes it from the app (owners fall back to
 * the first active PA). The slug is locked after creation for the same reason.
 */
class PersonalAssistantCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PersonalAssistant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Personal assistant')
            ->setEntityLabelInPlural('Personal assistants')
            ->setDefaultSort(['sortOrder' => 'ASC', 'slug' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'The PAs owners can choose in the app. Switch one off rather than deleting it; owners who chose it fall back to the first active PA.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('slug')
            ->setHelp('Stable key the app stores as the owner\'s choice, e.g. pa-marcus. Lowercase letters, digits and hyphens. Cannot be changed after creation.')
            ->setFormTypeOption('disabled', $pageName === Crud::PAGE_EDIT);
        yield TextField::new('name')
            ->setHelp('Display name shown in the app.');
        yield ChoiceField::new('gender')
            ->setChoices(['Male' => 'male', 'Female' => 'female']);
        yield ChoiceField::new('pronoun')
            ->setChoices(['He' => 'he', 'She' => 'she', 'They' => 'they'])
            ->setHelp('Used in the app\'s template text.')
            ->hideOnIndex();
        yield ChoiceField::new('tone')
            ->setChoices(PersonalAssistantTone::cases())
            ->setFormTypeOption('choice_label', fn (PersonalAssistantTone $t) => ucfirst($t->value))
            ->formatValue(fn ($v) => $v instanceof PersonalAssistantTone ? ucfirst($v->value) : $v)
            ->setHelp('Which family of offline template text the app uses for this PA. A new tone needs app template keys; until then the app uses its neutral wording.');
        yield TextareaField::new('description')
            ->setHelp('Player-facing, shown in the PA picker. 1 to 2 sentences, up to 300 characters.')
            ->hideOnIndex();
        yield TextareaField::new('personalityPrompt', 'Personality prompt')
            ->setNumOfRows(10)
            ->setHelp('Sent to the AI with every advice call for this PA, as the voice brief. Describe who they are and how they talk. The safety rules (use only the game\'s figures, keep it short, no real-world advice) live in code and always apply. Never shown to players. Up to ' . PersonalAssistant::PERSONALITY_PROMPT_MAX . ' characters.')
            ->hideOnIndex();
        yield TextField::new('appearanceSeed', 'Appearance seed')
            ->setHelp('Seed for the app\'s pixel sprite. Changing it changes the face.')
            ->hideOnIndex();
        yield CodeEditorField::new('appearanceOverridesJson', 'Appearance overrides')
            ->setLanguage('js')
            ->setHelp('Optional JSON object laid over the seeded sprite, e.g. {"hair": "bun", "facial": "none", "glasses": true}. Leave blank for none.')
            ->hideOnIndex();
        yield TextField::new('outOfOfficeMessage', 'Out-of-office line')
            ->setRequired(false)
            ->setHelp('Short in-character line shown when the AI wording is unavailable. Up to 200 characters.')
            ->hideOnIndex();
        yield IntegerField::new('sortOrder', 'Sort order')
            ->setHelp('Picker order; the lowest active one is the default for owners who have not chosen.');
        yield BooleanField::new('isActive', 'Active');
        yield DateTimeField::new('updatedAt')->onlyOnDetail();
    }
}
