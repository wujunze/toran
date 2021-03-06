<?php

declare(strict_types=1);
/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Service;

use Composer\Config as ComposerConfig;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\RepositoryInterface;
use Composer\Util\ComposerMirror;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Toran\ProxyBundle\Model\Repository;

class RepoSyncer
{
    private $config;
    private $router;
    private $distSyncer;
    private $sourceSyncer;
    private $outputDir;
    private $dumper;
    private $repoIdentifier;

    public function __construct(UrlGeneratorInterface $router, Configuration $config, DistSyncer $distSyncer, SourceSyncer $sourceSyncer, $webDir)
    {
        $this->router         = $router;
        $this->config         = $config;
        $this->distSyncer     = $distSyncer;
        $this->sourceSyncer   = $sourceSyncer;
        $this->repoIdentifier = $config->get('monorepo') ? 'all' : 'private';
        $this->outputDir      = realpath($webDir) . '/repo/' . $this->repoIdentifier;
        $this->dumper         = new ArrayDumper();
    }

    public function sync(IOInterface $io, array $repositories, array $whitelist = [], Repository $singleRepo = null)
    {
        unset(ComposerConfig::$defaultRepositories['packagist']);
        $config = Factory::createConfig();
        $config->merge(['repositories' => array_map(
            function ($r) { return $r->config; },
            $repositories
        )]);
        $io->loadConfiguration($config);

        $io->write('<info>Initializing private repositories</info>');

        // TODO LOW skip repos in fullSync mode that have had a hook triggered?
        $repos     = Factory::createDefaultRepositories($io, $config);
        $providers = [];

        $index = -1;
        foreach ($repos as $url => $repo) {
            $index++;

            // skip packages not in whitelist
            if (
                ($singleRepo && $singleRepo !== $repositories[$index])
                || ($whitelist && $repositories[$index]->getNames() && !array_intersect($repositories[$index]->getNames(), $whitelist))
            ) {
                // update providers so the file we generate has all names complete
                foreach ($repositories[$index]->getNames() as $name) {
                    if (!isset($providers[$name])) {
                        $providers[$name] = ['sha256' => null];
                    }
                }

                continue;
            }

            try {
                $providers = $this->syncRepo($io, $url, $repo, $config, $repositories, $index, $providers);
            } catch (\Exception $e) {
                if ($io->isVerbose()) {
                    throw $e;
                }
                $io->write('<error>' . get_class($e) . ' while updating ' . json_encode($repositories[$index]->getCleanConfig()) . ': ' . $e->getMessage() . '</error>', true, IOInterface::QUIET);
            }
        }

        if (!$this->config->get('monorepo')) {
            $this->dumpJson($providers, $this->outputDir . '/packages.json', $io);
        }
    }

    public function getJsonMetadataPath($packageName)
    {
        return $this->outputDir . '/p/' . strtolower($packageName) . '.json';
    }

    /**
     * Creates a dist filename for a given package version and
     * preloads the dist from the original URL if it does not exist in
     * the local cache
     * @param mixed $name
     * @param mixed $version
     * @param mixed $ref
     * @param mixed $format
     */
    public function getDistFilename(Request $req, $name, $version, $ref, $format)
    {
        $file = ComposerMirror::processUrl($this->outputDir . '/dists' . DistSyncer::DIST_FORMAT, $name, $version, $ref, $format);

        if (!file_exists($file)) {
            $json = $this->getJsonMetadataPath($name);
            if (file_exists($json)) {
                $packages = JsonFile::parseJson(file_get_contents($json), $json);
                if (!empty($packages['packages'][$name])) {
                    foreach ($packages['packages'][$name] as $package) {
                        if ($package['version_normalized'] === $version || md5($package['version_normalized']) === $version) {
                            // clear dist URL if it was generated by toran itself
                            if (isset($package['dist']['url'])
                                && (false !== strpos($package['dist']['url'], $req->getHost()) || false !== strpos($package['dist']['url'], '/repo/private') || false !== strpos($package['dist']['url'], '/repo/all'))
                            ) {
                                unset($package['dist']);
                            }

                            $io     = new NullIO();
                            $config = Factory::createConfig();
                            $io->loadConfiguration($config);
                            $loader = new ArrayLoader();
                            if (isset($package['source']) && $ref) {
                                $package['source']['reference'] = $ref;
                            }
                            if (isset($package['dist']) && $ref) {
                                $package['dist']['reference'] = $ref;
                            }
                            $package = $loader->load($package);
                            if ($package instanceof AliasPackage) {
                                $package = $package->getAliasOf();
                            }

                            $this->distSyncer->sync($io, $config, [$package], $this->outputDir . '/dists', 'all');

                            return $file;
                        }
                    }
                }
            }

            return '';
        }

        return $file;
    }

    private function syncRepo(IOInterface $io, $url, RepositoryInterface $repo, ComposerConfig $config, array $repositories, $index, $providers)
    {
        $io->write(sprintf('<info>Initializing %s</info>', is_numeric($url) ? 'repo #' . $url : $url));
        $packages = $repo->getPackages();

        if (!$packages) {
            return $providers;
        }

        $io->write(sprintf("<info>Synchronizing dist archives in '%s'</info>", $this->outputDir . '/dists'));
        $this->distSyncer->sync($io, $config, $packages, $this->outputDir . '/dists', $this->config->get('dist_sync_mode'));

        $io->write(sprintf("<info>Synchronizing clone in '%s'</info>", $this->config->get('git_path')));
        $this->sourceSyncer->sync($io, $config, $packages);

        // dump json after syncing dist/source since they can modify the dist url and such
        $this->dumpPackageJson($packages, $this->outputDir . '/p', $io);

        // update toran_package_names & providers
        $names = [];
        foreach ($packages as $package) {
            if (!isset($names[$package->getName()])) {
                $names[$package->getName()] = $package->getPrettyName();
            }
            if (!isset($providers[$package->getName()])) {
                $providers[$package->getName()] = ['sha256' => null];
            }
        }
        $this->config->setRepositoryPackageNames($repositories[$index], array_values($names));
        $this->config->save();

        return $providers;
    }

    private function dumpPackageJson(array $packages, $providerDir, IOInterface $io)
    {
        if (!is_dir($providerDir)) {
            mkdir($providerDir, 0777, true);
        }

        $uid  = 0;
        $data = [];
        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            $data[$package->getPrettyName()]['__normalized_name']                 = $package->getName();
            $data[$package->getPrettyName()][$package->getPrettyVersion()]        = $this->dumper->dump($package);
            $data[$package->getPrettyName()][$package->getPrettyVersion()]['uid'] = crc32($package->getName()) . ($uid++);
        }

        foreach ($data as $prettyName => $packageData) {
            $file = $packageData['__normalized_name'];
            unset($packageData['__normalized_name']);
            $io->write('<info>Writing ' . $providerDir . '/' . $file . '.json</info>');
            $json = new JsonFile($providerDir . '/' . $file . '.json');
            $json->write(['packages' => [$prettyName => $packageData]]);
        }
    }

    private function dumpJson(array $providers, $filename, IOInterface $io)
    {
        $repo = ['providers' => $providers];

        $distUrl = Proxy::generateDistUrl($this->router, $this->repoIdentifier, '%package%', '%version%', '%reference%', '%type%');
        $mirror  = [
            'dist-url'  => $distUrl,
            'preferred' => true,
        ];
        if ($this->config->isGitSyncEnabled()) {
            $mirror['git-url'] = $this->config->get('git_prefix') . '%package%/%normalizedUrl%.%type%';
        }
        $repo['mirrors']       = [$mirror];
        $repo['providers-url'] = $this->router->generate('toran_proxy_base', ['repo' => $this->repoIdentifier]) . 'p/%package%.json';
        if ($this->config->get('track_downloads')) {
            $repo['notify-batch'] = $this->router->generate('toran_track_downloads', ['repo' => $this->repoIdentifier]);
        }

        $io->write('<info>Writing packages.json for the private repository</info>');
        $repoJson = new JsonFile($filename);
        $repoJson->write($repo);
    }
}
