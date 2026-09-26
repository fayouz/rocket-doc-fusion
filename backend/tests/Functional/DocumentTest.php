<?php

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Fusion\SampleTemplate;
use App\OnlyOffice\OnlyOfficeJwt;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Rocket\Core\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Merge, editor and conversion with a fake ONLYOFFICE Docs (HttpMock): ONLYOFFICE_INTERNAL_URL http://onlyoffice.test,
 * ONLYOFFICE_PUBLIC_URL https://office.example.org, ONLYOFFICE_CALLBACK_URL http://api.test (.env.test).
 */
final class DocumentTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const OO = 'http://onlyoffice.test';
    private const MERGED = "PK\x03\x04merged docx";

    /** @var list<array<string, mixed>> bodies of the requests to ONLYOFFICE (JWT checked) */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        $this->calls = [];
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/var/test-data/onlyoffice');
        $this->fakeOnlyOffice();
    }

    public function testInspectATemplate(): void
    {
        $alice = $this->createUser('alice@example.org');
        $result = $this->upload('/api/templates/inspect', 'Bearer '.$this->jwtFor($alice), ['file' => $this->sample()]);

        $this->assertStatus(200);
        self::assertContains('client_nom', $result['variables']);
        self::assertSame('lignes', $result['sections'][0]['name']);
    }

    public function testMergeThenDownloadAndConvert(): void
    {
        $alice = $this->createUser('alice@example.org');
        $document = $this->merge($alice);

        self::assertSame('ready', $document['status']);
        self::assertSame(1, $document['version']);
        self::assertSame('Devis Martin', $document['title']);
        self::assertSame(SampleTemplate::NAME, $document['templateName']);

        // The merge: an asynchronous Document Builder task, with the signed address of the generated script.
        $build = $this->calls[0];
        self::assertSame('/docbuilder', $build['path']);
        self::assertTrue($build['async']);
        self::assertStringStartsWith('http://api.test/api/onlyoffice/documents/'.$document['id'].'/merge.js?', $build['url']);
        self::assertSame('Société Martin', $build['argument']['values']['client_nom']);
        self::assertSame('Audit', $build['argument']['sections']['lignes'][0]['designation']);

        // The script, as ONLYOFFICE downloads it: the template address is written in it. Unsigned: refused.
        $this->client->request('GET', $build['url']);
        $this->assertStatus(200);
        self::assertMatchesRegularExpression('#^builder\.OpenFile\("http://api\.test/api/onlyoffice/documents/'.$document['id'].'/template\?[^"]+"\);#', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', 'http://api.test/api/onlyoffice/documents/'.$document['id'].'/merge.js');
        $this->assertStatus(403);

        $auth = 'Bearer '.$this->jwtFor($alice);
        $this->client->request('GET', '/api/documents/'.$document['id'].'/content', server: ['HTTP_AUTHORIZATION' => $auth]);
        $this->assertStatus(200);
        self::assertSame(self::MERGED, $this->client->getInternalResponse()->getContent());

        $this->client->request('GET', '/api/documents/'.$document['id'].'/content?format=pdf', server: ['HTTP_AUTHORIZATION' => $auth]);
        $this->assertStatus(200);
        self::assertStringStartsWith('%PDF', $this->client->getInternalResponse()->getContent());
        self::assertSame('/converter', end($this->calls)['path']);

        // Private: another user does not see it.
        $bob = $this->createUser('bob@example.org');
        $this->api('GET', '/api/documents/'.$document['id'], authorization: 'Bearer '.$this->jwtFor($bob));
        $this->assertStatus(403);
        $this->client->request('GET', '/api/documents/'.$document['id'].'/content', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jwtFor($bob)]);
        $this->assertStatus(404);
        self::assertSame([], $this->api('GET', '/api/documents', authorization: 'Bearer '.$this->jwtFor($bob)));
    }

    public function testFailedMerge(): void
    {
        HttpMock::on(self::OO.'/docbuilder', fn () => new MockResponse('{"key":"k","error":-3}'));
        $document = $this->merge($this->createUser('alice@example.org'));

        self::assertSame('failed', $document['status']);
        self::assertStringContainsString('error in the merge script', $document['error']);
    }

    public function testInvalidTemplateIsRefused(): void
    {
        $alice = $this->createUser('alice@example.org');
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, 'not a docx');
        $this->upload('/api/documents', 'Bearer '.$this->jwtFor($alice), ['template' => new UploadedFile($path, 'devis.docx', test: true)]);

        $this->assertStatus(422);
    }

    public function testEditorThenSaveThroughTheCallback(): void
    {
        $alice = $this->createUser('alice@example.org');
        $alice->setFirstName('Alice');
        $this->em()->flush();
        $document = $this->merge($alice);
        $editor = $this->api('GET', '/api/documents/'.$document['id'].'/editor', authorization: 'Bearer '.$this->jwtFor($alice));

        $this->assertStatus(200);
        self::assertSame('https://office.example.org/web-apps/apps/api/documents/api.js', $editor['scriptUrl']);
        $config = $editor['config'];
        self::assertSame('edit', $config['editorConfig']['mode']);
        self::assertSame('Alice', $config['editorConfig']['user']['name']);
        // Signed with the secret shared with ONLYOFFICE.
        $signed = $config;
        unset($signed['token']);
        self::assertEquals($signed, $this->jwt()->verify($config['token']));
        // The version ONLYOFFICE downloads.
        $this->client->request('GET', $config['document']['url']);
        $this->assertStatus(200);
        self::assertSame(self::MERGED, $this->client->getInternalResponse()->getContent());

        $key = $config['document']['key'];
        $callback = $config['editorConfig']['callbackUrl'];
        HttpMock::on('https://office.example.org/cache/files/', fn () => new MockResponse("PK\x03\x04edited"));

        // Force save: a new version, same editing session.
        $this->callback($callback, ['key' => $key, 'status' => 6, 'url' => 'https://office.example.org/cache/files/data/k/output.docx?md5=x']);
        $this->assertStatus(200);
        self::assertSame(['error' => 0], json_decode((string) $this->client->getResponse()->getContent(), true));
        $saved = $this->reload($document['id']);
        self::assertSame(2, $saved->getVersion());
        self::assertSame($key, $saved->getEditorKey());
        self::assertNotNull($saved->getEditedAt());

        // Every editor closed: a new version, and a new key for the next sessions.
        $this->callback($callback, ['key' => $key, 'status' => 2, 'url' => 'https://office.example.org/cache/files/data/k/output.docx?md5=y']);
        $saved = $this->reload($document['id']);
        self::assertSame(3, $saved->getVersion());
        self::assertNotSame($key, $saved->getEditorKey());

        // An old session, a file elsewhere than ONLYOFFICE, a forged token: refused.
        $this->callback($callback, ['key' => $key, 'status' => 2, 'url' => 'https://office.example.org/cache/files/z']);
        $this->assertStatus(409);
        $this->callback($callback, ['key' => $saved->getEditorKey(), 'status' => 2, 'url' => 'https://evil.example.com/cache/files/x']);
        self::assertSame(1, json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
        self::assertSame(3, $this->reload($document['id'])->getVersion());
        $this->client->request('POST', $callback, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['token' => (new OnlyOfficeJwt('forged-secret-of-at-least-32-chars'))->sign(['key' => $saved->getEditorKey(), 'status' => 2, 'url' => 'https://office.example.org/cache/files/x'])]));
        $this->assertStatus(403);

        // Read only for the viewer mode.
        $view = $this->api('GET', '/api/documents/'.$document['id'].'/editor?mode=view', authorization: 'Bearer '.$this->jwtFor($alice));
        self::assertSame('view', $view['config']['editorConfig']['mode']);
        self::assertFalse($view['config']['document']['permissions']['edit']);
    }

    public function testReplaceTheContent(): void
    {
        $alice = $this->createUser('alice@example.org');
        $document = $this->merge($alice);
        $key = $this->reload($document['id'])->getEditorKey();

        $this->client->request('PUT', '/api/documents/'.$document['id'].'/content', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jwtFor($alice), 'HTTP_ACCEPT' => 'application/json'], content: "PK\x03\x04replaced");
        $this->assertStatus(200);
        $saved = $this->reload($document['id']);
        self::assertSame(2, $saved->getVersion());
        self::assertNotSame($key, $saved->getEditorKey());
    }

    public function testApplicationMergesOnBehalfOfAUser(): void
    {
        $this->createUser('alice@example.org');
        [, $token] = $this->createApplication();
        $document = $this->merge(null, ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_X_IMPERSONATE_USER' => 'alice@example.org']);

        self::assertSame('Partner CRM', $document['applicationName']);
        self::assertSame('alice@example.org', $document['ownerEmail']);
    }

    public function testAdministrationOfOnlyOfficeAndItsLicense(): void
    {
        $admin = 'Bearer '.$this->jwtFor($this->createUser('root@example.org', ['ROLE_ADMIN']));
        $state = $this->api('GET', '/api/admin/onlyoffice', authorization: $admin);
        $this->assertStatus(200);
        self::assertTrue($state['reachable']);
        self::assertSame('9.4.0', $state['version']);
        self::assertSame('community', $state['edition']);
        self::assertSame('none', $state['license']['status']);
        self::assertNull($state['license']['connections']);
        self::assertFalse($state['licenseFile']['installed']);

        $license = tempnam(sys_get_temp_dir(), 'lic');
        file_put_contents($license, '{"customer_id":"acme","signature":"abc"}');
        $state = $this->upload('/api/admin/onlyoffice/license', $admin, ['file' => new UploadedFile($license, 'license.lic', test: true)]);
        $this->assertStatus(200);
        self::assertTrue($state['licenseFile']['installed']);
        self::assertFileExists(static::getContainer()->getParameter('kernel.project_dir').'/var/test-data/onlyoffice/license.lic');

        file_put_contents($license, 'not a license');
        $this->upload('/api/admin/onlyoffice/license', $admin, ['file' => new UploadedFile($license, 'license.lic', test: true)]);
        $this->assertStatus(422);

        self::assertFalse($this->api('DELETE', '/api/admin/onlyoffice/license', authorization: $admin)['licenseFile']['installed']);

        $this->api('GET', '/api/admin/onlyoffice', authorization: 'Bearer '.$this->jwtFor($this->createUser('alice@example.org')));
        $this->assertStatus(403);
    }

    /** A fake ONLYOFFICE: every request is checked (JWT in body) and recorded. */
    private function fakeOnlyOffice(): void
    {
        $record = function (string $path, array $options): array {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);
            $payload = $this->jwt()->verify($body['token']);
            unset($body['token']);
            self::assertEquals($body, $payload, 'The token signs the request body.');
            $this->calls[] = ['path' => $path] + $body;

            return $body;
        };
        HttpMock::on(self::OO.'/docbuilder', function ($method, $url, $options) use ($record) {
            $body = $record('/docbuilder', $options);

            return new MockResponse(json_encode(['key' => $body['key'], 'end' => true, 'urls' => ['document.docx' => 'http://onlyoffice.test/cache/files/data/'.$body['key'].'/output/document.docx?md5=x']]));
        });
        HttpMock::on(self::OO.'/converter', function ($method, $url, $options) use ($record) {
            $record('/converter', $options);

            return new MockResponse('{"fileUrl":"https://office.example.org/cache/files/data/pdf/output.pdf?md5=y","fileType":"pdf","percent":100,"endConvert":true}');
        });
        HttpMock::on(self::OO.'/command', function ($method, $url, $options) use ($record) {
            $body = $record('/command', $options);

            return new MockResponse(json_encode('license' === $body['c']
                ? ['error' => 0, 'license' => ['end_date' => null, 'trial' => false, 'connections' => 2147483647, 'users_count' => 0], 'server' => ['resultType' => 3, 'packageType' => 0, 'buildVersion' => '9.4.0']]
                : ['error' => 0, 'version' => '9.4.0.129']));
        });
        HttpMock::on(self::OO.'/cache/files/data/merge-', fn () => new MockResponse(self::MERGED));
        HttpMock::on('https://office.example.org/cache/files/data/pdf/', fn () => new MockResponse('%PDF-1.7 converted'));
    }

    /** @param array<string, string> $server */
    private function merge(?User $user, array $server = []): array
    {
        $server = $server ?: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jwtFor($user)];
        $this->client->request('POST', '/api/documents', ['values' => json_encode(SampleTemplate::VALUES + ['lignes' => []], \JSON_THROW_ON_ERROR), 'title' => 'Devis Martin'], ['template' => $this->sample()], $server + ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatus(202);
        $id = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        $owner = $user ?? $this->em()->getRepository(User::class)->findOneBy(['email' => 'alice@example.org']);

        // Merged synchronously in the test environment.
        return $this->api('GET', '/api/documents/'.$id, authorization: 'Bearer '.$this->jwtFor($owner));
    }

    private function sample(): UploadedFile
    {
        return new UploadedFile((new SampleTemplate())->write(), SampleTemplate::NAME, test: true);
    }

    /** @param array<string, UploadedFile> $files */
    private function upload(string $uri, string $authorization, array $files): ?array
    {
        $this->client->request('POST', $uri, [], $files, ['HTTP_AUTHORIZATION' => $authorization, 'HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $body */
    private function callback(string $url, array $body): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['token' => $this->jwt()->sign($body)] + $body));
    }

    private function reload(string $id): Document
    {
        $this->em()->clear();

        return $this->em()->find(Document::class, $id);
    }

    private function jwt(): OnlyOfficeJwt
    {
        return new OnlyOfficeJwt((string) $_SERVER['ONLYOFFICE_JWT_SECRET']);
    }
}
