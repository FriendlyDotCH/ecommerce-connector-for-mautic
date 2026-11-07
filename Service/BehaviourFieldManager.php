<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Helper\FieldAliasHelper;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviour;

/**
 * Keeps the contact number field behind each behaviour in step with it.
 */
class BehaviourFieldManager
{
    public const ALIAS_PREFIX = 'ecb_';

    public function __construct(
        private FieldModel $fieldModel,
        private FieldAliasHelper $fieldAliasHelper,
    ) {
    }

    /**
     * Creates the field on first save (fixing the behaviour's alias) and keeps its label in step.
     */
    public function saveField(EcommerceBehaviour $behaviour, ?string $requestedAlias = null): LeadField
    {
        $field = $this->getField($behaviour);

        if (null === $field) {
            $field = new LeadField();
            $field->setLabel($behaviour->getName());
            $field->setAlias($this->fieldModel->cleanAlias(
                (string) ($requestedAlias ?: self::ALIAS_PREFIX.$behaviour->getName()),
                self::ALIAS_PREFIX,
                25
            ));
            $field->setType('number');
            $field->setObject('lead');
            $field->setGroup(LeadCommerceFieldDefinition::GROUP);
            $field->setIsFixed(false);
            $field->setIsListable(true);
            $field->setIsVisible(true);
            $field->setIsPubliclyUpdatable(false);
            $field->setIsPublished(true);
            $field->setProperties(['roundmode' => 4, 'scale' => 2]);
            $this->fieldAliasHelper->makeAliasUnique($field);

            $this->fieldModel->saveEntity($field);
            $behaviour->setFieldAlias($field->getAlias());

            return $field;
        }

        if ($field->getLabel() !== $behaviour->getName()) {
            $field->setLabel($behaviour->getName());
            $this->fieldModel->saveEntity($field);
        }

        return $field;
    }

    public function getField(EcommerceBehaviour $behaviour): ?LeadField
    {
        if (null === $behaviour->getFieldAlias()) {
            return null;
        }

        $field = $this->fieldModel->getRepository()->findOneBy(['alias' => $behaviour->getFieldAlias(), 'object' => 'lead']);

        return $field instanceof LeadField ? $field : null;
    }

    /**
     * @return list<string> names of segments that filter on the behaviour's field
     */
    public function getSegmentsUsingField(EcommerceBehaviour $behaviour): array
    {
        $field = $this->getField($behaviour);
        if (null === $field) {
            return [];
        }

        $names = [];
        foreach ($this->fieldModel->getFieldSegments($field) as $segment) {
            $names[] = sprintf('"%s" (%d)', $segment->getName(), $segment->getId());
        }

        return $names;
    }

    public function deleteField(EcommerceBehaviour $behaviour): void
    {
        $field = $this->getField($behaviour);
        if (null !== $field) {
            $this->fieldModel->deleteEntity($field);
        }
    }
}
