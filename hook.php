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
use Dropdown as GlpiDropdown;


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
   if (!$DB->tableExists("glpi_plugin_certificate_ticket")) {
      $table = 'glpi_plugin_certificate_ticket';

      $migration->displayMessage("Creating table $table");

      $query = "CREATE TABLE IF NOT EXISTS `$table` (
                  `id` int {$default_key_sign} NOT NULL auto_increment,
                  `certificate_id` int NOT NULL DEFAULT 0,
                  `ticket_id` int NOT NULL DEFAULT 0,
                  `date` date DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `certificate_id` (`certificate_id`),
                KEY `ticket_id` (`ticket_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

      $DB->doQuery($query) or die("Error creating $table: " . $DB->error());
   }



   // To be called for each task the plugin manage
   // task in class
   CronTask::Register(CertificateTicket::class, 'CertificateTicket', DAY_TIMESTAMP, ['param' => 50]);
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

   // We notify what is done when uninstall
   $notif = new Notification();
   $options = [
      'itemtype' => 'Ticket',
      'event' => 'plugin_certificate_ticket',
      'FIELDS' => 'id'
   ];
   foreach ($DB->request('glpi_notifications', $options) as $data) {
      $notif->delete($data);
   }
   return true;
}


// Nothing to do here
function plugin_certificateticket_postinit()
{
   global $CFG_GLPI;

}


