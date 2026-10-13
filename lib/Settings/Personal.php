<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Settings;
use OCP\Settings\ISettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
final class Personal implements ISettings {
    public function __construct(private IURLGenerator $urls) {}
    public function getForm(): TemplateResponse { return new TemplateResponse('deferendum', 'settings', ['url' => $this->urls->linkToRouteAbsolute('deferendum.page.index'), 'admin' => false]); }
    public function getSection(): string { return 'connected-accounts'; }
    public function getPriority(): int { return 80; }
}
