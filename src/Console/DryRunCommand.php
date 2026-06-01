<?php

namespace GlpiPlugin\Certificateticket\Console;

use GlpiPlugin\Certificateticket\CertificateTicket;
use GlpiPlugin\Certificateticket\PluginCertificateticketConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI preview of the certificate scan.
 *
 *   php bin/console plugins:certificateticket:dry-run
 *
 * Shows which certificates would generate tickets / followups / resolutions,
 * without writing anything.
 */
class DryRunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('plugins:certificateticket:dry-run')
            ->setDescription('Preview certificates that would generate tickets (no changes are made).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Certificate Ticket — dry run</info>');
        $output->writeln('');

        // Global stats
        $stats = CertificateTicket::getGlobalStats();
        $output->writeln('Certificates snapshot:');
        $output->writeln("  Expired:        <comment>{$stats['expired']}</comment>");
        $output->writeln("  Within 7 days:  <comment>{$stats['d7']}</comment>");
        $output->writeln("  Within 30 days: <comment>{$stats['d30']}</comment>");
        $output->writeln("  Within 90 days: <comment>{$stats['d90']}</comment>");
        $output->writeln("  Tickets generated so far: <comment>{$stats['tickets']}</comment>");
        $output->writeln('');

        $cfg = PluginCertificateticketConfig::getConfig();
        $output->writeln(sprintf(
            'Lead time: <comment>%s</comment>',
            $cfg['use_entity_delay'] ? 'entity certificate-alert delay' : $cfg['days_before'] . ' days (plugin)'
        ));
        $output->writeln('');

        $report = CertificateTicket::processCertificates(null, true);

        if (empty($report['preview'])) {
            $output->writeln('<info>Nothing to do — no certificate currently requires an action.</info>');
            return Command::SUCCESS;
        }

        foreach ($report['preview'] as $row) {
            switch ($row['type']) {
                case 'ticket':
                    $output->writeln(sprintf('  🎫 NEW TICKET   <comment>%s</comment> (%d day(s) left)', $row['certificate'], $row['days']));
                    break;
                case 'followup':
                    $output->writeln(sprintf('  💬 FOLLOWUP     <comment>%s</comment> on ticket #%d (%d day(s) left)', $row['certificate'], $row['ticket'], $row['days']));
                    break;
                case 'resolve':
                    $output->writeln(sprintf('  ✅ RESOLVE      <comment>%s</comment> ticket #%d (renewed)', $row['certificate'], $row['ticket']));
                    break;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Summary: %d ticket(s), %d followup(s), %d resolution(s) would be applied.</info>',
            $report['created'],
            $report['followups'],
            $report['resolved']
        ));

        return Command::SUCCESS;
    }
}
