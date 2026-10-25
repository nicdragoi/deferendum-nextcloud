<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Migration;
use OCP\IConfig;
use OCP\Migration\IRepairStep;
use OCP\Migration\IOutput;
final class RemoveCredentials implements IRepairStep {
    public function __construct(private IConfig $config) {}
    public function getName(): string { return 'Preserve local Deferendum connector state'; }
    public function run(IOutput $output): void {
        // Nextcloud also invokes uninstall repair steps on ordinary disable.
        // Retain this constructor/class for old cached hook references, but
        // never erase encrypted withdrawal handles or uncertain operations.
        // Remote withdrawal and pending reconciliation require explicit action.
        $output->info('Encrypted Deferendum connector state retained. Withdraw access explicitly before permanent removal.');
    }
}
