<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EcommerceConnectorBundle\Service\ExistingContactLookup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExistingContactLookupTest extends TestCase
{
    private LeadModel&MockObject $leadModel;

    private ExistingContactLookup $lookup;

    protected function setUp(): void
    {
        $this->leadModel = $this->createMock(LeadModel::class);
        $this->lookup    = new ExistingContactLookup($this->leadModel, $this->createMock(\Doctrine\DBAL\Connection::class));
    }

    public function testReturnsNullForEmptyEmail(): void
    {
        $this->leadModel->expects($this->never())->method('checkForDuplicateContact');

        $this->assertNull($this->lookup->findByEmail(null));
        $this->assertNull($this->lookup->findByEmail(''));
        $this->assertNull($this->lookup->findByEmail('not-an-email'));
    }

    public function testReturnsNullWhenContactDoesNotExist(): void
    {
        $newLead = new Lead();

        $this->leadModel->expects($this->once())
            ->method('checkForDuplicateContact')
            ->with(['email' => 'unknown@example.com'])
            ->willReturn($newLead);

        $this->assertNull($this->lookup->findByEmail('unknown@example.com'));
    }

    public function testReturnsExistingContactWhenEmailMatches(): void
    {
        $existing = new Lead();
        $existing->setId(15);

        $this->leadModel->expects($this->once())
            ->method('checkForDuplicateContact')
            ->with(['email' => 'jane@example.com'])
            ->willReturn($existing);

        $this->assertSame($existing, $this->lookup->findByEmail('Jane@Example.com'));
    }
}
