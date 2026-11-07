<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Service;

use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderItemRepository;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceOrderRepository;

class ContactOrderExportFormatter
{
    public const EXPORT_ALIAS = 'ecommerce_orders';

    public function __construct(
        private EcommerceOrderRepository $orderRepository,
        private EcommerceOrderItemRepository $orderItemRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $leadExport
     *
     * @return array<string, mixed>
     */
    public function appendToLeadRow(array $leadExport, Lead $lead): array
    {
        $leadId = (int) $lead->getId();
        if ($leadId > 0) {
            $leadExport[self::EXPORT_ALIAS] = $this->getOrdersJsonForLead($leadId);
        }

        return $leadExport;
    }

    /**
     * @param array<int, array<string, mixed>>|\Iterator $data
     *
     * @return array<int, array<string, mixed>>|\Iterator
     */
    public function appendToAliasValueExport(mixed $data, string $filename): mixed
    {
        if (!is_array($data) || !str_starts_with(strtolower($filename), 'contact_data_')) {
            return $data;
        }

        $leadId = $this->findLeadId($data);
        if (null === $leadId) {
            return $data;
        }

        $data[] = [
            'alias' => self::EXPORT_ALIAS,
            'value' => $this->getOrdersJsonForLead($leadId),
        ];

        return $data;
    }

    public function getOrdersJsonForLead(int $leadId): string
    {
        return json_encode($this->getOrdersForLead($leadId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOrdersForLead(int $leadId): array
    {
        if ($leadId <= 0) {
            return [];
        }

        $orders         = $this->orderRepository->getOrdersForLeadTimeline($leadId);
        $orderIds       = array_map(static fn (array $order): int => (int) $order['id'], $orders['results']);
        $itemsByOrderId = $this->orderItemRepository->getItemsGroupedByOrderIds($orderIds);
        $export         = [];

        foreach ($orders['results'] as $order) {
            $orderPk = (int) $order['id'];

            $export[] = [
                'order_id'          => (string) $order['order_id'],
                'order_total'       => number_format((float) $order['order_total'], 2, '.', ''),
                'status'            => (string) ($order['status'] ?? 'paid'),
                'refunded_total'    => number_format((float) ($order['refunded_total'] ?? 0), 2, '.', ''),
                'net_total'         => number_format((float) ($order['net_total'] ?? $order['order_total']), 2, '.', ''),
                'order_currency'    => strtoupper((string) ($order['order_currency'] ?? '')),
                'order_source'     => (string) $order['order_source'],
                'location'          => !empty($order['location']) ? (string) $order['location'] : null,
                'order_date'        => (string) $order['order_date'],
                'date_added'        => (string) $order['date_added'],
                'attributed_email'  => !empty($order['email_name']) ? (string) $order['email_name'] : null,
                'items'             => $itemsByOrderId[$orderPk] ?? [],
            ];
        }

        return $export;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function findLeadId(array $rows): ?int
    {
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['alias'] ?? null) !== 'id') {
                continue;
            }

            $leadId = (int) ($row['value'] ?? 0);

            return $leadId > 0 ? $leadId : null;
        }

        return null;
    }
}
