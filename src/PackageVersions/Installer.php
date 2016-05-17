<?php

namespace PackageVersions;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class Installer implements PluginInterface, EventSubscriberInterface
{
    private static $generatedClassTemplate = <<<'PHP'
<?php

namespace PackageVersions;

/**
 * This class is generated by ocramius/package-versions, specifically by
 * @see \PackageVersions\Installer
 *
 * This file is overwritten at every run of `composer install` or `composer update`.
 */
%s
{
    const VERSIONS = %s;

    private function __construct()
    {
    }

    /**
     * @throws \OutOfBoundsException if a version cannot be located
     */
    public static function getVersion($packageName)
    {
        $version = self::VERSIONS;

        if (! isset($version[$packageName])) {
            throw new \OutOfBoundsException(
                'Required package "' . $packageName . '" is not installed: cannot detect its version'
            );
        }

        return self::VERSIONS[$packageName];
    }

    /**
     * @throws \OutOfBoundsException if a version cannot be located
     */
    public static function getComposerVersion(string $packageName) : string
    {
        list($version) = explode('@', self::getVersion($packageName));
        return $version;
    }
}

PHP;

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getEventDispatcher()->addSubscriber($this);
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'dumpVersionsClass',
            ScriptEvents::POST_UPDATE_CMD  => 'dumpVersionsClass',
        ];
    }

    /**
     * @param Event $composerEvent
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    public static function dumpVersionsClass(Event $composerEvent)
    {
        $io = $composerEvent->getIO();

        $io->write('<info>ocramius/package-versions:</info>  Generating version class...');

        $composer = $composerEvent->getComposer();

        self::writeVersionClassToFile(
            self::generateVersionsClass($composer),
            $composer->getConfig(),
            $composer->getPackage()
        );

        $io->write('<info>ocramius/package-versions:</info> ...done generating version class');
    }

    private static function generateVersionsClass(Composer $composer)
    {
        return sprintf(
            self::$generatedClassTemplate,
            'fin' . 'al ' . 'cla' . 'ss ' . 'Versions', // note: workaround for regex-based code parsers :-(
            var_export(iterator_to_array(self::getVersions($composer->getLocker(), $composer->getPackage())), true)
        );
    }

    /**
     * @param string               $versionClassSource
     * @param Config               $composerConfig
     * @param RootPackageInterface $rootPackage
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    private static function writeVersionClassToFile(
        $versionClassSource,
        Config $composerConfig,
        RootPackageInterface $rootPackage
    ) {
        file_put_contents(
            self::locateRootPackageInstallPath($composerConfig, $rootPackage)
            . '/src/PackageVersions/Versions.php',
            $versionClassSource,
            0664
        );
    }

    /**
     * @param Config               $composerConfig
     * @param RootPackageInterface $rootPackage
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    private static function locateRootPackageInstallPath(
        Config $composerConfig,
        RootPackageInterface $rootPackage
    ) {
        if ('ocramius/package-versions' === self::getRootPackageAlias($rootPackage)->getName()) {
            return dirname($composerConfig->get('vendor-dir'));
        }

        return $composerConfig->get('vendor-dir') . '/ocramius/package-versions';
    }

    private static function getRootPackageAlias(RootPackageInterface $rootPackage)
    {
        $package = $rootPackage;

        while ($package instanceof AliasPackage) {
            $package = $package->getAliasOf();
        }

        return $package;
    }

    /**
     * @param Locker               $locker
     * @param RootPackageInterface $rootPackage
     *
     * @return \Generator|\string[]
     */
    private static function getVersions(Locker $locker, RootPackageInterface $rootPackage)
    {
        $lockData = $locker->getLockData();

        $lockData['packages-dev'] = isset($lockData['packages-dev']) ? $lockData['packages-dev'] : [];

        foreach (array_merge($lockData['packages'], $lockData['packages-dev']) as $package) {
            yield $package['name'] => $package['version'] . '@' . (
                isset($package['source']['reference'])
                    ? $package['source']['reference']
                    : (isset($package['dist']['reference']) ? $package['dist']['reference'] : '')
            );
        }

        yield $rootPackage->getName() => $rootPackage->getVersion() . '@' . $rootPackage->getSourceReference();
    }
}
