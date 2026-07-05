<?php

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

class GameConfigAppLinksTest extends TestCase
{
    public function testFacebookAndXUrlsDefaultToNull(): void
    {
        $config = new GameConfig();
        $this->assertNull($config->getFacebookPageUrl());
        $this->assertNull($config->getXProfileUrl());
    }

    public function testFacebookAndXUrlsCanBeSetAndRead(): void
    {
        $config = new GameConfig();
        $config->setFacebookPageUrl('https://facebook.com/buildmyclub');
        $config->setXProfileUrl('https://x.com/buildmyclub');

        $this->assertSame('https://facebook.com/buildmyclub', $config->getFacebookPageUrl());
        $this->assertSame('https://x.com/buildmyclub', $config->getXProfileUrl());
    }

    public function testEmptyStringIsNormalisedToNull(): void
    {
        // Matches setAndroidDownloadUrl()/setIosDownloadUrl()'s existing
        // behaviour: an empty form field means "hide this link", not a
        // literal empty-string URL.
        $config = new GameConfig();
        $config->setFacebookPageUrl('');
        $config->setXProfileUrl('');

        $this->assertNull($config->getFacebookPageUrl());
        $this->assertNull($config->getXProfileUrl());
    }
}
