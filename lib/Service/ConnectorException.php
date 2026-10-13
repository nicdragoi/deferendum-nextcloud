<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\Deferendum\Service;
final class ConnectorException extends \RuntimeException {
    public function __construct(public readonly string $reason = 'unavailable') { parent::__construct($reason); }
}
