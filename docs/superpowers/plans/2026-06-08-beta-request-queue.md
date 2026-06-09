# Beta Request Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the one-shot beta request email with a two-step verified opt-in flow that stores requests in a database queue visible in the admin panel.

**Architecture:** A new standalone `BetaRequest` entity (no User FK) stores email + 6-digit code + `valid` boolean. `POST /api/beta-request` creates a record and sends the code email; `POST /api/beta-request/verify` validates the code and sets `valid = true`. The admin panel gets a read-only EasyAdmin CRUD with boolean + text filters. The landing page modal becomes a two-step form (email → code input → success).

**Tech Stack:** Symfony 8 / Doctrine ORM 3 / EasyAdmin v5 / Symfony Mailer / Vanilla JS (index.html)

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `src/Entity/BetaRequest.php` | Doctrine entity: email, code, valid, attempts, expiresAt, createdAt, verifiedAt |
| Create | `src/Repository/BetaRequestRepository.php` | `findActiveByEmail()`, `findLatestByEmail()` |
| Modify | `src/Service/EmailVerificationService.php` | Add `sendBetaVerificationEmail(string $email, string $code)` public method |
| Replace | `src/Controller/Api/BetaRequestController.php` | POST /api/beta-request (submit), POST /api/beta-request/verify |
| Create | `src/Controller/Admin/BetaRequestCrudController.php` | Read-only EasyAdmin CRUD with filters |
| Modify | `src/Controller/Admin/DashboardController.php:1397` | Add BetaRequest to "Users & Clubs" menu |
| Modify | `config/packages/security.yaml` | Add PUBLIC_ACCESS for /api/beta-request/verify |
| Create | `migrations/Version20260608000001.php` | CREATE TABLE beta_request |
| Modify | `public/index.html` | Two-step beta modal: email step → code step → success |

---

## Task 1: BetaRequest Entity + Repository

**Files:**
- Create: `src/Entity/BetaRequest.php`
- Create: `src/Repository/BetaRequestRepository.php`

- [ ] **Step 1: Create the entity**

```php
<?php
// src/Entity/BetaRequest.php
namespace App\Entity;

use App\Repository\BetaRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: BetaRequestRepository::class)]
#[ORM\Table(name: 'beta_request')]
#[ORM\Index(columns: ['email'], name: 'idx_beta_request_email')]
class BetaRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 6)]
    private string $code;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $valid = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    public function __construct(string $email, string $code)
    {
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->code      = $code;
        $this->expiresAt = new \DateTimeImmutable('+15 minutes');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getCode(): string { return $this->code; }
    public function isValid(): bool { return $this->valid; }
    public function getAttempts(): int { return $this->attempts; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }

    public function markVerified(): void
    {
        $this->valid      = true;
        $this->verifiedAt = new \DateTimeImmutable();
    }

    public function incrementAttempts(): void { $this->attempts++; }
    public function isExpired(): bool { return $this->expiresAt <= new \DateTimeImmutable(); }
    public function isLockedOut(): bool { return $this->attempts >= 3; }
    public function expire(): void { $this->expiresAt = new \DateTimeImmutable(); }
}
```

- [ ] **Step 2: Create the repository**

```php
<?php
// src/Repository/BetaRequestRepository.php
namespace App\Repository;

use App\Entity\BetaRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BetaRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BetaRequest::class);
    }

    /** Most recent unexpired, unverified record for an email */
    public function findActiveByEmail(string $email): ?BetaRequest
    {
        return $this->createQueryBuilder('b')
            ->where('b.email = :email')
            ->andWhere('b.valid = false')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Most recent record (any state) for an email — used for lockout/expiry messaging */
    public function findLatestByEmail(string $email): ?BetaRequest
    {
        return $this->createQueryBuilder('b')
            ->where('b.email = :email')
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Entity/BetaRequest.php src/Repository/BetaRequestRepository.php
git commit -m "feat: add BetaRequest entity and repository"
```

---

## Task 2: Migration

**Files:**
- Create: `migrations/Version20260608000001.php`

- [ ] **Step 1: Create the migration**

```php
<?php
// migrations/Version20260608000001.php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create beta_request table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('beta_request');
        $table->addColumn('id',          'binary',   ['length' => 16, 'fixed' => true, 'notnull' => true]);
        $table->addColumn('email',       'string',   ['length' => 180, 'notnull' => true]);
        $table->addColumn('code',        'string',   ['length' => 6,   'notnull' => true]);
        $table->addColumn('valid',       'boolean',  ['default' => false, 'notnull' => true]);
        $table->addColumn('attempts',    'integer',  ['default' => 0,     'notnull' => true]);
        $table->addColumn('expires_at',  'datetimetz_immutable', ['notnull' => true]);
        $table->addColumn('created_at',  'datetimetz_immutable', ['notnull' => true]);
        $table->addColumn('verified_at', 'datetimetz_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['email'], 'idx_beta_request_email');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('beta_request');
    }
}
```

- [ ] **Step 2: Run the migration**

```bash
lando php bin/console doctrine:migrations:migrate --no-interaction
```

Expected output ends with: `[notice] finished in X.XXs`

- [ ] **Step 3: Commit**

```bash
git add migrations/Version20260608000001.php
git commit -m "feat: migration — add beta_request table"
```

---

## Task 3: Email sending for beta verification

**Files:**
- Modify: `src/Service/EmailVerificationService.php`

Add one public method `sendBetaVerificationEmail` that reuses the existing private helpers (`renderHtml`, `verificationBlock`, `logoPath`).

- [ ] **Step 1: Add the method** — insert after `sendPasswordResetConfirmationEmail()` and before `verifyCode()`:

```php
public function sendBetaVerificationEmail(string $toEmail, string $code): void
{
    $email = (new Email())
        ->from(new Address($this->mailerFrom, $this->mailerFromName))
        ->to($toEmail)
        ->subject('Your Build My Club beta access code')
        ->html($this->renderHtml(
            'Beta Access Verification',
            $this->verificationBlock($code),
            "Enter this code to confirm your place on the beta list.<br><br>" .
            "<span style='color:#9bb0c4;font-size:8px'>This code expires in <strong style='color:#E8CF59'>15 minutes</strong>. " .
            "If you didn't request beta access, you can safely ignore this email.</span>"
        ))
        ->text(
            "Your beta access verification code is: {$code}\n\n" .
            "Enter this code to confirm your place on the beta list.\n\n" .
            "This code expires in 15 minutes.\n\n" .
            "If you didn't request beta access, you can safely ignore this email."
        )
        ->embedFromPath($this->logoPath(), 'logo', 'image/png');

    $this->mailer->send($email);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Service/EmailVerificationService.php
git commit -m "feat: add sendBetaVerificationEmail to EmailVerificationService"
```

---

## Task 4: Replace BetaRequestController

**Files:**
- Replace: `src/Controller/Api/BetaRequestController.php`

Two endpoints:
- `POST /api/beta-request` — accepts `email`; expires any existing active record for that email; creates new `BetaRequest`; sends code email; returns `{"success": true}`
- `POST /api/beta-request/verify` — accepts `email` + `code`; validates; marks `valid = true` on success; returns `{"success": true}` or appropriate error

- [ ] **Step 1: Write the new controller**

```php
<?php
// src/Controller/Api/BetaRequestController.php
namespace App\Controller\Api;

use App\Entity\BetaRequest;
use App\Repository\BetaRequestRepository;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class BetaRequestController extends AbstractController
{
    public function __construct(
        private readonly BetaRequestRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly EmailVerificationService $emailService,
    ) {}

    #[Route('/beta-request', name: 'api_beta_request', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address.'], Response::HTTP_BAD_REQUEST);
        }

        // If this email already has a verified record, silently succeed — no re-entry needed
        $latest = $this->repo->findLatestByEmail($email);
        if ($latest !== null && $latest->isValid()) {
            return $this->json(['success' => true]);
        }

        // Expire any currently active (unverified) record so the new code is the only valid one
        $active = $this->repo->findActiveByEmail($email);
        if ($active !== null) {
            $active->expire();
        }

        $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $request_ = new BetaRequest($email, $code);
        $this->em->persist($request_);
        $this->em->flush();

        $this->emailService->sendBetaVerificationEmail($email, $code);

        return $this->json(['success' => true]);
    }

    #[Route('/beta-request/verify', name: 'api_beta_request_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));
        $code  = trim((string) $request->request->get('code', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
            return $this->json(['error' => 'Email and code are required.'], Response::HTTP_BAD_REQUEST);
        }

        $record = $this->repo->findActiveByEmail($email);

        if ($record === null) {
            $latest = $this->repo->findLatestByEmail($email);
            if ($latest !== null && $latest->isLockedOut()) {
                return $this->json(['error' => 'Too many attempts. Please request a new code.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($latest !== null && $latest->isExpired()) {
                return $this->json(['error' => 'Code has expired. Please request a new code.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            return $this->json(['error' => 'No active request found for this email.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($record->isLockedOut()) {
            return $this->json(['error' => 'Too many attempts. Please request a new code.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($record->getCode() !== $code) {
            $record->incrementAttempts();
            $this->em->flush();
            $remaining = 3 - $record->getAttempts();
            return $this->json([
                'error' => $remaining > 0
                    ? "Incorrect code. {$remaining} attempt(s) remaining."
                    : 'Too many attempts. Please request a new code.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $record->markVerified();
        $this->em->flush();

        return $this->json(['success' => true]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/Api/BetaRequestController.php
git commit -m "feat: beta request two-step verify flow — submit + verify endpoints"
```

---

## Task 5: Security — expose /api/beta-request/verify publicly

**Files:**
- Modify: `config/packages/security.yaml`

`/api/beta-request` was already added to PUBLIC_ACCESS in a prior fix. Add the verify sub-path as well. Both must be listed before the catch-all `^/api → IS_AUTHENTICATED_FULLY`.

- [ ] **Step 1: Edit security.yaml** — the existing `beta-request` line should read:

```yaml
        - { path: ^/api/beta-request,          roles: PUBLIC_ACCESS }
```

The `^/api/beta-request` pattern already covers `/api/beta-request/verify` (prefix match), so no additional line is needed if the existing rule is already `^/api/beta-request`. Verify this is the case:

```bash
grep "beta-request" config/packages/security.yaml
```

Expected: `- { path: ^/api/beta-request, roles: PUBLIC_ACCESS }`

If the path is `^/api/beta-request$` (exact), change it to `^/api/beta-request`:

```yaml
        - { path: ^/api/beta-request,          roles: PUBLIC_ACCESS }
```

- [ ] **Step 2: Clear cache**

```bash
lando php bin/console cache:clear
```

- [ ] **Step 3: Commit** (only if security.yaml changed)

```bash
git add config/packages/security.yaml
git commit -m "fix: expose /api/beta-request/verify as PUBLIC_ACCESS"
```

---

## Task 6: Admin CRUD — BetaRequestCrudController

**Files:**
- Create: `src/Controller/Admin/BetaRequestCrudController.php`
- Modify: `src/Controller/Admin/DashboardController.php`

- [ ] **Step 1: Create the CRUD controller**

```php
<?php
// src/Controller/Admin/BetaRequestCrudController.php
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
```

- [ ] **Step 2: Add to the admin menu** in `DashboardController::configureMenuItems()` — insert after the Admins line (around line 1397):

Find this block:
```php
        yield MenuItem::section('Users & Clubs');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(ClubCrudController::class, 'Clubs', 'fa fa-school');
        yield MenuItem::linkTo(AdminCrudController::class, 'Admins', 'fa fa-user-shield');
```

Replace with:
```php
        yield MenuItem::section('Users & Clubs');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(ClubCrudController::class, 'Clubs', 'fa fa-school');
        yield MenuItem::linkTo(AdminCrudController::class, 'Admins', 'fa fa-user-shield');
        yield MenuItem::linkTo(BetaRequestCrudController::class, 'Beta Requests', 'fa fa-envelope-open-text');
```

- [ ] **Step 3: Add the use statement** at the top of DashboardController.php with the other Admin use statements:

```php
use App\Controller\Admin\BetaRequestCrudController;
```

- [ ] **Step 4: Clear cache and verify**

```bash
lando php bin/console cache:clear
lando php bin/console debug:router | grep beta
```

Expected: `api_beta_request` and `api_beta_request_verify` appear.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/BetaRequestCrudController.php src/Controller/Admin/DashboardController.php
git commit -m "feat: Beta Requests admin CRUD under Users & Clubs"
```

---

## Task 7: Landing page — two-step beta modal

**Files:**
- Modify: `public/index.html`

Replace the single-step email form with a two-step flow:
1. **Step 1 (email)** — user enters email, submits → `POST /api/beta-request` → show step 2
2. **Step 2 (code)** — user enters 6-digit code, submits → `POST /api/beta-request/verify` → show success message

The reCAPTCHA integration is removed (code verification replaces it as the anti-abuse measure).

- [ ] **Step 1: Replace the beta modal HTML** — find the existing modal (around line 2073) and replace it:

Old:
```html
<div class="beta-overlay" id="beta-overlay" role="dialog" aria-modal="true" aria-labelledby="beta-title">
    <div class="beta-modal">
        <div class="beta-header">
            <h2 id="beta-title">Request Beta Access</h2>
            <button class="beta-close" id="beta-close" aria-label="Close">✕ CLOSE</button>
        </div>
        <div class="beta-body">
            <p>Enter your email address and we'll be in touch when a beta slot opens up.</p>
            <form id="beta-form" novalidate>
                <input type="email" id="beta-email" name="email" class="beta-input"
                       placeholder="your@email.com" autocomplete="email" required>
                <div class="beta-captcha" id="beta-captcha-wrap"></div>
                <button type="submit" class="beta-submit" id="beta-submit">Submit Request</button>
                <div class="beta-msg" id="beta-msg"></div>
            </form>
        </div>
    </div>
</div>
```

New:
```html
<div class="beta-overlay" id="beta-overlay" role="dialog" aria-modal="true" aria-labelledby="beta-title">
    <div class="beta-modal">
        <div class="beta-header">
            <h2 id="beta-title">Request Beta Access</h2>
            <button class="beta-close" id="beta-close" aria-label="Close">✕ CLOSE</button>
        </div>
        <div class="beta-body">

            <!-- Step 1: Email -->
            <div id="beta-step-email">
                <p>Enter your email address to receive a verification code.</p>
                <form id="beta-form-email" novalidate>
                    <input type="email" id="beta-email" name="email" class="beta-input"
                           placeholder="your@email.com" autocomplete="email" required>
                    <button type="submit" class="beta-submit" id="beta-submit-email">Send Code</button>
                    <div class="beta-msg" id="beta-msg-email"></div>
                </form>
            </div>

            <!-- Step 2: Code verification (hidden initially) -->
            <div id="beta-step-code" style="display:none">
                <p id="beta-code-hint">Enter the 6-digit code sent to your email.</p>
                <form id="beta-form-code" novalidate>
                    <input type="text" id="beta-code" name="code" class="beta-input"
                           placeholder="000000" maxlength="6" autocomplete="one-time-code"
                           inputmode="numeric" pattern="[0-9]{6}" required>
                    <button type="submit" class="beta-submit" id="beta-submit-code">Verify Code</button>
                    <div class="beta-msg" id="beta-msg-code"></div>
                    <button type="button" class="beta-resend" id="beta-resend">Resend code</button>
                </form>
            </div>

            <!-- Step 3: Success (hidden initially) -->
            <div id="beta-step-success" style="display:none">
                <p class="beta-success-msg">You're on the list! We'll be in touch when a slot opens.</p>
            </div>

        </div>
    </div>
</div>
```

- [ ] **Step 2: Add resend button style** — find the `.beta-submit` CSS block and add after it:

```css
        .beta-resend {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-family: 'Press Start 2P', monospace;
            font-size: 7px;
            letter-spacing: 1px;
            padding: 8px 0 0;
            text-decoration: underline;
            display: block;
        }
        .beta-resend:hover { color: var(--gold); }
        .beta-success-msg { color: var(--mint); font-size: 9px; line-height: 2; text-align: center; padding: 16px 0; }
```

- [ ] **Step 3: Replace the beta modal JS** — find the `// ── Beta request modal` block (around line 2265) and replace everything from `function openBetaModal()` to the closing `}());` of that IIFE:

```javascript
function openBetaModal() {
    var overlay = document.getElementById('beta-overlay');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('beta-email').focus();
}

(function () {
    var overlay       = document.getElementById('beta-overlay');
    var closeBtn      = document.getElementById('beta-close');
    var stepEmail     = document.getElementById('beta-step-email');
    var stepCode      = document.getElementById('beta-step-code');
    var stepSuccess   = document.getElementById('beta-step-success');
    var formEmail     = document.getElementById('beta-form-email');
    var formCode      = document.getElementById('beta-form-code');
    var emailEl       = document.getElementById('beta-email');
    var codeEl        = document.getElementById('beta-code');
    var submitEmail   = document.getElementById('beta-submit-email');
    var submitCode    = document.getElementById('beta-submit-code');
    var msgEmail      = document.getElementById('beta-msg-email');
    var msgCode       = document.getElementById('beta-msg-code');
    var codeHint      = document.getElementById('beta-code-hint');
    var resendBtn     = document.getElementById('beta-resend');
    var pendingEmail  = '';

    function resetModal() {
        stepEmail.style.display   = '';
        stepCode.style.display    = 'none';
        stepSuccess.style.display = 'none';
        formEmail.reset();
        formCode.reset();
        msgEmail.className = 'beta-msg';
        msgEmail.textContent = '';
        msgCode.className = 'beta-msg';
        msgCode.textContent = '';
        submitEmail.disabled = false;
        submitEmail.textContent = 'Send Code';
        submitCode.disabled = false;
        submitCode.textContent = 'Verify Code';
        pendingEmail = '';
    }

    function closeBeta() {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        resetModal();
    }

    function showMsg(el, text, type) {
        el.textContent = text;
        el.className = 'beta-msg ' + type;
    }

    closeBtn.addEventListener('click', closeBeta);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeBeta(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeBeta();
    });

    function sendCode(email, onSuccess, onError, onNetwork) {
        var body = new FormData();
        body.append('email', email);
        fetch('/api/beta-request', { method: 'POST', body: body })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) { res.ok ? onSuccess(res.data) : onError(res.data); })
            .catch(onNetwork);
    }

    // ── Step 1: Submit email ──────────────────────────────────────────────────
    formEmail.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = emailEl.value.trim();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showMsg(msgEmail, 'Please enter a valid email address.', 'error');
            return;
        }

        submitEmail.disabled = true;
        submitEmail.textContent = 'Sending…';
        msgEmail.className = 'beta-msg';

        sendCode(
            email,
            function () {
                pendingEmail = email;
                codeHint.textContent = 'Enter the 6-digit code sent to ' + email + '.';
                stepEmail.style.display = 'none';
                stepCode.style.display  = '';
                codeEl.focus();
            },
            function (data) {
                showMsg(msgEmail, data.error || 'Something went wrong.', 'error');
                submitEmail.disabled = false;
                submitEmail.textContent = 'Send Code';
            },
            function () {
                showMsg(msgEmail, 'Network error. Please try again.', 'error');
                submitEmail.disabled = false;
                submitEmail.textContent = 'Send Code';
            }
        );
    });

    // ── Step 2: Verify code ───────────────────────────────────────────────────
    formCode.addEventListener('submit', function (e) {
        e.preventDefault();
        var code = codeEl.value.trim();
        if (!code || !/^\d{6}$/.test(code)) {
            showMsg(msgCode, 'Please enter the 6-digit code.', 'error');
            return;
        }

        submitCode.disabled = true;
        submitCode.textContent = 'Verifying…';
        msgCode.className = 'beta-msg';

        var body = new FormData();
        body.append('email', pendingEmail);
        body.append('code', code);

        fetch('/api/beta-request/verify', { method: 'POST', body: body })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) {
                if (res.ok && res.data.success) {
                    stepCode.style.display    = 'none';
                    stepSuccess.style.display = '';
                } else {
                    showMsg(msgCode, res.data.error || 'Something went wrong.', 'error');
                    submitCode.disabled = false;
                    submitCode.textContent = 'Verify Code';
                }
            })
            .catch(function () {
                showMsg(msgCode, 'Network error. Please try again.', 'error');
                submitCode.disabled = false;
                submitCode.textContent = 'Verify Code';
            });
    });

    // ── Resend ────────────────────────────────────────────────────────────────
    resendBtn.addEventListener('click', function () {
        if (!pendingEmail) return;
        resendBtn.disabled = true;
        resendBtn.textContent = 'Sending…';
        msgCode.className = 'beta-msg';
        formCode.reset();

        sendCode(
            pendingEmail,
            function () {
                showMsg(msgCode, 'New code sent!', 'success');
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
                codeEl.focus();
            },
            function (data) {
                showMsg(msgCode, data.error || 'Could not resend. Please try again.', 'error');
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
            },
            function () {
                showMsg(msgCode, 'Network error. Please try again.', 'error');
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
            }
        );
    });
}());
```

- [ ] **Step 4: Remove the reCAPTCHA injection block** — find and delete the section that dynamically loads the reCAPTCHA script (inside the game-config fetch callback). Remove lines that reference `recaptchaSiteKey`, `grecaptcha`, `g-recaptcha`, and `beta-captcha-wrap`. The game-config fetch block will still work — just remove the captcha portion:

Find the following block (inside the `(function() { fetch('/api/game-config')...` IIFE) and delete it:
```javascript
            // Inject reCAPTCHA if a site key is configured
            if (data.recaptchaSiteKey) {
                window.__recaptchaSiteKey = data.recaptchaSiteKey;
                var s = document.createElement('script');
                s.src = 'https://www.google.com/recaptcha/api.js';
                s.async = true;
                s.defer = true;
                document.head.appendChild(s);

                var wrap = document.getElementById('beta-captcha-wrap');
                var div = document.createElement('div');
                div.className = 'g-recaptcha';
                div.setAttribute('data-sitekey', data.recaptchaSiteKey);
                wrap.appendChild(div);
            }
```

- [ ] **Step 5: Clear cache**

```bash
lando php bin/console cache:clear
```

- [ ] **Step 6: Commit**

```bash
git add public/index.html
git commit -m "feat: two-step beta request modal — email + code verification"
```

---

## Self-Review

### Spec Coverage

| Requirement | Task |
|-------------|------|
| Beta request queue in admin | Task 6 |
| Queue under "Users & Clubs" | Task 6 step 2 |
| `valid` property, set true on verified code | Task 1, Task 4 |
| Filter by `valid` status | Task 6 (`BooleanFilter`) |
| Email sent to address given on submit | Task 3, Task 4 |
| Code form shown after email submission | Task 7 |
| Code validated in backend | Task 4 `verify` endpoint |
| Mirrors registration flow | Tasks 1–4 (same patterns as EmailVerification entity) |

### Type Consistency Check

- `BetaRequest::markVerified()` sets `valid = true` — called in `BetaRequestController::verify()` ✓
- `BetaRequest::expire()` sets `expiresAt = now` — called in `BetaRequestController::submit()` to invalidate old codes ✓
- `BetaRequestRepository::findActiveByEmail()` used in both controller endpoints ✓
- `EmailVerificationService::sendBetaVerificationEmail(string $email, string $code)` — signature matches call in controller ✓
- `BetaRequestCrudController` references `BetaRequest::isValid()`, `getAttempts()`, `getCreatedAt()`, `getVerifiedAt()`, `getExpiresAt()` — all defined in entity ✓

### No Placeholders

Checked — all steps contain complete code. No TBDs.
