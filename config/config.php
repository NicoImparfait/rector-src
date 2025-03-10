<?php

declare(strict_types=1);

use Composer\Semver\VersionParser;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\Rules\English\InflectorFactory;
use OndraM\CiDetector\CiDetector;
use PhpParser\BuilderFactory;
use PhpParser\ConstExprEvaluator;
use PhpParser\Lexer;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Dependency\DependencyResolver;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\Reflection\ReflectionProvider;
use Rector\BetterPhpDocParser\PhpDocParser\BetterPhpDocParser;
use Rector\BetterPhpDocParser\PhpDocParser\BetterTypeParser;
use Rector\Caching\Cache;
use Rector\Caching\CacheFactory;
use Rector\Caching\ValueObject\Storage\MemoryCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Core\Bootstrap\ExtensionConfigResolver;
use Rector\Core\Configuration\Parameter\ParameterProvider;
use Rector\Core\Console\ConsoleApplication;
use Rector\Core\Console\Style\RectorConsoleOutputStyle;
use Rector\Core\Console\Style\RectorConsoleOutputStyleFactory;
use Rector\Core\Console\Style\SymfonyStyleFactory;
use Rector\Core\Validation\Collector\EmptyConfigurableRectorCollector;
use Rector\NodeTypeResolver\DependencyInjection\PHPStanServicesFactory;
use Rector\NodeTypeResolver\Reflection\BetterReflection\SourceLocator\IntermediateSourceLocator;
use Rector\NodeTypeResolver\Reflection\BetterReflection\SourceLocatorProvider\DynamicSourceLocatorProvider;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;
use Rector\PhpDocParser\PhpParser\SmartPhpParser;
use Rector\PhpDocParser\PhpParser\SmartPhpParserFactory;
use Rector\PSR4\Composer\PSR4NamespaceMatcher;
use Rector\PSR4\Contract\PSR4AutoloadNamespaceMatcherInterface;
use Rector\Utils\Command\MissingInSetCommand;
use Rector\Utils\Command\OutsideAnySetCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Style\SymfonyStyle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\Filesystem\Filesystem;
use Symplify\EasyParallel\ValueObject\EasyParallelConfig;

return static function (RectorConfig $rectorConfig): void {
    // make use of https://github.com/symplify/easy-parallel
    $rectorConfig->import(EasyParallelConfig::FILE_PATH);

    $rectorConfig->paths([]);
    $rectorConfig->skip([]);

    $rectorConfig->autoloadPaths([]);
    $rectorConfig->bootstrapFiles([]);
    $rectorConfig->parallel(120, 16, 20);

    // to avoid autoimporting out of the box
    $rectorConfig->importNames(false, false);
    $rectorConfig->removeUnusedImports(false);

    $rectorConfig->importShortClasses();
    $rectorConfig->indent(' ', 4);

    $rectorConfig->fileExtensions(['php']);
    $rectorConfig->nestedChainMethodCallLimit(120);

    $rectorConfig->cacheDirectory(sys_get_temp_dir() . '/rector_cached_files');
    $rectorConfig->containerCacheDirectory(sys_get_temp_dir());

    $services = $rectorConfig->services();
    $services->defaults()
        ->public()
        ->autowire()
        ->autoconfigure();

    $services->load('Rector\\', __DIR__ . '/../packages')
        ->exclude([
            __DIR__ . '/../packages/Config/RectorConfig.php',
            __DIR__ . '/../packages/*/{ValueObject,Contract,Exception}',
            __DIR__ . '/../packages/BetterPhpDocParser/PhpDocInfo/PhpDocInfo.php',
            __DIR__ . '/../packages/Testing/PHPUnit',
            __DIR__ . '/../packages/BetterPhpDocParser/PhpDoc',

            __DIR__ . '/../packages/PhpDocParser/NodeVisitor',
            __DIR__ . '/../packages/PhpDocParser/PhpParser/SmartPhpParser.php',
            __DIR__ . '/../packages/PhpDocParser/ValueObject',
            __DIR__ . '/../packages/PhpDocParser/PhpDocParser/PhpDocNodeVisitor/CallablePhpDocNodeVisitor.php',

            __DIR__ . '/../packages/PHPStanStaticTypeMapper/Enum',
            __DIR__ . '/../packages/Caching/Cache.php',
            __DIR__ . '/../packages/NodeTypeResolver/PhpDocNodeVisitor/UnderscoreRenamePhpDocNodeVisitor.php',

            // used in PHPStan
            __DIR__ . '/../packages/NodeTypeResolver/Reflection/BetterReflection/RectorBetterReflectionSourceLocatorFactory.php',
            __DIR__ . '/../packages/NodeTypeResolver/Reflection/BetterReflection/SourceLocatorProvider/DynamicSourceLocatorProvider.php',
        ]);

    // psr-4
    $services->alias(PSR4AutoloadNamespaceMatcherInterface::class, PSR4NamespaceMatcher::class);

    $services->load('Rector\\', __DIR__ . '/../rules')
        ->exclude([
            __DIR__ . '/../rules/*/ValueObject/*',
            __DIR__ . '/../rules/*/Rector/*',
            __DIR__ . '/../rules/*/Contract/*',
            __DIR__ . '/../rules/*/Exception/*',
            __DIR__ . '/../rules/*/Enum/*',
        ]);

    $services->set(Filesystem::class);

    // use faster in-memory cache in CI.
    // CI always starts from scratch, therefore IO intensive caching is not worth it
    $ciDetector = new CiDetector();
    if ($ciDetector->isCiDetected()) {
        $rectorConfig->cacheClass(MemoryCacheStorage::class);
    }

    $extensionConfigResolver = new ExtensionConfigResolver();
    $extensionConfigFiles = $extensionConfigResolver->provide();
    foreach ($extensionConfigFiles as $extensionConfigFile) {
        $rectorConfig->import($extensionConfigFile);
    }

    $services->load('Rector\Core\\', __DIR__ . '/../src')
        ->exclude([
            __DIR__ . '/../src/Rector',
            __DIR__ . '/../src/Console/Style/RectorConsoleOutputStyle.php',
            __DIR__ . '/../src/Exception',
            __DIR__ . '/../src/DependencyInjection/CompilerPass',
            __DIR__ . '/../src/DependencyInjection/Loader',
            __DIR__ . '/../src/Kernel',
            __DIR__ . '/../src/ValueObject',
            __DIR__ . '/../src/Bootstrap',
            __DIR__ . '/../src/Enum',
            __DIR__ . '/../src/functions',
            __DIR__ . '/../src/PhpParser/Node/CustomNode',
            __DIR__ . '/../src/PhpParser/ValueObject',
            __DIR__ . '/../src/constants.php',
        ]);

    $services->alias(Application::class, ConsoleApplication::class);

    $services->set(EmptyConfigurableRectorCollector::class)
        ->arg('$containerBuilder', service('service_container'));

    $services->set(SimpleCallableNodeTraverser::class);

    $services->set(BuilderFactory::class);
    $services->set(CloningVisitor::class);
    $services->set(ParentConnectingVisitor::class);
    $services->set(NodeFinder::class);

    $services->set(RectorConsoleOutputStyle::class)
        ->factory([service(RectorConsoleOutputStyleFactory::class), 'create']);

    $services->set(Parser::class)
        ->factory([service(PHPStanServicesFactory::class), 'createPHPStanParser']);

    $services->set(Lexer::class)
        ->factory([service(PHPStanServicesFactory::class), 'createEmulativeLexer']);

    $services->set(ParameterProvider::class)
        ->arg('$container', service('service_container'));

    $services->set(InflectorFactory::class);
    $services->set(Inflector::class)
        ->factory([service(InflectorFactory::class), 'build']);

    $services->set(VersionParser::class);

    // console
    $services->set(SymfonyStyleFactory::class);
    $services->set(SymfonyStyle::class)
        ->factory([service(SymfonyStyleFactory::class), 'create']);

    // cache
    $services->set(DependencyResolver::class)
        ->factory([service(PHPStanServicesFactory::class), 'createDependencyResolver']);
    $services->set(FileHelper::class)
        ->factory([service(PHPStanServicesFactory::class), 'createFileHelper']);

    $services->set(Cache::class)
        ->factory([service(CacheFactory::class), 'create']);

    // type resolving
    $services->set(IntermediateSourceLocator::class);
    $services->alias(TypeParser::class, BetterTypeParser::class);

    // PHPStan services
    $services->set(ReflectionProvider::class)
        ->factory([service(PHPStanServicesFactory::class), 'createReflectionProvider']);

    $services->set(NodeScopeResolver::class)
        ->factory([service(PHPStanServicesFactory::class), 'createNodeScopeResolver']);

    $services->set(ScopeFactory::class)
        ->factory([service(PHPStanServicesFactory::class), 'createScopeFactory']);

    $services->set(TypeNodeResolver::class)
        ->factory([service(PHPStanServicesFactory::class), 'createTypeNodeResolver']);

    $services->set(DynamicSourceLocatorProvider::class)
        ->factory([service(PHPStanServicesFactory::class), 'createDynamicSourceLocatorProvider']);

    $services->set(MissingInSetCommand::class);
    $services->set(OutsideAnySetCommand::class);

    // phpdoc parser
    $services->set(SmartPhpParser::class)
        ->factory([service(SmartPhpParserFactory::class), 'create']);

    $services->set(ConstExprEvaluator::class);
    $services->set(NodeFinder::class);

    // phpdoc parser
    $services->set(PhpDocParser::class);
    $services->alias(PhpDocParser::class, BetterPhpDocParser::class);

    $services->set(\PHPStan\PhpDocParser\Lexer\Lexer::class);
    $services->set(TypeParser::class);
    $services->set(ConstExprParser::class);
};
