<?php

namespace App\Controller\Admin;

use App\Entity\Admin;
use App\Entity\AdminMessage;
use App\Enum\MessageDisplayType;
use App\Enum\MessagePriority;
use App\Enum\MessageTargetType;
use App\Repository\MessageDeliveryRepository;
use App\Service\AdminMessageService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * Authoring UI for server-driven announcements.
 *
 * persistEntity()/updateEntity() are the single chokepoint where admin-authored HTML enters
 * the system — both sanitise before writing, so admin_message.body_html only ever holds
 * allowlisted markup and the API can emit it verbatim.
 */
class AdminMessageCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminMessageService $adminMessageService,
        private readonly MessageDeliveryRepository $deliveryRepository,
    ) {}

    public static function getEntityFqcn(): string
    {
        return AdminMessage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Message')
            ->setEntityLabelInPlural('Messages')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['title', 'bodyHtml']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield TextField::new('title');

        yield CodeEditorField::new('bodyHtml', 'Body')
            ->setLanguage('js')
            ->setNumOfRows(12)
            ->hideOnIndex()
            ->setHelp(self::bodyHelp());

        yield ChoiceField::new('priority')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => MessagePriority::class])
            ->formatValue(static fn ($value, $entity) => $entity?->getPriority()->name)
            ->setHelp('Higher priority is served first.');

        yield ChoiceField::new('displayType', 'Display')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => MessageDisplayType::class])
            ->setHelp(sprintf(
                'The poll endpoint returns at most %d blocking modal plus %d other messages per request.',
                AdminMessageService::MAX_BLOCKING,
                AdminMessageService::MAX_NON_BLOCKING,
            ));

        yield ChoiceField::new('targetType', 'Target')
            ->setFormType(EnumType::class)
            ->setFormTypeOptions(['class' => MessageTargetType::class])
            ->setHelp('Broadcast ignores the fields below. Group segmented uses Audience Groups; Direct club uses Target Club.');

        yield AssociationField::new('audienceGroups', 'Audience Groups')
            ->setFormTypeOption('by_reference', false)
            ->hideOnIndex()
            ->setHelp('Used only when Target is "group segmented". A club receives the message if it matches ANY group.');

        yield AssociationField::new('targetClub', 'Target Club')
            ->hideOnIndex()
            ->setHelp('Used only when Target is "direct club".');

        yield BooleanField::new('isActive', 'Active')
            ->setHelp('Inactive messages are never delivered, regardless of the validity window.');

        yield DateTimeField::new('validFrom')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('validUntil')
            ->setFormat('yyyy-MM-dd HH:mm')
            ->setHelp('Leave empty for no expiry.');

        yield TextField::new('deliveryStats', 'Deliveries')
            ->onlyOnDetail()
            ->formatValue(fn ($value, ?AdminMessage $entity) => $entity === null
                ? '—'
                : $this->formatDeliveryStats($entity));

        yield AssociationField::new('createdBy', 'Author')->hideOnForm();
        yield DateTimeField::new('createdAt')->setFormat('yyyy-MM-dd HH:mm')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AdminMessage) {
            $this->sanitizeBody($entityInstance);

            $author = $this->getUser();

            if ($entityInstance->getCreatedBy() === null && $author instanceof Admin) {
                $entityInstance->setCreatedBy($author);
            }
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof AdminMessage) {
            $this->sanitizeBody($entityInstance);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function sanitizeBody(AdminMessage $message): void
    {
        $message->setBodyHtml($this->adminMessageService->sanitize($message->getBodyHtml()));
    }

    private function formatDeliveryStats(AdminMessage $message): string
    {
        $counts = $this->deliveryRepository->countByMessageGroupedByStatus($message);

        if ($counts === []) {
            return 'Not yet delivered';
        }

        $parts = [];

        foreach ($counts as $status => $total) {
            $parts[] = sprintf('%s: %d', $status, $total);
        }

        return implode(' · ', $parts);
    }

    /** Raw HTML, rendered by EasyAdmin's setHelp(). */
    private static function bodyHelp(): string
    {
        return <<<HTML
            Saved content is <strong>sanitised on write</strong>. Only these tags survive:
            <code>p</code>, <code>strong</code>, <code>em</code>, <code>ul</code>, <code>ol</code>,
            <code>li</code>, <code>br</code>, <code>h3</code>, <code>a href</code> (https links only).
            <br><br>
            Everything else is stripped, including <code>style</code> and <code>class</code>
            attributes — the client applies its own retro theme, and inline CSS here would distort it.
            Scripts and iframes cannot be saved.
        HTML;
    }
}
