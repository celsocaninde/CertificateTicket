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
use Glpi\Plugin\Hooks;
use GlpiPlugin\Certificateticket\CertificateTicket;
use GlpiPlugin\Certificateticket\PluginCertificateticketConfig;


define('PLUGIN_CERTIFICATETICKET_VERSION', '0.1.1');

// Minimal GLPI version, inclusive
define('PLUGIN_CERTIFICATETICKET_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_CERTIFICATETICKET_MAX_GLPI', '11.0.99');

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_certificateticket()
{
   global $PLUGIN_HOOKS, $CFG_GLPI;

   $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['certificateticket'] = true;

   // Branded stylesheet for the config tab and the certificate tab (scoped to .certticket / .ct-*)
   $PLUGIN_HOOKS[Hooks::ADD_CSS]['certificateticket'] = ['public/css/certificateticket.css'];

   // Core class: cron + "Generated tickets" tab on the Certificate form
   Plugin::registerClass(
      CertificateTicket::class,
      [
         'notificationtemplates_types' => true,
         'addtabon'                    => 'Certificate',
      ]
   );

   // Configuration tab (Setup > General) + dedicated config page entry
   if (Session::haveRightsOr('config', [READ, UPDATE])) {
      Plugin::registerClass(PluginCertificateticketConfig::class, ['addtabon' => 'Config']);
      $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['certificateticket'] = 'front/config.form.php';
   }

   // Housekeeping: drop tracking rows when the linked ticket or certificate is purged
   $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['certificateticket'] = [
      'Ticket'      => [CertificateTicket::class, 'cleanupTicket'],
      'Certificate' => [CertificateTicket::class, 'cleanupCertificate'],
   ];

   // Add our notification event to the core Certificate notification target
   $PLUGIN_HOOKS[Hooks::ITEM_GET_EVENTS]['certificateticket'] = [
      'NotificationTargetCertificate' => [CertificateTicket::class, 'addNotificationEvents'],
   ];

   $PLUGIN_HOOKS[Hooks::POST_INIT]['certificateticket'] = 'plugin_certificateticket_postinit';
}


/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array
 */
function plugin_version_certificateticket()
{
   return [
      'name' => 'Certificate Ticket',
      'version' => PLUGIN_CERTIFICATETICKET_VERSION,
      'author' => 'ADZ',
      'license' => 'GPLv2+',
      'homepage' => '',
      'requirements' => [
         'glpi' => [
            'min' => PLUGIN_CERTIFICATETICKET_MIN_GLPI,
            'max' => PLUGIN_CERTIFICATETICKET_MAX_GLPI,
         ]
      ]
   ];
}


/**
 * Check pre-requisites before install
 * OPTIONNAL, but recommanded
 *
 * @return boolean
 */
function plugin_certificateticket_check_prerequisites()
{
   if (false) {
      return false;
   }
   return true;
}

/**
 * Check configuration process
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 *
 * @return boolean
 */
function plugin_certificateticket_check_config($verbose = false)
{
   if (true) { // Your configuration check
      return true;
   }

   if ($verbose) {
      echo __('Installed / not configured', 'example');
   }
   return false;
}
