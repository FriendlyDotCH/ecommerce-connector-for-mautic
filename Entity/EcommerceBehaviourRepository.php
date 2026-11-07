<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<EcommerceBehaviour>
 */
class EcommerceBehaviourRepository extends CommonRepository
{
    /**
     * @return list<EcommerceBehaviour>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * @return list<EcommerceBehaviour>
     */
    public function findPublished(): array
    {
        return $this->findBy(['isPublished' => true], ['id' => 'ASC']);
    }
}
