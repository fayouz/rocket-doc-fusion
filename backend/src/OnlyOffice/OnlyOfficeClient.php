<?php

namespace App\OnlyOffice;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API of ONLYOFFICE Docs used by the merge and the documents: Document Builder (/docbuilder), conversion
 * (/converter), commands (/command: version, license, force save), health check. Requests are signed (token in body).
 */
class OnlyOfficeClient
{
    private const BUILDER_ERRORS = [
        -1 => 'unknown error',
        -2 => 'timeout',
        -3 => 'error in the merge script',
        -4 => 'the template could not be downloaded',
        -6 => 'access token missing or invalid',
        -8 => 'invalid token',
    ];
    private const CONVERT_ERRORS = [
        -1 => 'unknown error',
        -2 => 'timeout',
        -3 => 'conversion error',
        -4 => 'the document could not be downloaded',
        -5 => 'the document is protected by a password',
        -6 => 'error of the ONLYOFFICE database',
        -7 => 'invalid input',
        -8 => 'invalid token',
        -9 => 'unknown format of the document',
        -10 => 'size limit exceeded',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OnlyOfficeJwt $jwt,
        private readonly OnlyOfficeUrls $urls,
    ) {
    }

    /**
     * Runs a Document Builder script, asynchronously: the first call (with "url") starts it, the next ones (key only)
     * tell whether it ended.
     *
     * @param array<string, mixed> $argument
     *
     * @return array{end: bool, urls: array<string, string>} result files (internal addresses) once ended
     */
    public function build(string $key, ?string $scriptUrl = null, array $argument = []): array
    {
        $body = ['async' => true, 'key' => $key];
        if (null !== $scriptUrl) {
            $body += ['url' => $scriptUrl, 'argument' => (object) $argument];
        }
        $result = $this->post('/docbuilder', $body);
        if (isset($result['error'])) {
            throw new OnlyOfficeException('ONLYOFFICE merge failed: '.(self::BUILDER_ERRORS[(int) $result['error']] ?? 'error '.$result['error']).'.');
        }
        $urls = [];
        foreach ((array) ($result['urls'] ?? []) as $name => $url) {
            $urls[(string) $name] = $this->urls->internalize((string) $url);
        }

        return ['end' => (bool) ($result['end'] ?? false), 'urls' => $urls];
    }

    /** Converts a document to another format; returns the converted file. */
    public function convert(string $url, string $key, string $fileType, string $outputType, string $title): string
    {
        $result = $this->post('/converter', [
            'async' => false,
            'filetype' => $fileType,
            'outputtype' => $outputType,
            'key' => $key,
            'title' => $title,
            'url' => $url,
        ], 120);
        if (isset($result['error'])) {
            throw new OnlyOfficeException('ONLYOFFICE conversion failed: '.(self::CONVERT_ERRORS[(int) $result['error']] ?? 'error '.$result['error']).'.');
        }
        if (true !== ($result['endConvert'] ?? false) || !isset($result['fileUrl'])) {
            throw new OnlyOfficeException('ONLYOFFICE conversion did not end.');
        }

        return $this->download((string) $result['fileUrl']);
    }

    /**
     * A command of the command service: "version", "license", "forcesave" (with "key")…
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function command(string $command, array $parameters = []): array
    {
        $result = $this->post('/command', ['c' => $command] + $parameters);
        if (0 !== (int) ($result['error'] ?? 0)) {
            throw new OnlyOfficeException(\sprintf('ONLYOFFICE command "%s" failed: error %s.', $command, $result['error']));
        }

        return $result;
    }

    public function healthy(): bool
    {
        try {
            return 'true' === trim($this->httpClient->request('GET', $this->urls->internalUrl().'/healthcheck', ['timeout' => 5])->getContent());
        } catch (ExceptionInterface) {
            return false;
        }
    }

    /** A result or a saved file of ONLYOFFICE (its address, public or internal). */
    public function download(string $url): string
    {
        try {
            return $this->httpClient->request('GET', $this->urls->internalize($url), ['timeout' => 60])->getContent();
        } catch (ExceptionInterface $e) {
            throw new OnlyOfficeException('ONLYOFFICE file could not be downloaded: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, int $timeout = 30): array
    {
        if ($this->jwt->isEnabled()) {
            $body['token'] = $this->jwt->sign($body);
        }
        try {
            $response = $this->httpClient->request('POST', $this->urls->internalUrl().$path, [
                'json' => $body,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => $timeout,
            ]);

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new OnlyOfficeException('ONLYOFFICE Docs is unreachable: '.$e->getMessage(), previous: $e);
        }
    }
}
