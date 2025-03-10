<?php

declare(strict_types=1);

namespace Rector\Removing\NodeManipulator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use Rector\Core\NodeAnalyzer\PropertyFetchAnalyzer;
use Rector\Core\PhpParser\Comparing\NodeComparator;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\ValueObject\MethodName;
use Rector\DeadCode\SideEffect\SideEffectNodeDetector;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeRemoval\NodeRemover;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;

final class ComplexNodeRemover
{
    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly NodeRemover $nodeRemover,
        private readonly SideEffectNodeDetector $sideEffectNodeDetector,
        private readonly SimpleCallableNodeTraverser $simpleCallableNodeTraverser,
        private readonly PropertyFetchAnalyzer $propertyFetchAnalyzer,
        private readonly NodeComparator $nodeComparator
    ) {
    }

    public function removePropertyAndUsages(
        Class_ $class,
        Property $property,
        bool $removeAssignSideEffect,
        Scope $scope,
        int $propertyStmtKey,
    ): bool {
        $propertyName = $this->nodeNameResolver->getName($property);
        $totalPropertyFetch = $this->propertyFetchAnalyzer->countLocalPropertyFetchName($class, $propertyName);
        $expressions = [];

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($class->stmts, function (Node $node) use (
            $removeAssignSideEffect,
            $propertyName,
            &$totalPropertyFetch,
            &$expressions,
            $scope
        ): ?Node {
            // here should be checked all expr like stmts that can hold assign, e.f. if, foreach etc. etc.
            if (! $node instanceof Expression) {
                return null;
            }

            $nodeExpr = $node->expr;

            // remove direct assigns
            if (! $nodeExpr instanceof Assign) {
                return null;
            }

            $assign = $nodeExpr;

            // skip double assigns
            if ($assign->expr instanceof Assign) {
                return null;
            }

            $originalNode = $assign->getAttribute(AttributeKey::ORIGINAL_NODE);
            if (! $this->nodeComparator->areNodesEqual($originalNode, $assign)) {
                return null;
            }

            $propertyFetches = $this->resolvePropertyFetchFromDimFetch($assign->var);
            if ($propertyFetches === []) {
                return null;
            }

            $currentTotalPropertyFetch = $totalPropertyFetch;
            foreach ($propertyFetches as $propertyFetch) {
                if ($this->nodeNameResolver->isName($propertyFetch->name, $propertyName)) {
                    if (! $removeAssignSideEffect && $this->sideEffectNodeDetector->detect($assign->expr, $scope)) {
                        return null;
                    }

                    --$totalPropertyFetch;
                }
            }

            if ($totalPropertyFetch < $currentTotalPropertyFetch) {
                $expressions[] = $node;
            }

            return null;
        });

        // not all property fetch with name removed
        if ($totalPropertyFetch > 0) {
            return false;
        }

        $this->removeConstructorDependency($class, $propertyName);

        foreach ($expressions as $expression) {
            $this->nodeRemover->removeNode($expression);
        }

        unset($class->stmts[$propertyStmtKey]);

        return true;
    }

    /**
     * @param int[] $paramKeysToBeRemoved
     * @return int[]
     */
    public function processRemoveParamWithKeys(ClassMethod $classMethod, array $paramKeysToBeRemoved): array
    {
        $totalKeys = count($classMethod->params) - 1;
        $removedParamKeys = [];

        foreach ($paramKeysToBeRemoved as $paramKeyToBeRemoved) {
            $startNextKey = $paramKeyToBeRemoved + 1;
            for ($nextKey = $startNextKey; $nextKey <= $totalKeys; ++$nextKey) {
                if (! isset($classMethod->params[$nextKey])) {
                    // no next param, break the inner loop, remove the param
                    break;
                }

                if (in_array($nextKey, $paramKeysToBeRemoved, true)) {
                    // keep searching next key not in $paramKeysToBeRemoved
                    continue;
                }

                return [];
            }

            unset($classMethod->params[$paramKeyToBeRemoved]);
            $removedParamKeys[] = $paramKeyToBeRemoved;
        }

        return $removedParamKeys;
    }

    private function removeConstructorDependency(Class_ $class, string $propertyName): void
    {
        $classMethod = $class->getMethod(MethodName::CONSTRUCT);
        if (! $classMethod instanceof ClassMethod) {
            return;
        }

        $stmts = (array) $classMethod->stmts;
        $paramKeysToBeRemoved = [];

        foreach ($stmts as $key => $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }

            $stmtExpr = $stmt->expr;

            if (! $stmtExpr instanceof Assign) {
                continue;
            }

            if (! $this->propertyFetchAnalyzer->isLocalPropertyFetch($stmtExpr->var)) {
                continue;
            }

            /** @var StaticPropertyFetch|PropertyFetch $propertyFetch */
            $propertyFetch = $stmtExpr->var;
            if (! $this->nodeNameResolver->isName($propertyFetch, $propertyName)) {
                continue;
            }

            unset($classMethod->stmts[$key]);

            if (! $stmtExpr->expr instanceof Variable) {
                continue;
            }

            $key = $this->resolveToBeClearedParamFromConstructor($classMethod, $stmtExpr->expr);
            if (is_int($key)) {
                $paramKeysToBeRemoved[] = $key;
            }
        }

        if ($paramKeysToBeRemoved === []) {
            return;
        }

        $this->processRemoveParamWithKeys($classMethod, $paramKeysToBeRemoved);
    }

    /**
     * @return StaticPropertyFetch[]|PropertyFetch[]
     */
    private function resolvePropertyFetchFromDimFetch(Expr $expr): array
    {
        // unwrap array dim fetch, till we get to parent too caller node
        /** @var PropertyFetch[]|StaticPropertyFetch[] $propertyFetches */
        $propertyFetches = [];

        while ($expr instanceof ArrayDimFetch) {
            $propertyFetches = $this->collectPropertyFetches($expr->dim, $propertyFetches);
            $expr = $expr->var;
        }

        return $this->collectPropertyFetches($expr, $propertyFetches);
    }

    /**
     * @param StaticPropertyFetch[]|PropertyFetch[] $propertyFetches
     * @return PropertyFetch[]|StaticPropertyFetch[]
     */
    private function collectPropertyFetches(?Expr $expr, array $propertyFetches): array
    {
        if (! $expr instanceof Expr) {
            return $propertyFetches;
        }

        if ($this->propertyFetchAnalyzer->isLocalPropertyFetch($expr)) {
            /** @var StaticPropertyFetch|PropertyFetch $expr */
            return array_merge($propertyFetches, [$expr]);
        }

        return $propertyFetches;
    }

    private function resolveToBeClearedParamFromConstructor(ClassMethod $classMethod, Variable $assignedVariable): ?int
    {
        // is variable used somewhere else? skip it
        $variables = $this->betterNodeFinder->findInstanceOf($classMethod, Variable::class);

        $paramNamedVariables = array_filter(
            $variables,
            fn (Variable $variable): bool => $this->nodeNameResolver->areNamesEqual($variable, $assignedVariable)
        );

        // there is more than 1 use, keep it in the constructor
        if (count($paramNamedVariables) > 1) {
            return null;
        }

        $paramName = $this->nodeNameResolver->getName($assignedVariable);
        if (! is_string($paramName)) {
            return null;
        }

        foreach ($classMethod->params as $paramKey => $param) {
            if ($this->nodeNameResolver->isName($param->var, $paramName)) {
                return $paramKey;
            }
        }

        return null;
    }
}
