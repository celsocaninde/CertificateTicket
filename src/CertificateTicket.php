<?php

namespace GlpiPlugin\Certificateticket;

use Certificate;
use CommonDBTM;
use CommonGLPI;
use CommonITILObject;
use CronTask;
use Dropdown;
use Entity;
use Html;
use Item_Ticket;
use ITILFollowup;
use ITILSolution;
use Ticket;

/**
 * -------------------------------------------------------------------------
 * Certificate Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Core class: scans certificates, opens richly formatted tickets before they
 * expire, manages escalating followups, auto-resolves on renewal and exposes a
 * "Generated tickets" tab on the Certificate form.
 */
class CertificateTicket extends CommonDBTM
{
    // Tab visibility follows the core Certificate read right
    public static $rightname = 'certificate';

    /** Notification event raised (on the Certificate itemtype) when a ticket is opened. */
    public const NOTIF_EVENT = 'plugin_certificateticket_expiration';

    /**
     * Add our event to the core Certificate notification target.
     * Hooked on Hooks::ITEM_GET_EVENTS for NotificationTargetCertificate.
     *
     * @param mixed $target NotificationTargetCertificate instance
     * @return void
     */
    public static function addNotificationEvents($target): void
    {
        if ($target instanceof \NotificationTargetCertificate) {
            $target->events[self::NOTIF_EVENT] = __('A ticket was opened for an expiring certificate', 'certificateticket');
        }
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_certificate_ticket';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Generated ticket', 'Generated tickets', $nb, 'certificateticket');
    }

    public static function getIcon(): string
    {
        return 'ti ti-certificate';
    }

    /**
     * Localized cron information.
     *
     * @param string $name
     * @return array<string,string>
     */
    public static function cronInfo($name): array
    {
        if ($name === 'CertificateTicket') {
            return [
                'description' => __('Open tickets for expiring certificates', 'certificateticket'),
                'parameter'   => __('Maximum days before expiration to look ahead', 'certificateticket'),
            ];
        }
        return [];
    }

    /**
     * Cron entry point.
     *
     * @param CronTask $task
     * @return int -1 error, 0 nothing, 1 done
     */
    public static function cronCertificateTicket(CronTask $task): int
    {
        $report = self::processCertificates($task, false);

        if ($report['errors'] > 0) {
            return -1;
        }
        return ($report['created'] + $report['followups'] + $report['resolved']) > 0 ? 1 : 0;
    }

    /* ===================================================================
     *  Scan engine
     * =================================================================== */

    /**
     * Process all certificates: resolve renewed, then open/escalate as needed.
     *
     * @param CronTask|null $task   Cron task (null in dry-run / CLI)
     * @param bool          $dryRun When true, nothing is written
     * @return array{created:int,followups:int,resolved:int,errors:int,preview:array}
     */
    public static function processCertificates(?CronTask $task = null, bool $dryRun = false): array
    {
        global $DB;

        $cfg        = PluginCertificateticketConfig::getConfig();
        $thresholds = self::parseThresholds($cfg['escalation_days']);
        $maxTh      = !empty($thresholds) ? max($thresholds) : 30;

        $report = ['created' => 0, 'followups' => 0, 'resolved' => 0, 'errors' => 0, 'preview' => []];

        // 1) Auto-resolve renewed certificates
        if ($cfg['auto_resolve']) {
            self::resolveRenewed($cfg, $maxTh, $report, $dryRun, $task);
        }

        // 2) Entities to scan
        if ($cfg['use_entity_delay']) {
            // Use GLPI's per-entity certificate-alert delay (same source as the core alert)
            $entities = array_keys(Entity::getEntitiesToNotify('use_certificates_alert'));
        } else {
            // Plugin-driven mode: scan every entity with the plugin lead time
            $entities = [];
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_entities']) as $e) {
                $entities[] = (int) $e['id'];
            }
        }

        foreach ($entities as $entity) {
            $before = $cfg['use_entity_delay']
                ? (int) Entity::getUsedConfig('send_certificates_alert_before_delay', $entity)
                : (int) $cfg['days_before'];
            if ($before <= 0) {
                $before = (int) $cfg['days_before'];
            }

            $iterator = $DB->request([
                'SELECT'    => [
                    'glpi_certificates.id',
                    'glpi_certificates.date_expiration',
                    'glpi_plugin_certificate_ticket.id AS track_id',
                    'glpi_plugin_certificate_ticket.ticket_id',
                    'glpi_plugin_certificate_ticket.date AS track_date',
                    'glpi_plugin_certificate_ticket.notified_days',
                ],
                'FROM'      => 'glpi_certificates',
                'LEFT JOIN' => [
                    'glpi_plugin_certificate_ticket' => [
                        'FKEY' => [
                            'glpi_plugin_certificate_ticket' => 'certificate_id',
                            'glpi_certificates'              => 'id',
                        ],
                    ],
                ],
                'WHERE'     => [
                    'glpi_certificates.is_deleted'   => 0,
                    'glpi_certificates.is_template'  => 0,
                    'glpi_certificates.entities_id'  => $entity,
                    ['NOT' => ['glpi_certificates.date_expiration' => null]],
                    ['RAW' => ['DATEDIFF(`glpi_certificates`.`date_expiration`, CURDATE())' => ['<=', $before]]],
                ],
            ]);

            foreach ($iterator as $data) {
                self::handleCertificate($data, $entity, $cfg, $thresholds, $report, $dryRun, $task);
            }
        }

        if ($task !== null && !$dryRun) {
            $task->addVolume($report['created'] + $report['followups'] + $report['resolved']);
        }

        return $report;
    }

    /**
     * Decide what to do for a single certificate row.
     */
    private static function handleCertificate(
        array $data,
        int $entity,
        array $cfg,
        array $thresholds,
        array &$report,
        bool $dryRun,
        ?CronTask $task
    ): void {
        $certificate = new Certificate();
        if (!$certificate->getFromDB((int) $data['id'])) {
            return;
        }
        $daysLeft = self::daysLeft($data['date_expiration']);

        // Existing ticket?
        if (!empty($data['ticket_id'])) {
            $ticket = new Ticket();
            if ($ticket->getFromDB((int) $data['ticket_id'])) {
                $isOpen = !in_array(
                    (int) $ticket->fields['status'],
                    [CommonITILObject::SOLVED, CommonITILObject::CLOSED],
                    true
                );

                if ($isOpen) {
                    if ($cfg['reuse_open_ticket'] && $cfg['escalation']) {
                        self::maybeEscalate($certificate, $ticket, $data, $daysLeft, $thresholds, $report, $dryRun);
                    }
                    return;
                }

                // Ticket is closed/solved: only start a new cycle if the expiration changed
                if ((string) $data['track_date'] === (string) $certificate->fields['date_expiration']) {
                    return;
                }
            }
        }

        // No usable ticket -> create one
        self::createTicket($certificate, $entity, $cfg, $thresholds, $daysLeft, (int) ($data['track_id'] ?? 0), $report, $dryRun);
    }

    /**
     * Add an escalating followup when a new threshold is crossed.
     */
    private static function maybeEscalate(
        Certificate $certificate,
        Ticket $ticket,
        array $data,
        int $daysLeft,
        array $thresholds,
        array &$report,
        bool $dryRun
    ): void {
        global $DB;

        $stage = self::tightestThreshold($daysLeft, $thresholds);
        // 0 / null both mean "nothing announced yet"
        $notified = ($data['notified_days'] === null || (int) $data['notified_days'] <= 0)
            ? null
            : (int) $data['notified_days'];

        if ($stage === null || ($notified !== null && $stage >= $notified)) {
            return; // nothing new to announce
        }

        $report['followups']++;
        $report['preview'][] = [
            'type'        => 'followup',
            'certificate' => $certificate->fields['name'],
            'ticket'      => $ticket->getID(),
            'days'        => $daysLeft,
        ];

        if ($dryRun) {
            return;
        }

        $fup = new ITILFollowup();
        $fup->add([
            'itemtype' => 'Ticket',
            'items_id' => $ticket->getID(),
            'content'  => self::buildFollowupContent($certificate, $daysLeft),
        ]);

        $DB->update(
            self::getTable(),
            ['notified_days' => $stage],
            ['id' => (int) $data['track_id']]
        );
    }

    /**
     * Create a new ticket for an expiring certificate.
     */
    private static function createTicket(
        Certificate $certificate,
        int $entity,
        array $cfg,
        array $thresholds,
        int $daysLeft,
        int $trackId,
        array &$report,
        bool $dryRun
    ): void {
        global $DB;

        $report['created']++;
        $report['preview'][] = [
            'type'        => 'ticket',
            'certificate' => $certificate->fields['name'],
            'days'        => $daysLeft,
        ];

        if ($dryRun) {
            return;
        }

        [, , , , $autoUrgency] = self::getStatus($daysLeft);

        $tkt = [
            'name'        => self::buildTitle($certificate),
            'content'     => self::buildTicketContent($certificate, $daysLeft),
            'entities_id' => $entity,
            'type'        => (int) $cfg['type'],
            'urgency'     => (int) $cfg['urgency'] > 0 ? (int) $cfg['urgency'] : $autoUrgency,
            'status'      => Ticket::INCOMING,
        ];
        if ((int) $cfg['itilcategories_id'] > 0) {
            $tkt['itilcategories_id'] = (int) $cfg['itilcategories_id'];
        }
        if ((int) $cfg['requesttypes_id'] > 0) {
            $tkt['requesttypes_id'] = (int) $cfg['requesttypes_id'];
        }

        // Assignment: plugin config first, certificate tech fields as fallback
        if ((int) $cfg['groups_id_assign'] > 0) {
            $tkt['_groups_id_assign'] = (int) $cfg['groups_id_assign'];
        } elseif (!empty($certificate->fields['groups_id_tech'])) {
            $tkt['_groups_id_assign'] = (int) $certificate->fields['groups_id_tech'];
        }
        if ((int) $cfg['users_id_assign'] > 0) {
            $tkt['_users_id_assign'] = (int) $cfg['users_id_assign'];
        } elseif (!empty($certificate->fields['users_id_tech'])) {
            $tkt['_users_id_assign'] = (int) $certificate->fields['users_id_tech'];
        }
        if (!empty($certificate->fields['groups_id'])) {
            $tkt['_groups_id_observer'] = (int) $certificate->fields['groups_id'];
        }

        $ticket = new Ticket();
        $ticket_id = $ticket->add($tkt);

        if (!$ticket_id) {
            $report['created']--;
            $report['errors']++;
            trigger_error(sprintf('CertificateTicket: unable to create ticket for certificate #%d', $certificate->getID()), E_USER_WARNING);
            return;
        }

        $stage = self::tightestThreshold($daysLeft, $thresholds);

        if ($trackId > 0) {
            $DB->update(self::getTable(), [
                'ticket_id'     => $ticket_id,
                'date'          => $certificate->fields['date_expiration'],
                'notified_days' => $stage ?? 0,
            ], ['id' => $trackId]);
        } else {
            $DB->insert(self::getTable(), [
                'certificate_id' => $certificate->getID(),
                'ticket_id'      => $ticket_id,
                'date'           => $certificate->fields['date_expiration'],
                'notified_days'  => $stage ?? 0,
            ]);
        }

        // Link the ticket to the certificate asset
        if ($cfg['link_certificate']) {
            $link = new Item_Ticket();
            $link->add([
                'itemtype'   => 'Certificate',
                'items_id'   => $certificate->getID(),
                'tickets_id' => $ticket_id,
            ]);
        }

        // Email the certificate's tech / group in charge (uses the core Certificate target)
        try {
            \NotificationEvent::raiseEvent(self::NOTIF_EVENT, $certificate, ['ticket_id' => $ticket_id]);
        } catch (\Throwable $e) {
            trigger_error('CertificateTicket: notification failed - ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * Resolve open tickets whose certificate was renewed (or removed).
     */
    private static function resolveRenewed(array $cfg, int $maxTh, array &$report, bool $dryRun, ?CronTask $task): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_plugin_certificate_ticket.id AS track_id',
                'glpi_plugin_certificate_ticket.ticket_id',
                'glpi_plugin_certificate_ticket.date AS track_date',
                'glpi_certificates.id AS certificate_id',
                'glpi_certificates.name',
                'glpi_certificates.date_expiration',
                'glpi_certificates.is_deleted',
                'glpi_tickets.status',
            ],
            'FROM'      => 'glpi_plugin_certificate_ticket',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'FKEY' => ['glpi_plugin_certificate_ticket' => 'ticket_id', 'glpi_tickets' => 'id'],
                ],
            ],
            'LEFT JOIN' => [
                'glpi_certificates' => [
                    'FKEY' => ['glpi_plugin_certificate_ticket' => 'certificate_id', 'glpi_certificates' => 'id'],
                ],
            ],
            'WHERE'     => [
                'glpi_plugin_certificate_ticket.ticket_id' => ['>', 0],
                'NOT' => ['glpi_tickets.status' => [CommonITILObject::SOLVED, CommonITILObject::CLOSED]],
            ],
        ]);

        foreach ($iterator as $row) {
            $renewed = false;
            if ((int) $row['is_deleted'] === 1 || $row['date_expiration'] === null) {
                $renewed = true;
            } else {
                $daysLeft = self::daysLeft($row['date_expiration']);
                if ($daysLeft > $maxTh && (string) $row['track_date'] !== (string) $row['date_expiration']) {
                    $renewed = true;
                }
            }
            if (!$renewed) {
                continue;
            }

            $report['resolved']++;
            $report['preview'][] = ['type' => 'resolve', 'certificate' => $row['name'] ?? '#' . $row['certificate_id'], 'ticket' => $row['ticket_id']];

            if ($dryRun) {
                continue;
            }

            $sol = new ITILSolution();
            $sol->add([
                'itemtype' => 'Ticket',
                'items_id' => (int) $row['ticket_id'],
                'content'  => self::buildSolutionContent($row['name'] ?? ''),
            ]);

            if ($row['date_expiration'] !== null) {
                $DB->update(self::getTable(), [
                    'date'          => $row['date_expiration'],
                    'notified_days' => 0,
                ], ['id' => (int) $row['track_id']]);
            }
        }
    }

    /* ===================================================================
     *  Helpers
     * =================================================================== */

    /** Parse a "30,15,7,1" string into a sorted unique int array (desc). */
    private static function parseThresholds(string $raw): array
    {
        $vals = array_filter(array_map('intval', array_map('trim', explode(',', $raw))), fn($v) => $v > 0);
        $vals = array_values(array_unique($vals));
        rsort($vals);
        return $vals;
    }

    /** Tightest threshold already crossed (smallest T with daysLeft <= T), or null. */
    private static function tightestThreshold(int $daysLeft, array $thresholds): ?int
    {
        $best = null;
        foreach ($thresholds as $t) {
            if ($daysLeft <= $t && ($best === null || $t < $best)) {
                $best = $t;
            }
        }
        return $best;
    }

    /** Signed days from today to the expiration date (negative = expired). */
    public static function daysLeft(string $expiration): int
    {
        return (int) (new \DateTime('today'))->diff(new \DateTime($expiration))->format('%r%a');
    }

    /**
     * Visual status for a number of days left.
     *
     * @return array{0:string,1:string,2:string,3:string,4:int,5:string}
     *               [emoji, label, accent, accentBg, urgency, badgeVariant]
     */
    public static function getStatus(int $daysLeft): array
    {
        if ($daysLeft < 0) {
            return ['🔴', sprintf(__('Expired %d day(s) ago', 'certificateticket'), abs($daysLeft)), '#dc2626', '#fee2e2', 5, 'expired'];
        }
        if ($daysLeft <= 7) {
            return ['🟠', sprintf(__('Expires in %d day(s)', 'certificateticket'), $daysLeft), '#ea580c', '#ffedd5', 4, 'soon'];
        }
        if ($daysLeft <= 30) {
            return ['🟡', sprintf(__('Expires in %d day(s)', 'certificateticket'), $daysLeft), '#ca8a04', '#fef9c3', 3, 'warn'];
        }
        return ['🟢', sprintf(__('Expires in %d day(s)', 'certificateticket'), $daysLeft), '#16a34a', '#dcfce7', 2, 'ok'];
    }

    /** Plain-text ticket title. */
    private static function buildTitle(Certificate $certificate): string
    {
        return "🔒 " . __('Digital certificate expiring', 'certificateticket') . " - " . $certificate->fields['name']
            . (!empty($certificate->fields['serial']) ? ' (' . $certificate->fields['serial'] . ')' : '');
    }

    /**
     * Build the rich HTML body of the ticket (rendered by GLPI rich text).
     */
    public static function buildTicketContent(Certificate $certificate, int $daysLeft): string
    {
        [$emoji, $statusText, $accent, $accentBg] = self::getStatus($daysLeft);

        $typeName = (int) $certificate->fields['certificatetypes_id'] > 0
            ? Dropdown::getDropdownName('glpi_certificatetypes', $certificate->fields['certificatetypes_id'])
            : '';
        $dns = trim(($certificate->fields['dns_name'] ?? '') . ' ' . ($certificate->fields['dns_suffix'] ?? ''));

        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $row = function (string $emoji, string $label, string $value) use ($h): string {
            if (trim($value) === '') {
                return '';
            }
            return "<tr>"
                . "<td style='padding:9px 14px;border-bottom:1px solid #eceef3;color:#6b7280;white-space:nowrap;vertical-align:top;font-size:13px;'>"
                . $emoji . " <strong>" . $h($label) . "</strong></td>"
                . "<td style='padding:9px 14px;border-bottom:1px solid #eceef3;color:#111827;font-size:13px;'>" . $h($value) . "</td>"
                . "</tr>";
        };

        return ""
            . "<div style='max-width:680px;margin:0 auto;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;'>"

            . "<div style='background:linear-gradient(125deg,#064e3b 0%,#0f766e 60%,#10b981 100%);color:#ffffff;padding:18px 20px;'>"
            . "<div style='font-size:18px;font-weight:700;'>🔒 " . $h(__('Digital certificate renewal', 'certificateticket')) . "</div>"
            . "<div style='font-size:13px;opacity:.85;margin-top:2px;'>" . $h(__('Action required to keep services and communications secure', 'certificateticket')) . "</div>"
            . "</div>"

            . "<div style='padding:16px 20px 4px 20px;'>"
            . "<span style='display:inline-block;background:" . $accentBg . ";color:" . $accent . ";border:1px solid " . $accent . ";"
            . "border-radius:999px;padding:6px 14px;font-size:13px;font-weight:700;'>" . $emoji . " " . $h($statusText) . "</span>"
            . "</div>"

            . "<div style='padding:14px 20px 4px 20px;'>"
            . "<div style='font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8;font-weight:700;margin-bottom:6px;'>📋 " . $h(__('Certificate details', 'certificateticket')) . "</div>"
            . "<table style='width:100%;border-collapse:collapse;background:#ffffff;'>"
            . $row('🏷️', __('Name', 'certificateticket'), $certificate->fields['name'])
            . $row('🔢', __('Serial', 'certificateticket'), $certificate->fields['serial'] ?? '')
            . $row('🧾', __('Inventory number', 'certificateticket'), $certificate->fields['otherserial'] ?? '')
            . $row('📜', __('Type', 'certificateticket'), $typeName)
            . $row('🌐', 'DNS', $dns)
            . $row('📅', __('Expiration date', 'certificateticket'), Html::convDate($certificate->fields['date_expiration']))
            . $row('⏳', __('Days remaining', 'certificateticket'), $statusText)
            . $row('👤', __('Contact', 'certificateticket'), $certificate->fields['contact'] ?? '')
            . "</table>"
            . "</div>"

            . "<div style='padding:14px 20px 4px 20px;'>"
            . "<div style='font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8;font-weight:700;margin-bottom:6px;'>✅ " . $h(__('Next steps', 'certificateticket')) . "</div>"
            . "<ol style='margin:0;padding-left:20px;color:#111827;font-size:13px;line-height:1.7;'>"
            . "<li>" . $h(__('Check the current certificate validity', 'certificateticket')) . "</li>"
            . "<li>" . $h(__('Start the renewal with the certificate authority', 'certificateticket')) . "</li>"
            . "<li>" . $h(__('Update the certificate on the affected systems', 'certificateticket')) . "</li>"
            . "<li>" . $h(__('Validate operation after the update', 'certificateticket')) . "</li>"
            . "</ol>"
            . "</div>"

            . "<div style='margin-top:12px;padding:12px 20px;background:#f8fafc;border-top:1px solid #e5e7eb;color:#94a3b8;font-size:12px;'>"
            . "📌 " . $h(__('Ticket generated automatically by the certificate monitoring system.', 'certificateticket'))
            . "</div>"

            . "</div>";
    }

    /** Compact HTML body for an escalation followup. */
    private static function buildFollowupContent(Certificate $certificate, int $daysLeft): string
    {
        [$emoji, $statusText, $accent, $accentBg] = self::getStatus($daysLeft);
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return "<div style='font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:13px;'>"
            . "<span style='display:inline-block;background:" . $accentBg . ";color:" . $accent . ";border:1px solid " . $accent . ";"
            . "border-radius:999px;padding:5px 12px;font-weight:700;'>" . $emoji . " " . $h($statusText) . "</span>"
            . "<p style='margin:10px 0 0;color:#111827;'>⏰ " . $h(sprintf(
                __('Reminder: the certificate "%s" is getting closer to its expiration date. Please proceed with the renewal.', 'certificateticket'),
                $certificate->fields['name']
            )) . "</p>"
            . "</div>";
    }

    /** HTML body for the auto-resolution. */
    private static function buildSolutionContent(string $certName): string
    {
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return "<div style='font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#111827;'>"
            . "✅ <strong>" . $h(sprintf(__('Certificate "%s" renewed', 'certificateticket'), $certName)) . "</strong><br>"
            . $h(__('The expiration date is no longer imminent. This ticket was resolved automatically by the certificate monitoring system.', 'certificateticket'))
            . "</div>";
    }

    /* ===================================================================
     *  Statistics
     * =================================================================== */

    /**
     * Global counters for the config dashboard.
     *
     * @return array{expired:int,d7:int,d30:int,d90:int,tickets:int}
     */
    public static function getGlobalStats(): array
    {
        global $DB;

        $countCert = function (string $rawCond) use ($DB): int {
            $res = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_certificates',
                'WHERE' => [
                    'is_deleted'  => 0,
                    'is_template' => 0,
                    ['NOT' => ['date_expiration' => null]],
                    ['RAW' => [$rawCond => null]],
                ],
            ]);
            return (int) ($res->current()['cpt'] ?? 0);
        };

        // Build conditions via RAW with embedded comparisons
        $expired = (int) ($DB->request([
            'COUNT' => 'cpt', 'FROM' => 'glpi_certificates',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, ['NOT' => ['date_expiration' => null]],
                ['RAW' => ['DATEDIFF(`date_expiration`, CURDATE())' => ['<', 0]]]],
        ])->current()['cpt'] ?? 0);

        $d7 = (int) ($DB->request([
            'COUNT' => 'cpt', 'FROM' => 'glpi_certificates',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, ['NOT' => ['date_expiration' => null]],
                ['RAW' => ['DATEDIFF(`date_expiration`, CURDATE())' => ['>=', 0]]],
                ['RAW' => ['DATEDIFF(`date_expiration`, CURDATE())' => ['<=', 7]]]],
        ])->current()['cpt'] ?? 0);

        $d30 = (int) ($DB->request([
            'COUNT' => 'cpt', 'FROM' => 'glpi_certificates',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, ['NOT' => ['date_expiration' => null]],
                ['RAW' => ['DATEDIFF(`date_expiration`, CURDATE())' => ['>=', 0]]],
                ['RAW' => ['DATEDIFF(`date_expiration`, CURDATE())' => ['<=', 30]]]],
        ])->current()['cpt'] ?? 0);

        $d90 = (int) ($DB->request([
            'COUNT' => 'cpt', 'FROM' => 'glpi_certificates',
            'WHERE' => ['is_deleted' => 0, 'is_template' => 0, ['NOT' => ['date_expiration' => null]],
                ['RAW' => ['DATEDIFF(`date_expiration`, CURDATE())' => ['>=', 0]]],
                ['RAW' => ['DATEDIFF(`date_expiration`, CURDATE())' => ['<=', 90]]]],
        ])->current()['cpt'] ?? 0);

        $tickets = (int) ($DB->request([
            'COUNT' => 'cpt', 'FROM' => self::getTable(),
            'WHERE' => ['ticket_id' => ['>', 0]],
        ])->current()['cpt'] ?? 0);

        unset($countCert); // not used (kept for clarity of intent)

        return ['expired' => $expired, 'd7' => $d7, 'd30' => $d30, 'd90' => $d90, 'tickets' => $tickets];
    }

    /* ===================================================================
     *  Certificate "Generated tickets" tab
     * =================================================================== */

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Certificate') {
            $count = 0;
            global $DB;
            $res = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => self::getTable(),
                'WHERE' => ['certificate_id' => $item->getID(), 'ticket_id' => ['>', 0]],
            ]);
            $count = (int) ($res->current()['cpt'] ?? 0);
            return self::createTabEntry(__('Generated tickets', 'certificateticket'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === 'Certificate') {
            self::showForCertificate($item);
        }
        return true;
    }

    /**
     * Render the list of generated tickets on a certificate.
     */
    public static function showForCertificate(Certificate $certificate): void
    {
        global $DB;

        $daysLeft = $certificate->fields['date_expiration']
            ? self::daysLeft($certificate->fields['date_expiration'])
            : null;

        echo "<div class='certticket'>";
        echo "<div class='ct-panel'>";
        echo "<div class='ct-panel__head'>";
        echo "<span class='ct-panel__icon'><i class='ti ti-ticket'></i></span>";
        echo "<div class='ct-panel__title'>" . __('Generated tickets', 'certificateticket');
        if ($daysLeft !== null) {
            [$emoji, $statusText, , , , $variant] = self::getStatus($daysLeft);
            echo "<small><span class='ct-badge ct-badge--$variant'>" . $emoji . " " . $statusText . "</span></small>";
        }
        echo "</div></div>";
        echo "<div class='ct-panel__body'>";

        $iterator = $DB->request([
            'SELECT'   => [
                'glpi_plugin_certificate_ticket.date AS track_date',
                'glpi_tickets.id',
                'glpi_tickets.name',
                'glpi_tickets.status',
                'glpi_tickets.date AS tdate',
            ],
            'FROM'      => self::getTable(),
            'LEFT JOIN' => [
                'glpi_tickets' => [
                    'FKEY' => [self::getTable() => 'ticket_id', 'glpi_tickets' => 'id'],
                ],
            ],
            'WHERE'    => [self::getTable() . '.certificate_id' => $certificate->getID(), self::getTable() . '.ticket_id' => ['>', 0]],
            'ORDER'    => 'glpi_tickets.date DESC',
        ]);

        if (!count($iterator)) {
            echo "<div class='ct-empty'><i class='ti ti-ticket-off'></i>" . __('No ticket generated for this certificate yet.', 'certificateticket') . "</div>";
            echo "</div></div></div>";
            return;
        }

        echo "<table class='ct-table'>";
        echo "<thead><tr>";
        echo "<th>" . __('Ticket') . "</th>";
        echo "<th>" . __('Title') . "</th>";
        echo "<th>" . __('Status') . "</th>";
        echo "<th>" . __('Opened on', 'certificateticket') . "</th>";
        echo "</tr></thead><tbody>";

        foreach ($iterator as $t) {
            if (empty($t['id'])) {
                continue;
            }
            $status   = (int) $t['status'];
            $isClosed = in_array($status, [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true);
            $badge    = $isClosed ? 'ct-badge--ok' : 'ct-badge--soon';
            $statusLabel = Ticket::getStatus($status);

            echo "<tr>";
            echo "<td><a href='" . Ticket::getFormURLWithID((int) $t['id']) . "'><i class='ti ti-hash'></i>" . (int) $t['id'] . "</a></td>";
            echo "<td>" . htmlspecialchars((string) $t['name']) . "</td>";
            echo "<td><span class='ct-badge $badge'>" . htmlspecialchars($statusLabel) . "</span></td>";
            echo "<td>" . Html::convDateTime($t['tdate']) . "</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";
        echo "</div></div></div>";
    }

    /* ===================================================================
     *  Housekeeping (purge hooks)
     * =================================================================== */

    /** Remove tracking rows when a ticket is purged. */
    public static function cleanupTicket(Ticket $ticket): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['ticket_id' => $ticket->getID()]);
    }

    /** Remove tracking rows when a certificate is purged. */
    public static function cleanupCertificate(Certificate $certificate): void
    {
        global $DB;
        $DB->delete(self::getTable(), ['certificate_id' => $certificate->getID()]);
    }
}
