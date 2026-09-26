<?php

namespace App\OnlyOffice;

use App\Entity\Document;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Addresses of ONLYOFFICE Docs, and the signed addresses of this API it calls (no user token there): the template and
 * the script of a merge, a version of a document, the editor callback. Signed with APP_SECRET, limited in time.
 */
final class OnlyOfficeUrls
{
    /** Validity of the addresses given to ONLYOFFICE for a merge or a conversion. */
    private const DOWNLOAD_TTL = 600;
    /** Validity of the callback address of an editing session (it may last a working day). */
    private const CALLBACK_TTL = 86400;

    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $router,
        #[Autowire(env: 'ONLYOFFICE_PUBLIC_URL')] private readonly string $publicUrl,
        #[Autowire(env: 'ONLYOFFICE_INTERNAL_URL')] private readonly string $internalUrl,
        #[Autowire(env: 'ONLYOFFICE_CALLBACK_URL')] private readonly string $callbackUrl,
    ) {
    }

    /** ONLYOFFICE as the browser reaches it (editor script). */
    public function publicUrl(): string
    {
        return rtrim($this->publicUrl, '/');
    }

    /** ONLYOFFICE as this API reaches it. */
    public function internalUrl(): string
    {
        return rtrim($this->internalUrl, '/');
    }

    public function editorScript(): string
    {
        return $this->publicUrl().'/web-apps/apps/api/documents/api.js';
    }

    public function template(Document $document): string
    {
        return $this->signed('onlyoffice_template', $document, self::DOWNLOAD_TTL);
    }

    public function mergeScript(Document $document): string
    {
        return $this->signed('onlyoffice_merge_script', $document, self::DOWNLOAD_TTL);
    }

    public function version(Document $document, int $version, int $ttl = self::CALLBACK_TTL): string
    {
        return $this->signed('onlyoffice_version', $document, $ttl, ['version' => $version]);
    }

    public function callback(Document $document): string
    {
        return $this->signed('onlyoffice_callback', $document, self::CALLBACK_TTL);
    }

    /**
     * The results and saved files of ONLYOFFICE are given with the address it was reached by: the public one for the
     * editor. Downloads go through the internal address, and only from ONLYOFFICE.
     *
     * @throws OnlyOfficeException not an address of ONLYOFFICE
     */
    public function internalize(string $url): string
    {
        foreach ([$this->internalUrl(), $this->publicUrl()] as $base) {
            if (str_starts_with($url, $base.'/')) {
                return $this->internalUrl().substr($url, \strlen($base));
            }
        }
        // ONLYOFFICE may name itself by the host it was reached with, without the port of the address.
        $path = parse_url($url, \PHP_URL_PATH);
        $host = parse_url($url, \PHP_URL_HOST);
        if (\is_string($path) && str_starts_with($path, '/cache/files/') && \in_array($host, [parse_url($this->internalUrl(), \PHP_URL_HOST), parse_url($this->publicUrl(), \PHP_URL_HOST)], true)) {
            $query = parse_url($url, \PHP_URL_QUERY);

            return $this->internalUrl().$path.(\is_string($query) ? '?'.$query : '');
        }

        throw new OnlyOfficeException('Refused download: not an address of ONLYOFFICE.');
    }

    /** @param array<string, int|string> $parameters */
    private function signed(string $route, Document $document, int $ttl, array $parameters = []): string
    {
        $path = $this->router->generate($route, ['id' => $document->getId()->toRfc4122()] + $parameters);

        return $this->signer->sign(rtrim($this->callbackUrl, '/').$path, time() + $ttl);
    }
}
