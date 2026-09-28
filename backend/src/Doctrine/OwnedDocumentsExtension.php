<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Document;
use Doctrine\ORM\QueryBuilder;
use Rocket\Core\Security\ActorContext;

/** Documents are private: everyone, administrators included, only lists their own. */
final class OwnedDocumentsExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly ActorContext $actor)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Document::class !== $resourceClass) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        $user = $this->actor->getUser();
        if (null === $user) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $param = $queryNameGenerator->generateParameterName('owner');
        $queryBuilder->andWhere(\sprintf('%s.owner = :%s', $alias, $param))->setParameter($param, $user->getId(), 'uuid');
    }
}
