<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Symfony\Contracts\Translation\TranslatorInterface;

class LeadCommerceFieldInstaller
{
    public function __construct(
        private FieldModel $fieldModel,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private ?string $tablePrefix = null,
    ) {
    }

    public function installFields(): void
    {
        foreach (LeadCommerceFieldDefinition::getFields() as $definition) {
            $this->ensureFieldExists($definition);
        }
    }

    /**
     * @param array{label: string, type: string, alias: string, list?: array<string, string>} $definition
     */
    private function ensureFieldExists(array $definition): void
    {
        $existing = $this->fieldModel->getRepository()->findOneBy(['alias' => $definition['alias']]);
        if ($existing instanceof LeadField && $this->leadColumnExists($definition['alias'])) {
            if ($existing->getGroup() !== LeadCommerceFieldDefinition::GROUP) {
                $existing->setGroup(LeadCommerceFieldDefinition::GROUP);
                $this->fieldModel->saveEntity($existing);
            }

            return;
        }

        if ($existing instanceof LeadField) {
            $this->fieldModel->deleteEntity($existing);
        }

        $field = new LeadField();
        $field->setLabel($this->translator->trans($definition['label']));
        $field->setAlias($definition['alias']);
        $field->setType($definition['type']);
        $field->setObject('lead');
        $field->setGroup(LeadCommerceFieldDefinition::GROUP);
        $field->setIsFixed(true);
        $field->setIsListable(true);
        $field->setIsVisible(true);
        $field->setIsPubliclyUpdatable(false);
        $field->setIsPublished(true);

        if (isset($definition['list'])) {
            $options = [];
            foreach ($definition['list'] as $value => $label) {
                $options[] = ['label' => $this->translator->trans($label), 'value' => $value];
            }
            $field->setProperties(['list' => $options]);
        }

        $this->fieldModel->saveEntity($field);
    }

    private function leadColumnExists(string $alias): bool
    {
        $schema = $this->entityManager->getConnection()->createSchemaManager()->introspectSchema();
        // Same prefix source as the repositories; the env var is not set in every environment.
        $table  = ($this->tablePrefix ?? MAUTIC_TABLE_PREFIX).'leads';

        if (!$schema->hasTable($table)) {
            return false;
        }

        return $schema->getTable($table)->hasColumn($alias);
    }
}
