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
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class BetaRequestCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
    ) {}

    public static function getEntityFqcn(): string
    {
        return BetaRequest::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $sendInvite = Action::new('sendBetaInvite', 'Send Invite', 'fa fa-paper-plane')
            ->linkToUrl(fn(BetaRequest $entity) => $this->generateUrl('admin_beta_request_send_invite', [
                'id'     => $entity->getId(),
                '_token' => $this->csrf->getToken('beta_request_invite_' . $entity->getId())->getValue(),
            ]))
            ->setHtmlAttributes(['onclick' => 'return confirm("Send the beta invite email to this address?");'])
            ->setCssClass('btn btn-sm btn-outline-primary');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $sendInvite);
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
        yield DateTimeField::new('invitedAt')->setFormat('yyyy-MM-dd HH:mm');
        yield DateTimeField::new('expiresAt')->setFormat('yyyy-MM-dd HH:mm')->hideOnIndex();
    }
}
