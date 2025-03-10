<?php

declare(strict_types=1);

namespace Rector\CodeQuality\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use Rector\Core\PhpParser\Comparing\NodeComparator;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\NodeNameResolver\NodeNameResolver;

final class ForeachAnalyzer
{
    public function __construct(
        private readonly NodeComparator $nodeComparator,
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly VariableNameUsedNextAnalyzer $variableNameUsedNextAnalyzer
    ) {
    }

    /**
     * Matches$
     * foreach ($values as $value) {
     *      <$assigns[]> = $value;
     * }
     */
    public function matchAssignItemsOnlyForeachArrayVariable(Foreach_ $foreach): ?Expr
    {
        if (count($foreach->stmts) !== 1) {
            return null;
        }

        $onlyStatement = $foreach->stmts[0];
        if ($onlyStatement instanceof Expression) {
            $onlyStatement = $onlyStatement->expr;
        }

        if (! $onlyStatement instanceof Assign) {
            return null;
        }

        if (! $onlyStatement->var instanceof ArrayDimFetch) {
            return null;
        }

        if ($onlyStatement->var->dim instanceof Expr) {
            return null;
        }

        if (! $this->nodeComparator->areNodesEqual($foreach->valueVar, $onlyStatement->expr)) {
            return null;
        }

        return $onlyStatement->var->var;
    }

    public function isValueVarUsed(Foreach_ $foreach, string $singularValueVarName): bool
    {
        $isUsedInStmts = (bool) $this->betterNodeFinder->findFirst($foreach->stmts, function (Node $node) use (
            $singularValueVarName
        ): bool {
            if (! $node instanceof Variable) {
                return false;
            }

            return $this->nodeNameResolver->isName($node, $singularValueVarName);
        });

        if ($isUsedInStmts) {
            return true;
        }

        if ($this->variableNameUsedNextAnalyzer->isValueVarUsedNext($foreach, $singularValueVarName)) {
            return true;
        }

        return $this->variableNameUsedNextAnalyzer->isValueVarUsedNext(
            $foreach,
            (string) $this->nodeNameResolver->getName($foreach->valueVar)
        );
    }
}
