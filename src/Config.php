<?php

namespace GlpiPlugin\Certificateticket;

use CommonDBTM;
use CommonGLPI;
use Config;
use Dropdown;
use Group;
use Html;
use ITILCategory;
use RequestType;
use Session;
use Toolbox;
use User;

/**
 * -------------------------------------------------------------------------
 * Certificate Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Configuration tab (Setup > General > Certificate Ticket) with a branded
 * settings form and a small statistics panel.
 */
class PluginCertificateticketConfig extends CommonDBTM
{
    public const CONFIG_CONTEXT = 'plugin:certificateticket';

    public static $rightname = 'config';

    /**
     * Default configuration values.
     *
     * @return array<string,mixed>
     */
    public static function getDefaults(): array
    {
        return [
            'itilcategories_id' => 0,
            'type'              => 1,   // 1 = Incident, 2 = Request
            'urgency'           => 0,   // 0 = automatic (based on days left), 1..5 = fixed
            'requesttypes_id'   => 0,
            'groups_id_assign'  => 0,
            'users_id_assign'   => 0,
            'use_entity_delay'  => 1,   // use entity "certificates alert" delay
            'days_before'       => 50,  // plugin delay (used when use_entity_delay = 0)
            'reuse_open_ticket' => 1,   // add followup instead of a new ticket when one is open
            'escalation'        => 1,   // add escalating followups
            'escalation_days'   => '30,15,7,1',
            'auto_resolve'      => 1,   // solve ticket once the certificate is renewed
            'link_certificate'  => 1,   // link the ticket to the certificate asset
        ];
    }

    /**
     * Get the merged configuration (stored values over defaults).
     *
     * @return array<string,mixed>
     */
    public static function getConfig(): array
    {
        $stored = Config::getConfigurationValues(self::CONFIG_CONTEXT);
        return array_merge(self::getDefaults(), is_array($stored) ? $stored : []);
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Certificate Ticket', 'certificateticket');
    }

    public static function getIcon(): string
    {
        return 'ti ti-certificate';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item->getType() === 'Config') {
            return "<span class='d-flex align-items-center'><i class='ti ti-certificate me-2'></i>"
                . __('Certificate Ticket', 'certificateticket') . "</span>";
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() === 'Config') {
            self::showConfigForm();
        }
        return true;
    }

    /**
     * Render the branded configuration form + statistics.
     *
     * @return void
     */
    public static function showConfigForm(): void
    {
        if (!Session::haveRight('config', READ)) {
            return;
        }

        $cfg = self::getConfig();

        echo "<div class='certticket'>";

        // ---- Hero ----
        echo "<div class='ct-hero'>";
        echo "<div class='ct-hero__brand'>";
        echo "<span class='ct-logo'><i class='ti ti-certificate'></i></span>";
        echo "<div>";
        echo "<div class='ct-hero__eyebrow'>GLPI &middot; " . __('Certificate Ticket', 'certificateticket') . "</div>";
        echo "<h2 class='ct-hero__title'>" . __('Automatic tickets for expiring certificates', 'certificateticket') . "</h2>";
        echo "<p class='ct-hero__subtitle'>" . __('Monitors your digital certificates and opens a ticket before they expire.', 'certificateticket') . "</p>";
        echo "</div></div>";
        echo "<span class='ct-hero__status'><i class='ti ti-shield-check'></i> v" . PLUGIN_CERTIFICATETICKET_VERSION . "</span>";
        echo "</div>";

        // ---- Stats KPIs ----
        self::showStats();

        // ---- Settings form ----
        echo "<form name='form' method='post' action='" . Toolbox::getItemTypeFormURL('Config') . "' data-track-changes='true'>";
        echo "<div class='ct-panel'>";
        echo "<div class='ct-panel__head'>";
        echo "<span class='ct-panel__icon'><i class='ti ti-adjustments'></i></span>";
        echo "<div class='ct-panel__title'>" . __('Generated ticket settings', 'certificateticket');
        echo "<small>" . __('How the ticket created for an expiring certificate should look', 'certificateticket') . "</small>";
        echo "</div></div>";
        echo "<div class='ct-panel__body'>";

        echo "<div class='ct-grid'>";

        // Category
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-tag'></i> " . __('Ticket category', 'certificateticket') . "</span>";
        ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => $cfg['itilcategories_id'], 'width' => '100%']);
        echo "</div>";

        // Type
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-ticket'></i> " . __('Ticket type', 'certificateticket') . "</span>";
        Dropdown::showFromArray('type', [
            1 => __('Incident'),
            2 => __('Request'),
        ], ['value' => $cfg['type'], 'width' => '100%']);
        echo "</div>";

        // Urgency
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-flame'></i> " . __('Urgency', 'certificateticket') . "</span>";
        Dropdown::showFromArray('urgency', [
            0 => __('Automatic (based on days left)', 'certificateticket'),
            5 => __('Very high'),
            4 => __('High'),
            3 => __('Medium'),
            2 => __('Low'),
            1 => __('Very low'),
        ], ['value' => $cfg['urgency'], 'width' => '100%']);
        echo "</div>";

        // Request source
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-rss'></i> " . __('Request source') . "</span>";
        RequestType::dropdown(['name' => 'requesttypes_id', 'value' => $cfg['requesttypes_id'], 'width' => '100%']);
        echo "</div>";

        // Assign group
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-users-group'></i> " . __('Assigned group', 'certificateticket') . "</span>";
        Group::dropdown([
            'name'      => 'groups_id_assign',
            'value'     => $cfg['groups_id_assign'],
            'condition' => ['is_assign' => 1],
            'width'     => '100%',
        ]);
        echo "</div>";

        // Assign tech
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-user-cog'></i> " . __('Assigned technician', 'certificateticket') . "</span>";
        User::dropdown([
            'name'  => 'users_id_assign',
            'value' => $cfg['users_id_assign'],
            'right' => 'own_ticket',
            'width' => '100%',
        ]);
        echo "</div>";

        // Use entity delay
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-building'></i> " . __('Use the entity certificate-alert delay', 'certificateticket') . "</span>";
        echo "<span class='ct-field__hint'>" . __('When enabled, the lead time comes from Entity > Assistance > Certificates.', 'certificateticket') . "</span>";
        Dropdown::showYesNo('use_entity_delay', $cfg['use_entity_delay']);
        echo "</div>";

        // Days before
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-clock-hour-4'></i> " . __('Plugin lead time (days)', 'certificateticket') . "</span>";
        echo "<span class='ct-field__hint'>" . __('Used only when the entity delay is disabled.', 'certificateticket') . "</span>";
        echo "<input type='number' min='1' name='days_before' class='form-control' value='" . (int) $cfg['days_before'] . "'>";
        echo "</div>";

        // Reuse open ticket
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-message-plus'></i> " . __('Reuse open ticket (followup)', 'certificateticket') . "</span>";
        echo "<span class='ct-field__hint'>" . __('Add a followup to the existing open ticket instead of creating a new one.', 'certificateticket') . "</span>";
        Dropdown::showYesNo('reuse_open_ticket', $cfg['reuse_open_ticket']);
        echo "</div>";

        // Escalation
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-stairs-up'></i> " . __('Escalating reminders', 'certificateticket') . "</span>";
        echo "<span class='ct-field__hint'>" . __('Add a followup when the certificate crosses each threshold below.', 'certificateticket') . "</span>";
        Dropdown::showYesNo('escalation', $cfg['escalation']);
        echo "</div>";

        // Escalation days
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-calendar-stats'></i> " . __('Reminder thresholds (days)', 'certificateticket') . "</span>";
        echo "<span class='ct-field__hint'>" . __('Comma-separated list, e.g. 30,15,7,1', 'certificateticket') . "</span>";
        echo "<input type='text' name='escalation_days' class='form-control' value='" . Html::entities_deep($cfg['escalation_days']) . "'>";
        echo "</div>";

        // Auto resolve
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-checkup-list'></i> " . __('Auto-resolve when renewed', 'certificateticket') . "</span>";
        echo "<span class='ct-field__hint'>" . __('Solve the open ticket once the certificate expiration moves to the future.', 'certificateticket') . "</span>";
        Dropdown::showYesNo('auto_resolve', $cfg['auto_resolve']);
        echo "</div>";

        // Link certificate
        echo "<div class='ct-field'>";
        echo "<span class='ct-field__label'><i class='ti ti-link'></i> " . __('Link ticket to the certificate', 'certificateticket') . "</span>";
        Dropdown::showYesNo('link_certificate', $cfg['link_certificate']);
        echo "</div>";

        echo "</div>"; // .ct-grid

        echo "<div class='ct-note'>";
        echo "<i class='ti ti-info-circle ct-note__icon'></i>";
        echo "<div class='ct-note__body'><strong>" . __('How it works', 'certificateticket') . "</strong>";
        echo __('A daily cron task checks every certificate. When the expiration date gets close, a richly formatted ticket is opened (and followups/solution are managed automatically).', 'certificateticket');
        echo "</div></div>";

        echo "<div class='ct-actions'>";
        echo "<button type='submit' name='update' class='ct-btn'><i class='ti ti-device-floppy'></i> " . _sx('button', 'Save') . "</button>";
        echo "</div>";

        echo "</div></div>"; // body / panel

        echo "<input type='hidden' name='id' value='1'>";
        echo "<input type='hidden' name='config_context' value='" . self::CONFIG_CONTEXT . "'>";
        Html::closeForm();

        echo "</div>"; // .certticket
    }

    /**
     * Show the KPI statistics tiles.
     *
     * @return void
     */
    public static function showStats(): void
    {
        $stats = CertificateTicket::getGlobalStats();

        $cards = [
            ['expired',   'ti-alert-octagon', __('Expired', 'certificateticket'),      $stats['expired']],
            ['soon',      'ti-clock-exclamation', __('Within 7 days', 'certificateticket'), $stats['d7']],
            ['warn',      'ti-calendar-due', __('Within 30 days', 'certificateticket'), $stats['d30']],
            ['ok',        'ti-calendar', __('Within 90 days', 'certificateticket'),    $stats['d90']],
            ['emerald',   'ti-ticket', __('Tickets generated', 'certificateticket'),   $stats['tickets']],
        ];

        echo "<div class='ct-kpis'>";
        foreach ($cards as [$variant, $icon, $label, $value]) {
            echo "<div class='ct-kpi ct-kpi--$variant'>";
            echo "<div class='ct-kpi__top'><span class='ct-kpi__icon'><i class='ti $icon'></i></span>";
            echo "<span class='ct-kpi__value'>" . (int) $value . "</span></div>";
            echo "<span class='ct-kpi__label'>" . $label . "</span>";
            echo "</div>";
        }
        echo "</div>";
    }
}
