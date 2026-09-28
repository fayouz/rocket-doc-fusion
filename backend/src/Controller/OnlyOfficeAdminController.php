<?php

namespace App\Controller;

use App\OnlyOffice\OnlyOfficeClient;
use App\OnlyOffice\OnlyOfficeException;
use App\OnlyOffice\OnlyOfficeLicense;
use App\OnlyOffice\OnlyOfficeUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/** Administration → ONLYOFFICE: the Document Server, its edition and its license. */
#[IsGranted('ROLE_ADMIN')]
final class OnlyOfficeAdminController extends AbstractController
{
    public function __construct(
        private readonly OnlyOfficeClient $onlyOffice,
        private readonly OnlyOfficeUrls $urls,
        private readonly OnlyOfficeLicense $license,
    ) {
    }

    /**
     * State of ONLYOFFICE Docs: reachable, version, edition and license (command "license": dates, connections,
     * users), and whether a license file is installed.
     */
    #[Route('/api/admin/onlyoffice', name: 'api_admin_onlyoffice', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $state = [
            'publicUrl' => $this->urls->publicUrl(),
            'internalUrl' => $this->urls->internalUrl(),
            'reachable' => false,
            'version' => null,
            'edition' => null,
            'license' => null,
            'licenseFile' => ['installed' => $this->license->exists(), 'updatedAt' => $this->license->updatedAt()?->format(\DATE_ATOM)],
            'error' => null,
        ];
        try {
            $info = $this->onlyOffice->command('license');
            $server = (array) ($info['server'] ?? []);
            $license = (array) ($info['license'] ?? []);
            $state['reachable'] = true;
            $state['version'] = $server['buildVersion'] ?? null;
            $state['edition'] = match ((int) ($server['packageType'] ?? -1)) {
                0 => 'community',
                1 => 'enterprise',
                2 => 'developer',
                default => null,
            };
            $state['license'] = [
                // Community edition: no license. Otherwise the check result of ONLYOFFICE (resultType): 3 success,
                // 7 success within limits, 2, 6 and 11 expired, 16 not started yet, others a limit or an invalid file.
                'status' => 'community' === $state['edition'] ? 'none' : match ((int) ($server['resultType'] ?? 0)) {
                    3, 7 => 'valid',
                    2, 6, 11 => 'expired',
                    16 => 'not_started',
                    default => 'invalid',
                },
                'customer' => ($license['customer_id'] ?? '') ?: null,
                'startDate' => $license['start_date'] ?? null,
                'endDate' => $license['end_date'] ?? null,
                'trial' => (bool) ($license['trial'] ?? false),
                'connections' => self::limit($license['connections'] ?? null),
                'connectionsView' => self::limit($license['connections_view'] ?? null),
                'users' => self::limit($license['users_count'] ?? null),
            ];
        } catch (OnlyOfficeException $e) {
            $state['error'] = $e->getMessage();
        }

        return $this->json($state);
    }

    /**
     * Installs the license file (multipart "file", license.lic). ONLYOFFICE applies it once restarted
     * (`docker compose restart onlyoffice`).
     */
    #[Route('/api/admin/onlyoffice/license', name: 'api_admin_onlyoffice_license', methods: ['POST'])]
    public function install(#[MapUploadedFile([new Assert\NotNull()])] UploadedFile $file): JsonResponse
    {
        if ($file->getSize() > 65536) {
            return $this->json(['detail' => 'This is not an ONLYOFFICE license file (license.lic).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->license->install((string) file_get_contents($file->getPathname()));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['detail' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->status();
    }

    /** Back to the Community edition (once ONLYOFFICE restarted). */
    #[Route('/api/admin/onlyoffice/license', name: 'api_admin_onlyoffice_license_remove', methods: ['DELETE'])]
    public function remove(): JsonResponse
    {
        $this->license->remove();

        return $this->status();
    }

    /** Unlimited values of the Community edition are the largest integer. */
    private static function limit(mixed $value): ?int
    {
        return \is_numeric($value) && (int) $value < 2147483647 ? (int) $value : null;
    }
}
