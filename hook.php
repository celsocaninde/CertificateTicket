<?php

/**
 * -------------------------------------------------------------------------
 * Certificate Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * This Plugin was developped to add the functionnality to create ticket when certificate will expire
 */


require_once(__DIR__ . "/src/CertificateTicket.php");
require_once(__DIR__ . "/src/Config.php");
use GlpiPlugin\Certificateticket\CertificateTicket;
use GlpiPlugin\Certificateticket\PluginCertificateticketConfig;


/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_certificateticket_install()
{

   //We get the global db acces
   global $DB;

   /**
    * May be used later
    */
   //$migration = new Migration(PLUGIN_CERTIFICATETICKET_VERSION);
   //PluginCertificateticketConfig::setConfigurationValues('CertificateTicket', ['configuration' => false]);

   //Used to configure the good parameters of the DB
   $default_charset = DBConnection::getDefaultCharset();
   $default_collation = DBConnection::getDefaultCollation();
   $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

   // Use Migration API for GLPI 11 compatibility
   $migration = new Migration(PLUGIN_CERTIFICATETICKET_VERSION);

   // We create a table that'll stock if ticket was already created, to create only 1 ticket
   $table = 'glpi_plugin_certificate_ticket';
   if (!$DB->tableExists($table)) {

      $migration->displayMessage("Creating table $table");

      $query = "CREATE TABLE IF NOT EXISTS `$table` (
                  `id` int {$default_key_sign} NOT NULL auto_increment,
                  `certificate_id` int unsigned NOT NULL DEFAULT 0,
                  `ticket_id` int unsigned NOT NULL DEFAULT 0,
                  `date` date DEFAULT NULL,
                  `notified_days` int NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `certificate_id` (`certificate_id`),
                KEY `ticket_id` (`ticket_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

      $DB->doQuery($query) or die("Error creating $table: " . $DB->error());
   } else {
      // v0.1.0 migration: track which escalation threshold was already notified
      if (!$DB->fieldExists($table, 'notified_days')) {
         $migration->addField($table, 'notified_days', 'integer', ['value' => 0, 'after' => 'date']);
         $migration->migrationOneTable($table);
      }
   }

   // Default configuration values (only the missing keys are written)
   $current = Config::getConfigurationValues(PluginCertificateticketConfig::CONFIG_CONTEXT);
   $defaults = array_diff_key(PluginCertificateticketConfig::getDefaults(), is_array($current) ? $current : []);
   if (!empty($defaults)) {
      Config::setConfigurationValues(PluginCertificateticketConfig::CONFIG_CONTEXT, $defaults);
   }

   // Register the daily cron task (idempotent). The look-ahead window is driven
   // by the plugin configuration (entity delay or "days_before"), not a cron param.
   CronTask::Register(CertificateTicket::class, 'CertificateTicket', DAY_TIMESTAMP);

   // Install the e-mail notification (idempotent). It targets the certificate's
   // technician / group in charge using the core Certificate notification target.
   if (countElementsInTable('glpi_notifications', ['itemtype' => 'Certificate', 'event' => CertificateTicket::NOTIF_EVENT]) === 0) {

      $body_html = "<div style=\"font-family:Segoe UI,Roboto,Arial,sans-serif\">"
         . "<p>🔒 <strong>" . __('A ticket was opened for an expiring certificate', 'certificateticket') . "</strong></p>"
         . "<ul>"
         . "<li>" . __('Name', 'certificateticket') . ": <strong>##certificate.name##</strong></li>"
         . "<li>" . __('Serial', 'certificateticket') . ": ##certificate.serial##</li>"
         . "<li>" . __('Type', 'certificateticket') . ": ##certificate.type##</li>"
         . "<li>" . __('Expiration date', 'certificateticket') . ": <strong>##certificate.expirationdate##</strong></li>"
         . "</ul>"
         . "<p><a href=\"##certificate.url##\">##certificate.url##</a></p>"
         . "</div>";
      $body_text = "##certificate.name## (##certificate.serial##) - ##certificate.expirationdate##\n##certificate.url##";

      $template = new NotificationTemplate();
      $templates_id = $template->add([
         'name'     => 'Certificate Ticket - Expiration',
         'itemtype' => 'Certificate',
         'comment'  => 'Plugin Certificate Ticket',
      ]);

      if ($templates_id) {
         $translation = new NotificationTemplateTranslation();
         $translation->add([
            'notificationtemplates_id' => $templates_id,
            'language'     => '',
            'subject'      => __('Expiring certificate', 'certificateticket') . ': ##certificate.name##',
            'content_text' => $body_text,
            'content_html' => $body_html,
         ]);

         $notification = new Notification();
         $notifications_id = $notification->add([
            'name'         => 'Certificate Ticket - Expiration',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'is_active'    => 1,
            'itemtype'     => 'Certificate',
            'event'        => CertificateTicket::NOTIF_EVENT,
            'comment'      => '',
         ]);

         if ($notifications_id) {
            $nnt = new Notification_NotificationTemplate();
            $nnt->add([
               'notifications_id'         => $notifications_id,
               'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
               'notificationtemplates_id' => $templates_id,
            ]);

            // tech in charge (5) + group in charge (23), USER_TYPE = 1 (same as core "Certificates")
            $ntarget = new NotificationTarget();
            foreach ([Notification::ITEM_TECH_IN_CHARGE, Notification::ITEM_TECH_GROUP_IN_CHARGE] as $target_item) {
               $ntarget->add([
                  'notifications_id' => $notifications_id,
                  'type'             => Notification::USER_TYPE,
                  'items_id'         => $target_item,
               ]);
            }
         }
      }
   }

   return true;
}


/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_certificateticket_uninstall()
{
   global $DB;

   // We drop the table when we uninstall the plugin
   if ($DB->tableExists("glpi_plugin_certificate_ticket")) {
      $table = 'glpi_plugin_certificate_ticket';
      $query = "DROP TABLE IF EXISTS `$table`";
      $DB->doQuery($query) or die("Error deleting $table: " . $DB->error());
   }

   // May be used later
   //$config = new PluginCertificateticketConfig();
   //$config->deleteConfigurationValues('CertificateTicket', ['configuration' => false]);

   // Remove the notification, its template and translations
   $notif = new Notification();
   foreach ($DB->request('glpi_notifications', ['itemtype' => 'Certificate', 'event' => CertificateTicket::NOTIF_EVENT]) as $data) {
      $notif->delete(['id' => $data['id']], true);
   }
   $template = new NotificationTemplate();
   foreach ($DB->request('glpi_notificationtemplates', ['itemtype' => 'Certificate', 'name' => 'Certificate Ticket - Expiration']) as $data) {
      $template->delete(['id' => $data['id']], true);
   }

   return true;
}


// Nothing to do here
function plugin_certificateticket_postinit()
{
   global $CFG_GLPI;

}


