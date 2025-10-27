<?php

declare(strict_types=1);

namespace App\Service;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class ClassUsageCollector extends NodeVisitorAbstract
{
    private array $usedClasses = [];
    private array $useStatements = [];

    public function enterNode(Node $node)
    {
        // Collect use statements
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->useStatements[$use->alias?->name ?? $use->name->getLast()] = $use->name->toString();
            }
        }

        // Collect group use statements
        if ($node instanceof Node\Stmt\GroupUse) {
            foreach ($node->uses as $use) {
                $fullName = $node->prefix->toString() . '\\' . $use->name->toString();
                $this->useStatements[$use->alias?->name ?? $use->name->getLast()] = $fullName;
            }
        }

        // Collect class instantiations
        if ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->addClassUsage($node->class);
            }
        }

        // Collect static calls
        if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\ClassConstFetch) {
            if ($node->class instanceof Node\Name) {
                $this->addClassUsage($node->class);
            }
        }

        // Collect instanceof checks
        if ($node instanceof Node\Expr\Instanceof_) {
            if ($node->class instanceof Node\Name) {
                $this->addClassUsage($node->class);
            }
        }

        // Collect catch statements
        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addClassUsage($type);
            }
        }

        // Collect type hints in functions/methods
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            // Parameter types
            foreach ($node->params as $param) {
                if ($param->type instanceof Node\Name) {
                    $this->addClassUsage($param->type);
                }
            }
            // Return type
            if ($node->returnType instanceof Node\Name) {
                $this->addClassUsage($node->returnType);
            }
        }

        // Collect property types
        if ($node instanceof Node\Stmt\Property) {
            if ($node->type instanceof Node\Name) {
                $this->addClassUsage($node->type);
            }
        }

        // Collect attributes
        foreach ($node->attrGroups ?? [] as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $this->addClassUsage($attr->name);
            }
        }
    }

    private function addClassUsage(Node\Name $name): void
    {
        $className = $name->toString();

        // Resolve if it's a short name that matches a use statement
        if (!$name->isFullyQualified() && isset($this->useStatements[$name->getFirst()])) {
            $className = $this->useStatements[$name->getFirst()];
        } elseif ($name->isFullyQualified()) {
            $className = ltrim($className, '\\');
        }

        $this->usedClasses[$className] = true;
    }

    public function getUsedClasses(): array
    {
        return array_keys($this->usedClasses);
    }
}
