# Database Schema (Doctrine / PostgreSQL)

**Migrations (latest 10):**

- `Version20260626000001`
  → ALTER TABLE "user" ADD last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
  → ALTER TABLE "user" DROP last_login_at
- `Version20260704000001`
  → ALTER TABLE club ADD tutorial_completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
  → ALTER TABLE club DROP tutorial_completed_at
- `Version20260705141806`
  → CREATE TABLE social_account_connection (id UUID NOT NULL, platform VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL, external_account_id VARCHAR(255) NOT NULL, access_token TEXT NOT NULL, refresh_token TEXT DEFAULT NULL, token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_active BOOLEAN NOT NULL, connected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))
  → CREATE UNIQUE INDEX uq_social_platform_external_id ON social_account_connection (platform, external_account_id)
  → DROP TABLE social_account_connection
- `Version20260705202249`
  → CREATE TABLE social_post_template (id UUID NOT NULL, category VARCHAR(255) NOT NULL, platform VARCHAR(255) NOT NULL, period VARCHAR(255) NOT NULL, body_template TEXT NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))
  → CREATE UNIQUE INDEX uq_social_post_template_category_platform ON social_post_template (category, platform)
  → DROP TABLE social_post_template
- `Version20260705202745`
  → ALTER TABLE game_config ADD last_posted_stat_category VARCHAR(255) DEFAULT NULL
  → ALTER TABLE game_config DROP last_posted_stat_category
- `Version20260705211313`
  → ALTER TABLE game_config ADD facebook_page_url VARCHAR(255) DEFAULT NULL
  → ALTER TABLE game_config ADD x_profile_url VARCHAR(255) DEFAULT NULL
  → ALTER TABLE game_config DROP facebook_page_url
- `Version20260707203954`
  → ALTER TABLE club ALTER tutorial_completed_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE
  → ALTER TABLE game_config ADD npc_squad_config JSON DEFAULT NULL
  → ALTER TABLE game_config ALTER npc_squad_config SET NOT NULL
- `Version20260713194158`
  → ALTER TABLE agent ADD appearance JSON DEFAULT NULL
  → ALTER TABLE player ADD appearance JSON DEFAULT NULL
  → ALTER TABLE scout ADD appearance JSON DEFAULT NULL
- `Version20260716000000`
  → ALTER TABLE starter_config ADD world_pack_players_per_agent INT NOT NULL DEFAULT 12
  → ALTER TABLE starter_config DROP world_pack_players_per_agent
- `Version20260719000000`
  → ALTER TABLE game_config ADD season_ticket_holder_percent SMALLINT DEFAULT 60 NOT NULL
  → ALTER TABLE game_config DROP season_ticket_holder_percent

#### `LeagueSponsor`
```php
#[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'leagueSponsors')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private League $league;
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Sponsor $sponsor;
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $rolledValue = 0;
    public function __construct(League $league, Sponsor $sponsor, int $rolledValue = 0)
        $this->league      = $league;
        $this->sponsor     = $sponsor;
        $this->rolledValue = $rolledValue;
    public function getLeague(): League { return $this->league; }
    public function getSponsor(): Sponsor { return $this->sponsor; }
    public function getRolledValue(): int { return $this->rolledValue; }
    public function setRolledValue(int $v): static { $this->rolledValue = $v; return $this; }
```

#### `TacticalAdvantage`
```php
#[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;
    #[ORM\Column(enumType: PlayingStyle::class)]
    private PlayingStyle $style;
    #[ORM\Column(enumType: PlayingStyle::class)]
    private PlayingStyle $opponentStyle;
    #[ORM\Column(type: 'float')]
    private float $multiplier;
        PlayingStyle $style = PlayingStyle::POSSESSION,
        PlayingStyle $opponentStyle = PlayingStyle::DIRECT,
        float $multiplier = 1.0
        $this->id            = new UuidV7();
        $this->style         = $style;
        $this->opponentStyle = $opponentStyle;
        $this->multiplier    = $multiplier;
    public function getId(): UuidV7 { return $this->id; }
    public function getStyle(): PlayingStyle { return $this->style; }
    public function setStyle(PlayingStyle $style): void { $this->style = $style; }
    public function getOpponentStyle(): PlayingStyle { return $this->opponentStyle; }
    public function setOpponentStyle(PlayingStyle $opponentStyle): void { $this->opponentStyle = $opponentStyle; }
    public function getMultiplier(): float { return $this->multiplier; }
    public function setMultiplier(float $multiplier): void { $this->multiplier = $multiplier; }
```

#### `User`
```php
#[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;
    #[ORM\Column(length: 180, unique: true)]
    private string $email;
    #[ORM\Column]
    private string $password;
    #[ORM\Column(type: 'json')]
    private array $roles = [];
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Club::class, cascade: ['persist', 'remove'])]
    private Collection $clubs;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $managerProfile = null;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    public function __construct(string $email)
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->createdAt = new \DateTimeImmutable();
```
