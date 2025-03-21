<?php

declare(strict_types=1);

namespace Rector\CodeQuality\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use Rector\CodeQuality\TypeResolver\ArrayDimFetchTypeResolver;
use Rector\Core\NodeAnalyzer\PropertyFetchAnalyzer;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\NodeTypeResolver\PHPStan\Type\TypeFactory;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;

final class LocalPropertyAnalyzer
{
    /**
     * @var string
     */
    private const LARAVEL_COLLECTION_CLASS = 'Illuminate\Support\Collection';

    public function __construct(
        private readonly SimpleCallableNodeTraverser $simpleCallableNodeTraverser,
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly ArrayDimFetchTypeResolver $arrayDimFetchTypeResolver,
        private readonly NodeTypeResolver $nodeTypeResolver,
        private readonly PropertyFetchAnalyzer $propertyFetchAnalyzer,
        private readonly TypeFactory $typeFactory,
    ) {
    }

    /**
     * @return array<string, Type>
     */
    public function resolveFetchedPropertiesToTypesFromClass(Class_ $class): array
    {
        $fetchedLocalPropertyNameToTypes = [];

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($class->getMethods(), function (Node $node) use (
            &$fetchedLocalPropertyNameToTypes
        ): ?int {
            if ($this->shouldSkip($node)) {
                return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
            }

            if ($node instanceof Assign && ($node->var instanceof PropertyFetch || $node->var instanceof ArrayDimFetch)) {
                $propertyFetch = $node->var;

                $propertyName = $this->resolvePropertyName(
                    $propertyFetch instanceof ArrayDimFetch ? $propertyFetch->var : $propertyFetch
                );

                if ($propertyName === null) {
                    return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                if ($propertyFetch instanceof ArrayDimFetch) {
                    $fetchedLocalPropertyNameToTypes[$propertyName][] = $this->arrayDimFetchTypeResolver->resolve(
                        $propertyFetch,
                        $node
                    );
                    return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
                }

                $fetchedLocalPropertyNameToTypes[$propertyName][] = $this->nodeTypeResolver->getType($node->expr);
                return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
            }

            $propertyName = $this->resolvePropertyName($node);
            if ($propertyName === null) {
                return null;
            }

            $fetchedLocalPropertyNameToTypes[$propertyName][] = new MixedType();

            return null;
        });

        return $this->normalizeToSingleType($fetchedLocalPropertyNameToTypes);
    }

    private function shouldSkip(Node $node): bool
    {
        // skip anonymous classes and inner function
        if ($node instanceof Class_ || $node instanceof Function_) {
            return true;
        }

        // skip closure call
        return $node instanceof MethodCall && $node->var instanceof Closure;
    }

    private function resolvePropertyName(Node $node): string|null
    {
        if (! $node instanceof PropertyFetch) {
            return null;
        }

        if (! $this->propertyFetchAnalyzer->isLocalPropertyFetch($node)) {
            return null;
        }

        if ($this->shouldSkipPropertyFetch($node)) {
            return null;
        }

        return $this->nodeNameResolver->getName($node->name);
    }

    private function shouldSkipPropertyFetch(PropertyFetch $propertyFetch): bool
    {
        // special Laravel collection scope
        if ($this->shouldSkipForLaravelCollection($propertyFetch)) {
            return true;
        }

        if ($this->isPartOfClosureBind($propertyFetch)) {
            return true;
        }

        if ($propertyFetch->name instanceof Variable) {
            return true;
        }

        return $this->isPartOfClosureBindTo($propertyFetch);
    }

    /**
     * @param array<string, Type[]> $propertyNameToTypes
     * @return array<string, Type>
     */
    private function normalizeToSingleType(array $propertyNameToTypes): array
    {
        // normalize types to union
        $propertyNameToType = [];
        foreach ($propertyNameToTypes as $name => $types) {
            $propertyNameToType[$name] = $this->typeFactory->createMixedPassedOrUnionType($types);
        }

        return $propertyNameToType;
    }

    private function shouldSkipForLaravelCollection(PropertyFetch $propertyFetch): bool
    {
        $staticCallOrClassMethod = $this->betterNodeFinder->findParentByTypes(
            $propertyFetch,
            [ClassMethod::class, StaticCall::class]
        );

        if (! $staticCallOrClassMethod instanceof StaticCall) {
            return false;
        }

        return $this->nodeNameResolver->isName($staticCallOrClassMethod->class, self::LARAVEL_COLLECTION_CLASS);
    }

    /**
     * Local property is actually not local one, but belongs to passed object
     * See https://ocramius.github.io/blog/accessing-private-php-class-members-without-reflection/
     */
    private function isPartOfClosureBind(PropertyFetch $propertyFetch): bool
    {
        $parentStaticCall = $this->betterNodeFinder->findParentType($propertyFetch, StaticCall::class);
        if (! $parentStaticCall instanceof StaticCall) {
            return false;
        }

        if (! $this->nodeNameResolver->isName($parentStaticCall->class, 'Closure')) {
            return true;
        }

        return $this->nodeNameResolver->isName($parentStaticCall->name, 'bind');
    }

    private function isPartOfClosureBindTo(PropertyFetch $propertyFetch): bool
    {
        $parentMethodCall = $this->betterNodeFinder->findParentType($propertyFetch, MethodCall::class);
        if (! $parentMethodCall instanceof MethodCall) {
            return false;
        }

        if (! $parentMethodCall->var instanceof Closure) {
            return false;
        }

        return $this->nodeNameResolver->isName($parentMethodCall->name, 'bindTo');
    }
}
