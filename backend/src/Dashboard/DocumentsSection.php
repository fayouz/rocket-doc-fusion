<?php

namespace App\Dashboard;

use Doctrine\DBAL\Connection;
use Rocket\Core\Dashboard\DashboardSectionInterface;
use Rocket\Core\Dashboard\DashboardStats;
use Rocket\Core\Entity\User;

/**
 * Rocket Doc Fusion on the dashboard: merges over 30 days, documents edited, failures.
 * A user sees their own documents, an administrator the whole platform.
 */
final class DocumentsSection implements DashboardSectionInterface
{
    private const STATUS = [
        'merging' => ['Fusion en cours', 'info'],
        'ready' => ['Prêt', 'success'],
        'failed' => ['Échec', 'error'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function build(User $user, bool $admin, \DateTimeImmutable $from, \DateTimeImmutable $previousFrom): array
    {
        $params = ['user' => $user->getId()->toRfc4122()];
        [$scope, $scopeParams] = $admin ? ['TRUE', []] : ['d.owner_id = :user', $params];

        $daily = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', d.created_at), 'YYYY-MM-DD') AS day, COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE d.status = 'failed') AS failed
             FROM document d WHERE $scope AND d.created_at >= :from GROUP BY 1",
            $scopeParams + ['from' => DashboardStats::sql($from)],
        ) as $row) {
            $daily[$row['day']] = ['merges' => (int) $row['n'], 'failed' => (int) $row['failed']];
        }
        $period = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE d.status = 'failed') AS failed,
                    COUNT(*) FILTER (WHERE d.edited_at >= :from) AS edited
             FROM document d WHERE $scope AND d.created_at >= :from",
            $scopeParams + ['from' => DashboardStats::sql($from)],
        );
        $previous = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM document d WHERE $scope AND d.created_at >= :from AND d.created_at < :to",
            $scopeParams + ['from' => DashboardStats::sql($previousFrom), 'to' => DashboardStats::sql($from)],
        );
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM document d WHERE $scope", $scopeParams);

        return [
            'kpis' => [
                [
                    'id' => 'merges',
                    'label' => $admin ? 'Fusions (30 j)' : 'Mes fusions (30 j)',
                    'value' => (int) $period['total'],
                    'format' => 'number',
                    'icon' => 'i-lucide-file-stack',
                    'tone' => 'bg-primary/10 text-primary',
                    'series' => 'merges',
                    'previous' => $previous,
                    'detail' => \sprintf('%d document(s) au total', $total),
                ],
                [
                    'id' => 'edited',
                    'label' => 'Retouchés dans l’éditeur',
                    'value' => (int) $period['edited'],
                    'format' => 'number',
                    'icon' => 'i-lucide-file-pen-line',
                    'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                    'detail' => 'documents fusionnés sur la période puis modifiés',
                ],
                [
                    'id' => 'merge_failed',
                    'label' => 'Échecs (30 j)',
                    'value' => (int) $period['failed'],
                    'format' => 'number',
                    'icon' => 'i-lucide-triangle-alert',
                    'tone' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                    'series' => 'failed',
                ],
            ],
            'series' => [
                ['key' => 'merges', 'label' => 'Fusions', 'color' => 'bg-primary'],
                ['key' => 'failed', 'label' => 'Échecs', 'color' => 'bg-rose-500'],
            ],
            'daily' => $daily,
            // Always the user's own documents, even for administrators.
            'recent' => [
                'title' => 'Mes derniers documents',
                'link' => '/documents',
                'empty' => 'Aucun document pour le moment : fusionnez un modèle depuis « Fusionner ».',
                'items' => array_map(static fn (array $row) => [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'subtitle' => $row['template_name'].($row['version'] > 1 ? \sprintf(' · version %d', $row['version']) : ''),
                    'at' => DashboardStats::atom($row['updated_at']),
                    'badge' => self::STATUS[$row['status']][0] ?? $row['status'],
                    'badgeColor' => self::STATUS[$row['status']][1] ?? 'neutral',
                    'link' => '/documents/'.$row['id'],
                ], $this->db->fetchAllAssociative(
                    'SELECT id, title, template_name, version, status, updated_at FROM document WHERE owner_id = :user ORDER BY updated_at DESC LIMIT 6',
                    $params,
                )),
            ],
            'activity' => array_map(static fn (array $row) => [
                'type' => 'merge.failed',
                'at' => DashboardStats::atom($row['updated_at']),
                'title' => $row['title'],
                'actor' => $row['email'],
                'link' => $admin ? '/documents' : '/documents/'.$row['id'],
                'icon' => 'i-lucide-file-x',
                'label' => 'Fusion en échec',
                'color' => 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
            ], $this->db->fetchAllAssociative(
                "SELECT d.id, d.title, d.updated_at, u.email FROM document d JOIN \"user\" u ON u.id = d.owner_id
                 WHERE $scope AND d.status = 'failed' ORDER BY d.updated_at DESC LIMIT 8",
                $scopeParams,
            )),
            'quickActions' => [
                ['label' => 'Fusionner un modèle', 'icon' => 'i-lucide-file-stack', 'to' => '/merge', 'tone' => 'bg-primary/10 text-primary'],
                ['label' => 'Mes documents', 'icon' => 'i-lucide-files', 'to' => '/documents', 'tone' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400'],
            ],
        ];
    }
}
