<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add owner identity (name, nationality, gender, dob, appearance) to "user"; drop the old manager_profile blob from "user" and club';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD nationality VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD gender VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD dob DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD appearance JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" DROP manager_profile');
        $this->addSql('ALTER TABLE club DROP manager_profile');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP name');
        $this->addSql('ALTER TABLE "user" DROP nationality');
        $this->addSql('ALTER TABLE "user" DROP gender');
        $this->addSql('ALTER TABLE "user" DROP dob');
        $this->addSql('ALTER TABLE "user" DROP appearance');
        $this->addSql('ALTER TABLE "user" ADD manager_profile JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD manager_profile JSON DEFAULT NULL');
    }
}
