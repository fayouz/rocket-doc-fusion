<?php

namespace App\OnlyOffice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * License file of ONLYOFFICE Docs (Enterprise or Developer edition), in the directory shared with it
 * (/var/www/onlyoffice/Data/license.lic on its side). Without it, ONLYOFFICE runs as the Community edition.
 */
class OnlyOfficeLicense
{
    public function __construct(
        #[Autowire(env: 'resolve:ONLYOFFICE_LICENSE_PATH')] private readonly string $path,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function updatedAt(): ?\DateTimeImmutable
    {
        return $this->exists() ? (new \DateTimeImmutable())->setTimestamp((int) filemtime($this->path)) : null;
    }

    /** @throws \InvalidArgumentException not a license file */
    public function install(string $content): void
    {
        $license = json_decode($content, true);
        if (!\is_array($license) || !isset($license['signature'])) {
            throw new \InvalidArgumentException('This is not an ONLYOFFICE license file (license.lic).');
        }
        (new Filesystem())->dumpFile($this->path, $content);
        @chmod($this->path, 0o644);
    }

    public function remove(): void
    {
        (new Filesystem())->remove($this->path);
    }
}
