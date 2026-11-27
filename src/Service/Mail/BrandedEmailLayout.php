<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * The branded Build My Club email shell (logo header, heading bar, body, footer), shared by
 * the verification mails and the admin Notify mail so they all look the same. The caller is
 * responsible for escaping whatever it passes in: $body and $footer are inserted as HTML.
 */
final class BrandedEmailLayout
{
    public function __construct(
        private readonly string $appUrl,
    ) {}

    /**
     * Rendered at 80x80 in the email body, so this points at the 192px derivative, not
     * the 1.5MB archival master — a transactional email should not carry a megabyte of
     * logo per send, and plenty of clients fetch it on every open.
     */
    private function logoUrl(): string
    {
        return $this->appUrl . '/logo-192.png';
    }

    public function render(string $heading, string $body, string $footer): string
    {
        $logoUrl = $this->logoUrl();
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Build My Club</title>
</head>
<body style="margin:0;padding:0;background-color:#0f1420;font-family:'Courier New',Courier,monospace">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#0f1420;padding:40px 20px">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:500px;background-color:#1e2448;border:1px solid #2a3060">

          <!-- Header -->
          <tr>
            <td style="padding:32px 24px;text-align:center;border-bottom:2px solid #E8CF59">
              <img src="{$logoUrl}" alt="Build My Club" width="80" height="80" style="display:block;margin:0 auto 12px">
              <p style="color:#E8CF59;font-size:11px;letter-spacing:3px;margin:0 0 4px;text-transform:uppercase;font-weight:bold">BUILD MY CLUB</p>
              <p style="color:#9bb0c4;font-size:7px;letter-spacing:2px;margin:0;text-transform:uppercase">Football Management Simulation</p>
            </td>
          </tr>

          <!-- Heading -->
          <tr>
            <td style="padding:24px 24px 0;text-align:center">
              <p style="color:#e8f0f4;font-size:10px;letter-spacing:2px;margin:0;text-transform:uppercase;border-left:3px solid #E8CF59;padding-left:10px;text-align:left">{$heading}</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:16px 24px">
              {$body}
            </td>
          </tr>

          <!-- Footer text -->
          <tr>
            <td style="padding:0 24px 24px">
              <p style="color:#e8f0f4;font-size:9px;line-height:2;margin:0">{$footer}</p>
            </td>
          </tr>

          <!-- Bottom bar -->
          <tr>
            <td style="padding:16px 24px;border-top:1px solid #2a3060;text-align:center">
              <p style="color:#9bb0c4;font-size:7px;letter-spacing:1px;margin:0;text-transform:uppercase">&copy; 2026 Build My Club. All rights reserved.</p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
