<?php

declare(strict_types=1);

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Console\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Satis\Builder\Satis2Nexus;
use Composer\Satis\PackageSelection\PackageSelection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class PurgeCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('purge')
            ->setDescription('Purge packages')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
            ])
            ->setHelp(
<<<'EOT'
The <info>purge</info> command deletes useless archive files, depending
on given json file (satis.json is used by default) and the
newest json file in the include directory of the given output-dir.

In your satis.json (or other name you give), you must define
"archive" argument.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getArgument('file');
        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: ' . $configFile . '</error>');

            return 1;
        }
        $config = $file->read();
	    $satis2nexus = new Satis2Nexus($output,$config);
        /*
         * Check whether archive is defined
         */
        if (!isset($config['archive']) || !isset($config['archive']['directory'])) {
            $output->writeln('<error>You must define "archive" parameter in your ' . $configFile . '</error>');

            return 1;
        }

        $outputDir = $input->getArgument('output-dir') ?? $config['output-dir'] ?? null;
        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside ' . $input->getArgument('file'));
        }

        $packageSelection = new PackageSelection($output, $outputDir, $config, false);
        $packages = $packageSelection->load();

        $prefix = sprintf(
            '%s/',
            $config['archive']['directory']
        );

        $length = strlen($prefix);
        $neededFiles = [];
	    $neededPackages = [];
        foreach ($packages as $package) {
            if (!$package->getDistType()) {
                continue;
            }
            $url = $package->getDistUrl();
            if (substr($url, 0, $length) === $prefix) {
	            $neededFiles[] = substr($url, $length);
	            $neededPackages[] = $package;
            }
        }

        $distDirectory = sprintf('%s/%s', $outputDir, $config['archive']['directory']);

        $finder = new Finder();
        $finder
            ->files()
            ->in($distDirectory)
        ;

        if (!$finder->count()) {
            $output->writeln('<warning>No archives found.</warning>');

            return 0;
        }

        /** @var SplFileInfo[] $unreferenced */
        $unreferenced = [];
        foreach ($finder as $file) {
            $filename = strtr($file->getRelativePathname(), DIRECTORY_SEPARATOR, '/');
            if (!in_array($filename, $neededFiles)) {
                $unreferenced[] = $file;
            }
        }

        if (empty($unreferenced)) {
            $output->writeln('<warning>No unreferenced archives found.</warning>');
        }

        foreach ($unreferenced as $file) {
            unlink($file->getPathname());

	        $output->writeln(sprintf(
                '<info>Removed archive</info>: <comment>%s</comment>',
                $file->getRelativePathname()
            ));
        }

	    try {
		    $satis2nexus->deleteNoNeeded2Nexus($neededPackages);
	    } catch (\ErrorException $exception) {
		    $output->writeln(sprintf("<error>Les packages n'ont pas été supprimés sur Nexus : '%s'.</error>", $exception->getMessage()));
	    }

        $this->removeEmptyDirectories($output, $distDirectory);

        $output->writeln('<info>Done.</info>');

        return 0;
    }

    private function removeEmptyDirectories($output, $dir, $depth = 2)
    {
        $empty = true;
        $children = @scandir($dir);
        if (false === $children) {
            return false;
        }
        foreach ($children as $child) {
            if ('.' === $child || '..' === $child) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $child;
            if (is_dir($path)
                && $depth > 0
                && $this->removeEmptyDirectories($output, $path, $depth - 1)
                && rmdir($path)
            ) {
                $output->writeln(sprintf('<info>Removed empty directory</info>: <comment>%s</comment>', $path));
            } else {
                $empty = false;
            }
        }

        return $empty;
    }
}
