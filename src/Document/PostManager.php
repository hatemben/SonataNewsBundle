<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\NewsBundle\Document;

use Sonata\Doctrine\Document\BaseDocumentManager;
use Sonata\DoctrineMongoDBAdminBundle\Datagrid\Pager;
use Sonata\DoctrineMongoDBAdminBundle\Datagrid\ProxyQuery;
use Sonata\NewsBundle\Model\BlogInterface;
use Sonata\NewsBundle\Model\PostInterface;
use Sonata\NewsBundle\Model\PostManagerInterface;

class PostManager extends BaseDocumentManager implements PostManagerInterface
{
    protected $query;

    /**
     * @param $year
     * @param $month
     * @param $day
     * @param $slug
     *
     * @return PostInterface|null
     *
     * @deprecated since version 3.2, to be removed in 4.0. Use PostManager::findOneByPermalink instead
     */
    public function findOneBySlug($year, $month, $day, $slug)
    {
        @trigger_error(
            'Calling the '.__METHOD__.' method is deprecated since 3.2 and will be removed in 4.0.'
            .' Use Sonata\NewsBundle\Document::findOneByPermalink() instead.',
            E_USER_DEPRECATED
        );

        $pdqp = $this->getPublicationDateQueryParts(sprintf('%s-%s-%s', $year, $month, $day), 'day');

        return $this->getDocumentManager()
            ->createQueryBuilder($this->class)
            ->field('slug')->equals($slug)
            ->expr($pdqp['query'])
            ->getQuery()
            ->getSingleResult();
    }

    /**
     * @param string $permalink
     *
     * @return PostInterface|null
     */
    public function findOneByPermalink($permalink, BlogInterface $blog)
    {
        $query = $this->getDocumentManager()->createQueryBuilder($this->class);

        try {
            $urlParameters = $blog->getPermalinkGenerator()->getParameters($permalink);

        } catch (\InvalidArgumentException $exception) {
            return null;
        }
        if (isset($urlParameters['year'], $urlParameters['month'], $urlParameters['day'])) {
            $date = sprintf('%d-%d-%d', $urlParameters['year'], $urlParameters['month'], $urlParameters['day']);
            $query->field('publicationDateStart')->gte(new \DateTime($date))
                ->field('publicationDateStart')->lte(new \DateTime($date.' +1 day'));
        }

        if (isset($urlParameters['slug'])) {
            $query->field('slug')->equals($urlParameters['slug']);
        }

        // Still missing left join part
        if (isset($urlParameters['collection'])) {
            /*
            if (null === $urlParameters['collection']) {
                $query->field('collection')->equals(null);
            } else {
                $query->field('slug')->in(['collection' => $urlParameters['collection']]);

            }

            $query
                ->leftJoin('p.collection', 'c')
                ->andWhere($collectionQueryParts['query']);
            //*/
        }
        return $query->getQuery()->getSingleResult();
    }

    /**
     * Valid criteria are:
     *    enabled - boolean
     *    date - query
     *    tag - string
     *    author - 'NULL', 'NOT NULL', id, array of ids
     *    collections - CollectionInterface
     *    mode - string public|admin.
     */
    public function getPager(array $criteria, $page, $limit = 10, array $sort = [])
    {
        if (!isset($criteria['mode'])) {
            $criteria['mode'] = 'public';
        }
        $parameters = [];
        $query = $this->getDocumentManager()->createQueryBuilder($this->class);
        $query
            ->field('tags')
            ->sort('publicationDateStart','DESC');


        if (!isset($criteria['enabled']) && 'public' === $criteria['mode']) {
            $criteria['enabled'] = true;
        }
        if (isset($criteria['enabled'])) {
            $query->field('enabled')->equals($criteria['enabled']);
        }

        if (isset($criteria['date'], $criteria['date']['query'], $criteria['date']['params'])) {
            $query->field($criteria['date']['query'])->equals($criteria['date']['params']);
          //  $parameters = array_merge($parameters, $criteria['date']['params']);
        }

        if (isset($criteria['tag'])) {
            $query->field('slug')->equals($criteria['tag']);
        }

        if (isset($criteria['author'])) {
            if (!\is_array($criteria['author']) && stristr($criteria['author'], 'NULL')) {
                $query->field('author')->equals($criteria['author']);
            } else {
                $query->field('author')->in((array) $criteria['author']);
            }
        }

        if (isset($criteria['collection']) && $criteria['collection'] instanceof CollectionInterface) {
            $query->field('collection')->equals($criteria['collection']->getId());
        }


        $pager = new Pager();
        $pager->setMaxPerPage($limit);
        $pager->setQuery(new ProxyQuery($query));
        $pager->setPage($page);
        $pager->init();

        return $pager;
    }

    public function getPublicationDateQueryParts($date, $step, $alias = 'p')
    {
        return [
            'query' => sprintf('%s.publicationDateStart >= :startDate AND %s.publicationDateStart < :endDate', $alias, $alias),
            'params' => [
                'startDate' => new \DateTime($date),
                'endDate' => new \DateTime($date.'+1 '.$step),
            ],
        ];
    }

    /**
     * @param string $collection
     *
     * @return array
     */
    final protected function getPublicationCollectionQueryParts($collection)
    {
        $queryParts = ['query' => '', 'params' => []];

        if (null === $collection) {
            $queryParts['query'] = 'p.collection IS NULL';
        } else {
            $queryParts['query'] = 'c.slug = :collection';
            $queryParts['params'] = ['collection' => $collection];
        }

        return $queryParts;
    }
}
