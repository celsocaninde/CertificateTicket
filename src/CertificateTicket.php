<?php

namespace GlpiPlugin\Certificateticket;

use Certificate;
use CommonDBTM;
use Entity;
use Html;
use Ticket;


/**
 * -------------------------------------------------------------------------
 * Certificate Ticket plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * This Plugin was developped to add the functionnality to create ticket when certificate will expire
 */

//We load the needed classes
//use CommonDBTM;
//use Certificates;

// Class of the defined type
class CertificateTicket extends CommonDBTM
{

    // Should return the localized name of the type
    static function getTypeName($nb = 0)
    {
        return 'CertificateTicket';
    }

    /**
     * Give localized information about 1 task
     *
     * @param $name of the task
     *
     * @return array of strings
     */
    static function cronInfo($name)
    {

        switch ($name) {
            case 'CertificateTicket':
                return [
                    'description' => ('Cron description for certificateticket'),
                    'parameter' => ('Cron parameter for certificateticket')
                ];
        }
        return [];
    }


    /**
     *
     * The function that will check expiry then create ticket if needed
     * 
     */
    static function cronCertificateTicket($task)
    {
        global $CFG_GLPI, $DB;

        $errors = 0;
        $total = 0;

        // We check all entities having the notification activated for certificates
        foreach (array_keys(Entity::getEntitiesToNotify('use_certificates_alert')) as $entity) {

            $before = Entity::getUsedConfig('send_certificates_alert_before_delay', $entity);
            $repeat = Entity::getUsedConfig('certificates_alert_repeat_interval', $entity);

            $iterator = $DB->request(
                [
                    'SELECT' => [
                        'glpi_certificates.id',
                        'glpi_certificates.date_expiration',
                        'glpi_certificates.groups_id',
                        'glpi_certificates.users_id_tech',
                        'glpi_certificates.groups_id_tech',
                        'glpi_plugin_certificate_ticket.date'
                    ],
                    'FROM' => 'glpi_certificates',
                    'LEFT JOIN' => [
                        'glpi_plugin_certificate_ticket' => [
                            'FKEY' => [
                                'glpi_plugin_certificate_ticket' => 'certificate_id',
                                'glpi_certificates' => 'id',
                            ]
                        ]
                    ],
                    'WHERE' => [
                        'glpi_certificates.is_deleted' => 0,
                        'glpi_certificates.is_template' => 0,
                        [
                            'NOT' => ['glpi_certificates.date_expiration' => null],
                        ],
                        [
                            'RAW' => [
                                'DATEDIFF(`glpi_certificates`.`date_expiration`, CURDATE())' => ['<', $before]
                            ]
                        ],
                        'glpi_certificates.entities_id' => $entity,
                    ],
                ]
            );

            // We parse all certificate that'll expire soon
            foreach ($iterator as $certificate_data) {

                $certificate_id = $certificate_data['id'];
                $certificate = new Certificate();
                if (!$certificate->getFromDB($certificate_id)) {
                    $errors++;
                    trigger_error(sprintf('Unable to load Certificate "%s".', $certificate_id), E_USER_WARNING);
                    continue;
                }

                // ticket name preparation
                $tktname = addslashes("🔒 Certificado Digital Expirando - " . $certificate->fields['name'] . (!empty($certificate->fields['serial']) ? ' (' . $certificate->fields['serial'] . ')' : ''));

                // ticket options preparation
                $tkt = [];
                $tkt['entities_id'] = $entity;
                $task->log($certificate_data['date_expiration'] . " == " . $certificate_data['date']);
                $tkt['name'] = $tktname;
                $tkt['content'] = "⚠️ ATENÇÃO: Ação Necessária - Renovação de Certificado Digital\n\n" .
                    "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
                    "📋 DETALHES DO CERTIFICADO:\n" .
                    "   • Nome: " . $certificate->fields['name'] . "\n" .
                    (!empty($certificate->fields['serial']) ? "   • Serial: " . $certificate->fields['serial'] . "\n" : "") .
                    "   • Data de Expiração: " . Html::convDate($certificate->fields['date_expiration']) . "\n\n" .
                    "🔔 AÇÃO REQUERIDA:\n" .
                    "Este certificado digital está próximo da data de expiração ou já expirou. " .
                    "Para garantir a continuidade dos serviços e a segurança das comunicações, " .
                    "é necessário renovar este certificado com urgência.\n\n" .
                    "✅ PRÓXIMOS PASSOS:\n" .
                    "   1. Verificar a validade atual do certificado\n" .
                    "   2. Iniciar o processo de renovação junto à autoridade certificadora\n" .
                    "   3. Atualizar o certificado nos sistemas após a renovação\n" .
                    "   4. Validar o funcionamento após a atualização\n\n" .
                    "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                    "📌 Este ticket foi gerado automaticamente pelo sistema de monitoramento de certificados.";
                if (isset($certificate_data['groups_id'])) {
                    $tkt['_groups_id_observer'] = $certificate_data['groups_id'];
                }
                if (isset($certificate_data['users_id_tech'])) {
                    $tkt['_users_id_assign'] = $certificate_data['users_id_tech'];
                }
                if (isset($certificate_data['groups_id_tech'])) {
                    $tkt['_groups_id_assign'] = $certificate_data['groups_id_tech'];
                }


                $ticket = new Ticket();
                if (!$certificate_data['date']) {
                    $task->addVolume(1);
                    $total++;
                    $ticket_id = $ticket->add($tkt);

                    $DB->insert(
                        'glpi_plugin_certificate_ticket',
                        [
                            'certificate_id' => $certificate_data['id'],
                            'ticket_id' => $ticket_id,
                            'date' => $certificate_data['date_expiration']
                        ]
                    );
                } elseif ($certificate_data['date_expiration'] !== $certificate_data['date']) {
                    $task->addVolume(1);
                    $total++;
                    $ticket_id = $ticket->add($tkt);

                    $DB->update(
                        'glpi_plugin_certificate_ticket',
                        [
                            'date' => $certificate_data['date_expiration']
                        ],
                        [
                            'certificate_id' => $certificate_data['id']
                        ]
                    );
                }
            }
        }

        // Return status: -1 for errors, 1 for tickets created, 0 for no action needed
        return $errors > 0 ? -1 : ($total > 0 ? 1 : 0);
    }
}
