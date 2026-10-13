<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\AppInfo;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\Deferendum\Dashboard\DecisionWidget;
final class Application extends App implements IBootstrap {
    public const APP_ID = 'deferendum';
    public function __construct(array $urlParams = []) { parent::__construct(self::APP_ID, $urlParams); }
    public function register(IRegistrationContext $context): void { $context->registerDashboardWidget(DecisionWidget::class); }
    public function boot(IBootContext $context): void {}
}
