<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DumpPackagesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:dump')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a dump of all packages'),
                new InputOption('gc', null, InputOption::VALUE_NONE, 'Runs garbage collection of old files'),
            ))
            ->setDescription('Dumps the packages into a packages.json + included files')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = (bool) $input->getOption('force');
        $gc = (bool) $input->getOption('gc');
        $verbose = (bool) $input->getOption('verbose');

        $deployLock = $this->getContainer()->getParameter('kernel.cache_dir').'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return;
        }

        // another dumper is still active
        $lockName = $this->getName();
        if ($gc) {
            $lockName .= '-gc';
        }
        $locker = $this->getContainer()->get('locker');
        if (!$locker->lockCommand($lockName)) {
            if ($verbose) {
                $output->writeln('Aborting, another task is running already');
            }
            return 0;
        }

        $doctrine = $this->getContainer()->get('doctrine');
        $dumper = $this->getContainer()->get('packagist.package_dumper');

        if ($gc) {
            try {
                $dumper->gc();
            } finally {
                $locker->unlockCommand($lockName);
            }
            return 0;
        }

        if ($force) {
            $packages = $doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package WHERE replacementPackage != "spam/spam" OR replacementPackage IS NULL ORDER BY id ASC');
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackagesForDumping();
        }

        $ids = array();
        foreach ($packages as $package) {
            $ids[] = $package['id'];
        }
        if (!$ids && !$force) {
            if ($verbose) {
                $output->writeln('Aborting, no packages to dump and not doing a forced run');
            }
            return 0;
        }

        ini_set('memory_limit', -1);
        gc_enable();

        try {
            $result = $dumper->dump($ids, $force, $verbose);
        } finally {
            $locker->unlockCommand($lockName);
        }

        return $result ? 0 : 1;
    }
}
