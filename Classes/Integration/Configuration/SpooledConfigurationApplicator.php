<?php
namespace FluidTYPO3\Flux\Integration\Configuration;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Builder\ContentTypeBuilder;
use FluidTYPO3\Flux\Builder\RequestBuilder;
use FluidTYPO3\Flux\Content\ContentTypeManager;
use FluidTYPO3\Flux\Core;
use FluidTYPO3\Flux\Form;
use FluidTYPO3\Flux\Provider\Provider;
use FluidTYPO3\Flux\Provider\ProviderInterface;
use FluidTYPO3\Flux\Utility\ExtensionNamingUtility;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Exception;

class SpooledConfigurationApplicator
{
    private ContentTypeBuilder $contentTypeBuilder;
    private ContentTypeManager $contentTypeManager;
    private RequestBuilder $requestBuilder;
    private PackageManager $packageManager;

    public function __construct(
        ContentTypeBuilder $contentTypeBuilder,
        ContentTypeManager $contentTypeManager,
        RequestBuilder $requestBuilder,
        PackageManager $packageManager
    ) {
        $this->contentTypeBuilder = $contentTypeBuilder;
        $this->contentTypeManager = $contentTypeManager;
        $this->requestBuilder = $requestBuilder;
        $this->packageManager = $packageManager;
    }

    public function processData(): void
    {
        // Initialize the TCA needed by "template as CType" integrations
        $this->spoolQueuedContentTypeTableConfigurations(Core::getQueuedContentTypeRegistrations());

        foreach ($this->contentTypeManager->fetchContentTypes() as $contentType) {
            $this->contentTypeManager->registerTypeDefinition($contentType);
            Core::registerTemplateAsContentType(
                $contentType->getExtensionIdentity(),
                $contentType->getTemplatePathAndFilename(),
                $contentType->getContentTypeName(),
                $contentType->getProviderClassName()
            );
        }

        $this->spoolQueuedContentTypeRegistrations(Core::getQueuedContentTypeRegistrations());
        Core::clearQueuedContentTypeRegistrations();

        $scopedRequire = static function (string $filename): void {
            require $filename;
        };

        $activePackages = $this->packageManager->getActivePackages();
        foreach ($activePackages as $package) {
            try {
                $finder = Finder::create()
                    ->files()
                    ->sortByName()
                    ->depth(0)
                    ->name('*.php')
                    ->in($package->getPackagePath() . 'Configuration/TCA/Flux');
            } catch (\InvalidArgumentException $e) {
                // No such directory in this package
                continue;
            }
            foreach ($finder as $fileInfo) {
                $scopedRequire($fileInfo->getPathname());
            }
        }
    }

    private function spoolQueuedContentTypeTableConfigurations(array $queue): void
    {
        foreach ($queue as $queuedRegistration) {
            [$extensionName, $templatePathAndFilename, , $contentType] = $queuedRegistration;
            $contentType = $contentType ?: $this->determineContentType($extensionName, $templatePathAndFilename);
            $this->contentTypeBuilder->addBoilerplateTableConfiguration($contentType);
        }
    }

    private function determineContentType(
        string $providerExtensionName,
        string $templatePathAndFilename
    ): string {
        // Determine which plugin name and controller action to emulate with this CType, base on file name.
        $controllerExtensionName = $providerExtensionName;
        $emulatedPluginName = ucfirst(pathinfo($templatePathAndFilename, PATHINFO_FILENAME));
        $extensionSignature = str_replace('_', '', ExtensionNamingUtility::getExtensionKey($controllerExtensionName));
        $fullContentType = $extensionSignature . '_' . strtolower($emulatedPluginName);
        return $fullContentType;
    }

    protected function spoolQueuedContentTypeRegistrations(array $queue): void
    {
        $applicationContext = $this->getApplicationContext();
        $providers = [];
        foreach ($queue as $queuedRegistration) {
            /** @var ProviderInterface $provider */
            [
                $providerExtensionName,
                $templateFilename,
                $providerClassName,
                $contentType,
                $pluginName,
                $controllerActionName
            ] = $queuedRegistration;
            try {
                $contentType = $contentType ?: $this->determineContentType($providerExtensionName, $templateFilename);
                $defaultControllerExtensionName = 'FluidTYPO3.Flux';
                $provider = $this->contentTypeBuilder->configureContentTypeFromTemplateFile(
                    $providerExtensionName,
                    $templateFilename,
                    $providerClassName ?? Provider::class,
                    $contentType,
                    $defaultControllerExtensionName,
                    $controllerActionName
                );

                $provider->setPluginName($pluginName);

                Core::registerConfigurationProvider($provider);

                $providers[] = $provider;
            } catch (Exception $error) {
                if (!$applicationContext->isProduction()) {
                    throw $error;
                }
            }
        }

        $self = $this;

        $backup = $GLOBALS['TYPO3_REQUEST'] ?? null;

        $GLOBALS['TYPO3_REQUEST'] = $this->requestBuilder->getServerRequest();

        uasort(
            $providers,
            function (ProviderInterface $item1, ProviderInterface $item2) use ($self) {
                $form1 = $item1->getForm(['CType' => $item1->getContentObjectType()]);
                $form2 = $item2->getForm(['CType' => $item2->getContentObjectType()]);
                return $self->resolveSortingValue($form1) <=> $self->resolveSortingValue($form2);
            }
        );

        foreach ($providers as $provider) {
            $contentType = $provider->getContentObjectType();
            $virtualRecord = ['CType' => $contentType];
            $providerExtensionName = $provider->getExtensionKey($virtualRecord);

            try {
                $this->contentTypeBuilder->registerContentType($providerExtensionName, $contentType, $provider);
            } catch (Exception $error) {
                if (!$applicationContext->isProduction()) {
                    throw $error;
                }
            }
        }

        $GLOBALS['TYPO3_REQUEST'] = $backup;
    }

    private function resolveSortingValue(?Form $form): string
    {
        $sortingOptionValue = 0;
        if ($form instanceof Form\FormInterface) {
            if ($form->hasOption(Form::OPTION_SORTING)) {
                $sortingOptionValue = $form->getOption(Form::OPTION_SORTING);
            } elseif ($form->hasOption(Form::OPTION_TEMPLATEFILE)) {
                /** @var string $templateFilename */
                $templateFilename = $form->getOption(Form::OPTION_TEMPLATEFILE);
                $sortingOptionValue = basename($templateFilename);
            } else {
                $sortingOptionValue = $form->getId();
            }
        }
        return !is_scalar($sortingOptionValue) ? '0' : (string) $sortingOptionValue;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function getApplicationContext(): ApplicationContext
    {
        return Environment::getContext();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function getContentTypeManager(): ContentTypeManager
    {
        /** @var ContentTypeManager $contentTypeManager */
        $contentTypeManager = GeneralUtility::makeInstance(ContentTypeManager::class);
        return $contentTypeManager;
    }
}
