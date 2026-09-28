<?php

namespace App\OnlyOffice;

use Rocket\Core\Health\ServiceProbeInterface;

/** ONLYOFFICE Docs on the dashboard, checked every 5 minutes by the worker: reachable, and its version. */
final class OnlyOfficeProbe implements ServiceProbeInterface
{
    public function __construct(
        private readonly OnlyOfficeClient $onlyOffice,
        private readonly OnlyOfficeUrls $urls,
    ) {
    }

    public function id(): string
    {
        return 'onlyoffice';
    }

    public function label(): string
    {
        return 'ONLYOFFICE Docs';
    }

    public function targets(): iterable
    {
        yield 'documentserver' => [
            'name' => 'Document Server ('.$this->urls->internalUrl().')',
            'check' => function (): string {
                if (!$this->onlyOffice->healthy()) {
                    throw new OnlyOfficeException('ONLYOFFICE Docs does not answer its health check.');
                }

                return 'Version '.($this->onlyOffice->command('version')['version'] ?? '?');
            },
        ];
    }
}
