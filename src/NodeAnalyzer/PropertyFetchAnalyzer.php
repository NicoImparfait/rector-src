<?php

declare(strict_types=1);

namespace Rector\Core\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use Rector\Core\Enum\ObjectReference;
use Rector\Core\PhpParser\AstResolver;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\ValueObject\MethodName;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;
use Rector\StaticTypeMapper\ValueObject\Type\FullyQualifiedObjectType;

final class PropertyFetchAnalyzer
{
    /**
     * @var string
     */
    private const THIS = 'this';

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly AstResolver $astResolver,
        private readonly SimpleCallableNodeTraverser $simpleCallableNodeTraverser,
        private readonly NodeTypeResolver $nodeTypeResolver
    ) {
    }

    public function isLocalPropertyFetch(Node $node): bool
    {
        if (! $node instanceof PropertyFetch && ! $node instanceof StaticPropertyFetch) {
            return false;
        }

        $variableType = $node instanceof PropertyFetch
            ? $this->nodeTypeResolver->getType($node->var)
            : $this->nodeTypeResolver->getType($node->class);

        if ($variableType instanceof FullyQualifiedObjectType) {
            $currentClassLike = $this->betterNodeFinder->findParentType($node, ClassLike::class);
            if ($currentClassLike instanceof ClassLike) {
                return $this->nodeNameResolver->isName($currentClassLike, $variableType->getClassName());
            }

            return false;
        }

        if (! $variableType instanceof ThisType) {
            return $this->isTraitLocalPropertyFetch($node);
        }

        return true;
    }

    public function isLocalPropertyFetchName(Node $node, string $desiredPropertyName): bool
    {
        if (! $node instanceof PropertyFetch && ! $node instanceof StaticPropertyFetch) {
            return false;
        }

        if (! $this->nodeNameResolver->isName($node->name, $desiredPropertyName)) {
            return false;
        }

        return $this->isLocalPropertyFetch($node);
    }

    public function countLocalPropertyFetchName(Class_ $class, string $propertyName): int
    {
        $total = 0;

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($class->getMethods(), function (Node $subNode) use (
            $propertyName,
            &$total
        ): int|null|Node {
            // skip anonymous classes and inner function
            if ($subNode instanceof Class_ || $subNode instanceof Function_) {
                return NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
            }

            if (! $this->isLocalPropertyFetchName($subNode, $propertyName)) {
                return null;
            }

            ++$total;
            return $subNode;
        });

        return $total;
    }

    public function containsLocalPropertyFetchName(Trait_ $trait, string $propertyName): bool
    {
        if ($trait->getProperty($propertyName) instanceof Property) {
            return true;
        }

        return (bool) $this->betterNodeFinder->findFirst(
            $trait,
            fn (Node $node): bool => $this->isLocalPropertyFetchName($node, $propertyName)
        );
    }

    public function isPropertyFetch(Node $node): bool
    {
        if ($node instanceof PropertyFetch) {
            return true;
        }

        return $node instanceof StaticPropertyFetch;
    }

    /**
     * Matches:
     * "$this->someValue = $<variableName>;"
     */
    public function isVariableAssignToThisPropertyFetch(Assign $assign, string $variableName): bool
    {
        if (! $assign->expr instanceof Variable) {
            return false;
        }

        if (! $this->nodeNameResolver->isName($assign->expr, $variableName)) {
            return false;
        }

        return $this->isLocalPropertyFetch($assign->var);
    }

    public function isFilledViaMethodCallInConstructStmts(ClassLike $classLike, string $propertyName): bool
    {
        $classMethod = $classLike->getMethod(MethodName::CONSTRUCT);
        if (! $classMethod instanceof ClassMethod) {
            return false;
        }

        $className = (string) $this->nodeNameResolver->getName($classLike);
        $stmts = (array) $classMethod->stmts;

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof MethodCall && ! $stmt->expr instanceof StaticCall) {
                continue;
            }

            $callerClassMethod = $this->astResolver->resolveClassMethodFromCall($stmt->expr);
            if (! $callerClassMethod instanceof ClassMethod) {
                continue;
            }

            $callerClass = $this->betterNodeFinder->findParentType($callerClassMethod, Class_::class);
            if (! $callerClass instanceof Class_) {
                continue;
            }

            $callerClassName = (string) $this->nodeNameResolver->getName($callerClass);
            $isFound = $this->isPropertyAssignFoundInClassMethod(
                $classLike,
                $className,
                $callerClassName,
                $callerClassMethod,
                $propertyName
            );
            if ($isFound) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $propertyNames
     */
    public function isLocalPropertyOfNames(Expr $expr, array $propertyNames): bool
    {
        if (! $this->isLocalPropertyFetch($expr)) {
            return false;
        }

        /** @var PropertyFetch $expr */
        return $this->nodeNameResolver->isNames($expr->name, $propertyNames);
    }

    private function isTraitLocalPropertyFetch(Node $node): bool
    {
        if ($node instanceof PropertyFetch) {
            if (! $node->var instanceof Variable) {
                return false;
            }

            return $this->nodeNameResolver->isName($node->var, self::THIS);
        }

        if ($node instanceof StaticPropertyFetch) {
            if (! $node->class instanceof Name) {
                return false;
            }

            return $this->nodeNameResolver->isNames($node->class, [
                ObjectReference::SELF,
                ObjectReference::STATIC,
            ]);
        }

        return false;
    }

    private function isPropertyAssignFoundInClassMethod(
        ClassLike $classLike,
        string $className,
        string $callerClassName,
        ClassMethod $classMethod,
        string $propertyName
    ): bool {
        if ($className !== $callerClassName && ! $classLike instanceof Trait_) {
            $objectType = new ObjectType($className);
            $callerObjectType = new ObjectType($callerClassName);

            if (! $callerObjectType->isSuperTypeOf($objectType)->yes()) {
                return false;
            }
        }

        foreach ((array) $classMethod->stmts as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            if (! $stmt->expr instanceof Assign) {
                continue;
            }

            if ($this->isLocalPropertyFetchName($stmt->expr->var, $propertyName)) {
                return true;
            }
        }

        return false;
    }
}
