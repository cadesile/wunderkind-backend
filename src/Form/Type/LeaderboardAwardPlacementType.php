<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Competition\RewardTemplate;
use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One prize place, rendered inline on the definition form by EasyAdmin's CollectionField.
 *
 * A plain FormType over a real child entity is EasyAdmin's native idiom here. The
 * alternative — a json place→reward map on the definition with
 * Field::new('json')->setFormType() — hits the documented injected-CollectionType-options
 * trap (CLAUDE.md Key Gotchas), needs a form theme with a {% block ..._widget %}, loses the
 * RewardTemplate foreign key, and would need a bespoke shape validator. So this type
 * deliberately stays boring.
 */
class LeaderboardAwardPlacementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('place', IntegerType::class, [
                'label' => 'Place',
                'help'  => '1 = winner. Top N is simply the highest place configured here — there is no separate depth setting.',
                'attr'  => ['min' => 1, 'max' => 100],
            ])
            ->add('prizePence', IntegerType::class, [
                'label'    => 'Prize (pence)',
                'required' => false,
                'help'     => 'Paid as a ledger_delta effect. 0 for no cash prize.',
            ])
            ->add('reputationDelta', IntegerType::class, [
                'label'    => 'Reputation',
                'required' => false,
                'help'     => 'Paid as a reputation_delta effect. 0 for none.',
            ])
            ->add('rewardTemplate', EntityType::class, [
                'label'         => 'Reward template',
                'class'         => RewardTemplate::class,
                'required'      => false,
                'placeholder'   => '— none —',
                'choice_label'  => 'name',
                // Only active templates are offered, matching the award path: an inactive
                // template contributes no effects, so offering one would be a trap.
                'query_builder' => static fn (EntityRepository $repo) => $repo->createQueryBuilder('t')
                    ->where('t.isActive = true')
                    ->orderBy('t.name', 'ASC'),
                'help'          => 'Anything richer than cash + reputation. Only active templates contribute effects.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LeaderboardAwardPlacement::class]);
    }
}
