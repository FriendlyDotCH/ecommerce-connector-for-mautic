<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use MauticPlugin\EcommerceConnectorBundle\DTO\PurchaseCriteria;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * A rolling purchase measure kept in a number field on each contact, for example
 * "spend on Candles in the last 90 days".
 */
class EcommerceBehaviour
{
    private ?int $id = null;

    private string $name = '';

    /**
     * Alias of the contact field that holds the value. Set once on creation.
     */
    private ?string $fieldAlias = null;

    private string $metric = PurchaseCriteria::METRIC_SPEND;

    private string $scope = PurchaseCriteria::SCOPE_ANY;

    /**
     * Comma-separated categories, products or sources.
     */
    private ?string $values = null;

    private int $days = 0;

    private bool $isPublished = true;

    private ?\DateTimeInterface $dateAdded = null;

    private ?\DateTimeInterface $lastCalculated = null;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('ecommerce_behaviours')
            ->setCustomRepositoryClass(EcommerceBehaviourRepository::class)
            ->addUniqueConstraint(['field_alias'], 'ecommerce_behaviour_field_alias');

        $builder->addId();
        $builder->addField('name', 'string');
        $builder->addNamedField('fieldAlias', 'string', 'field_alias');
        $builder->addField('metric', 'string', ['length' => 16]);
        $builder->addField('scope', 'string', ['length' => 16]);
        $builder->addNullableField('values', 'text', 'filter_values');
        $builder->addField('days', 'integer');
        $builder->addNamedField('isPublished', 'boolean', 'is_published');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added', true);
        $builder->addNamedField('lastCalculated', 'datetime', 'last_calculated', true);
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint('name', new Assert\NotBlank(['message' => 'mautic.core.name.required']));
        $metadata->addPropertyConstraint('name', new Assert\Length(['max' => 191]));
        $metadata->addPropertyConstraint('metric', new Assert\Choice(['choices' => PurchaseCriteria::METRICS]));
        $metadata->addPropertyConstraint('scope', new Assert\Choice(['choices' => PurchaseCriteria::SCOPES]));
        $metadata->addPropertyConstraint('days', new Assert\GreaterThanOrEqual(0));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
    }

    public function getFieldAlias(): ?string
    {
        return $this->fieldAlias;
    }

    public function setFieldAlias(?string $fieldAlias): void
    {
        $this->fieldAlias = $fieldAlias;
    }

    public function getMetric(): string
    {
        return $this->metric;
    }

    public function setMetric(string $metric): void
    {
        $this->metric = $metric;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    public function getValues(): ?string
    {
        return $this->values;
    }

    public function setValues(?string $values): void
    {
        $values       = implode(', ', PurchaseCriteria::splitList($values));
        $this->values = '' !== $values ? $values : null;
    }

    public function getDays(): int
    {
        return $this->days;
    }

    public function setDays(?int $days): void
    {
        $this->days = (int) $days;
    }

    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    public function getIsPublished(): bool
    {
        return $this->isPublished;
    }

    public function setIsPublished(bool $isPublished): void
    {
        $this->isPublished = $isPublished;
    }

    public function getDateAdded(): ?\DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function setDateAdded(?\DateTimeInterface $dateAdded): void
    {
        $this->dateAdded = $dateAdded;
    }

    public function getLastCalculated(): ?\DateTimeInterface
    {
        return $this->lastCalculated;
    }

    public function setLastCalculated(?\DateTimeInterface $lastCalculated): void
    {
        $this->lastCalculated = $lastCalculated;
    }

    public function getCriteria(): PurchaseCriteria
    {
        return new PurchaseCriteria($this->metric, $this->scope, PurchaseCriteria::splitList($this->values), $this->days);
    }
}
