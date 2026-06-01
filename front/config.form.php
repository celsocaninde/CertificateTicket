<?php

/**
 * -------------------------------------------------------------------------
 * Certificate Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Config page entry point: focuses the plugin tab inside the GLPI
 * Setup > General configuration page. The settings themselves are persisted
 * by the core Config form (config_context = plugin:certificateticket).
 */

global $CFG_GLPI;

Session::checkRight('config', READ);

Session::setActiveTab('Config', 'GlpiPlugin\Certificateticket\PluginCertificateticketConfig$1');
Html::redirect($CFG_GLPI['root_doc'] . '/front/config.form.php');
