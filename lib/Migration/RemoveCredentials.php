<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Migration;
use OCP\IConfig;
use OCP\Migration\IRepairStep;
use OCP\Migration\IOutput;
final class RemoveCredentials implements IRepairStep {
    public function __construct(private IConfig $config) {}
    public function getName(): string { return 'Remove local Deferendum connector credentials'; }
    public function run(IOutput $output): void {
        // Local cleanup only. Administrators should disconnect first so remote
        // grants are withdrawn; orphaned remote grants still expire within 30 days.
        $this->config->deleteAppFromAllUsers('deferendum');
        $this->config->deleteAppValues('deferendum');
        $output->info('Local Deferendum connector credentials removed.');
    }
}
