<?php

namespace App\Exporters\Normalizers;

use App\Exporters\SalesOsClient;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes WHMCS source data to SalesOS canonical items.
 *
 * Emits:
 *   account      — one per whmcs_clients record (includes services in meta)
 *   identity     — one per client email + one per contact email
 *   conversation — one per unique ticket_id in whmcs_tickets
 *   message      — one per whmcs_tickets record (each record = one msg/reply)
 */
class WhmcsNormalizer
{
    private string $systemSlug;

    public function __construct(string $systemSlug)
    {
        $this->systemSlug = $systemSlug;
    }

    /**
     * Yield canonical items from source_whmcs_clients updated since $sinceAt.
     * Also emits identity for each client's primary email.
     * Includes services data (revenue, service list) in account meta.
     */
    public function normalizeClients(?string $sinceAt, callable $log): \Generator
    {
        // Preload all services for this system grouped by clientid
        $servicesByClient = DB::table('source_whmcs_services')
            ->where('source_system', $this->systemSlug)
            ->get()
            ->groupBy(fn($r) => json_decode($r->payload_json, true)['clientid'] ?? null)
            ->map(fn($rows) => $rows->map(fn($r) => json_decode($r->payload_json, true))->values()->all());

        $query = DB::table('source_whmcs_clients')
            ->where('source_system', $this->systemSlug)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $record = json_decode($row->payload_json, true);

            $companyName = !empty($record['companyname'])
                ? $record['companyname']
                : trim(($record['firstname'] ?? '') . ' ' . ($record['lastname'] ?? ''));

            $clientId = (string) $record['clientid'];

            // Build services summary for this client
            $services = [];
            foreach ($servicesByClient[$record['clientid']] ?? [] as $svc) {
                $services[] = [
                    'service_id'    => $svc['serviceid'] ?? null,
                    'product_name'  => $svc['product_name'] ?? null,
                    'status'        => $svc['service_status'] ?? null,
                    'start_date'    => $svc['start_date'] ?? null,
                    'total_revenue' => (float) ($svc['total_revenue'] ?? 0),
                    'renewal_count' => (int) ($svc['renewal_count'] ?? 0),
                    'cancelled'     => (bool) ($svc['cancelled'] ?? false),
                ];
            }

            $totalRevenue = array_sum(array_column($services, 'total_revenue'));

            $meta = array_filter([
                'clientid'      => $record['clientid'],
                'firstname'     => $record['firstname'] ?? null,
                'lastname'      => $record['lastname'] ?? null,
                'datecreated'   => $record['datecreated'] ?? null,
                'services'      => $services ?: null,
                'total_revenue' => $totalRevenue > 0 ? round($totalRevenue, 2) : null,
            ]);

            $payload = array_filter([
                'company_name' => $companyName ?: null,
                'email'        => $record['email'] ?? null,
                'phone'        => $record['phonenumber'] ?? null,
                'address'      => isset($record['address']) ? ($record['address'] . ', ' . ($record['city'] ?? '')) : null,
                'meta'         => $meta,
            ]);

            yield [
                'item'       => SalesOsClient::buildItem('whmcs', $this->systemSlug, 'account', 'upsert', $clientId, $payload),
                'updated_at' => $row->updated_at,
            ];

            // Also emit identity for client's primary email
            if (!empty($record['email'])) {
                $displayName = trim(($record['firstname'] ?? '') . ' ' . ($record['lastname'] ?? '')) ?: null;
                yield [
                    'item'       => SalesOsClient::buildItem('whmcs', $this->systemSlug, 'identity', 'upsert', $record['email'], array_filter([
                        'identity_type' => 'email',
                        'value'         => $record['email'],
                        'display_name'  => $displayName,
                    ])),
                    'updated_at' => $row->updated_at,
                ];
            }
        }
    }

    /**
     * Yield canonical identity items from source_whmcs_contacts updated since $sinceAt.
     * WHMCS contacts are additional contacts per client (secondary contacts for the same company).
     */
    public function normalizeContacts(?string $sinceAt, callable $log): \Generator
    {
        $query = DB::table('source_whmcs_contacts')
            ->where('source_system', $this->systemSlug)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        foreach ($query->cursor() as $row) {
            $record = json_decode($row->payload_json, true);

            if (empty($record['email'])) {
                continue;
            }

            $displayName = trim(($record['firstname'] ?? '') . ' ' . ($record['lastname'] ?? '')) ?: null;

            yield [
                'item'       => SalesOsClient::buildItem('whmcs', $this->systemSlug, 'identity', 'upsert', $record['email'], array_filter([
                    'identity_type' => 'email',
                    'value'         => $record['email'],
                    'display_name'  => $displayName,
                ])),
                'updated_at' => $row->updated_at,
            ];
        }
    }

    /**
     * Yield canonical items from source_whmcs_tickets updated since $sinceAt.
     * Emits: conversation + message per ticket message.
     */
    public function normalizeTickets(?string $sinceAt, callable $log): \Generator
    {
        $query = DB::table('source_whmcs_tickets')
            ->where('source_system', $this->systemSlug)
            ->orderBy('updated_at');

        if ($sinceAt) {
            $query->where('updated_at', '>', $sinceAt);
        }

        // Track emitted conversations to deduplicate within same batch
        $emittedConvs = [];

        foreach ($query->cursor() as $row) {
            $record   = json_decode($row->payload_json, true);
            $ticketId = (string) $record['ticket_id'];
            $msgId    = (string) $record['msg_id'];
            $sentAt   = $record['sent_at'] ?? null;

            // Emit conversation item once per ticket_id (first time we see it)
            if (!isset($emittedConvs[$ticketId])) {
                $convPayload = [
                    'channel_type'   => 'ticket',
                    'subject'        => $record['title'] ?? "Ticket #{$ticketId}",
                    'started_at'     => $sentAt,
                    'last_message_at' => $sentAt,
                    'meta'           => [
                        'status'    => $record['status'] ?? null,
                        'dept'      => $record['department_name'] ?? null,
                        'client_id' => $record['client_userid'] ?? null,
                    ],
                ];

                $emittedConvs[$ticketId] = true;
                yield [
                    'item'       => SalesOsClient::buildItem('whmcs', $this->systemSlug, 'conversation', 'upsert', "ticket_{$ticketId}", $convPayload),
                    'updated_at' => $row->updated_at,
                ];
            }

            // Emit message item
            $direction = match ($record['direction'] ?? 'from_client') {
                'from_client' => 'customer',
                'from_staff'  => 'internal',
                default       => 'customer',
            };

            $msgPayload = [
                'conversation_external_id'  => "ticket_{$ticketId}",
                'conversation_channel_type' => 'ticket',
                'sender_external_id'        => $record['sender_email'] ?? null,
                'sender_identity_type'      => 'email',
                'sender_name'               => $record['sender_name'] ?? 'Unknown',
                'body_text'                 => $record['message'] ?? null,
                'occurred_at'               => $sentAt,
                'direction_hint'            => $direction,
                'meta'                      => [
                    'ticket_id'  => $ticketId,
                    'msg_id'     => $msgId,
                    'status'     => $record['status'] ?? null,
                    'dept'       => $record['department_name'] ?? null,
                ],
            ];

            // Emit identity hint for sender
            if (!empty($record['sender_email'])) {
                $identPayload = [
                    'identity_type' => 'email',
                    'value'         => $record['sender_email'],
                    'display_name'  => $record['sender_name'] ?? null,
                ];
                yield [
                    'item'       => SalesOsClient::buildItem('whmcs', $this->systemSlug, 'identity', 'upsert', $record['sender_email'], $identPayload),
                    'updated_at' => $row->updated_at,
                ];
            }

            yield [
                'item'       => SalesOsClient::buildItem('whmcs', $this->systemSlug, 'message', 'upsert', $msgId, $msgPayload),
                'updated_at' => $row->updated_at,
            ];
        }
    }
}
