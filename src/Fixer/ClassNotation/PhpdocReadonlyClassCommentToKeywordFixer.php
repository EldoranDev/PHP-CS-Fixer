<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\ClassNotation;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\DocBlock\DocBlock;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @author Marcel Behrmann <marcel@behrmann.dev>
 */
final class PhpdocReadonlyClassCommentToKeywordFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     *
     * Must run before NoEmptyPhpdocFixer, NoExtraBlankLinesFixer, PhpdocAlignFixer.
     * Must run after AlignMultilineCommentFixer, CommentToPhpdocFixer, PhpdocIndentFixer, PhpdocScalarFixer, PhpdocToCommentFixer, PhpdocTypesFixer.
     */
    public function getPriority(): int
    {
        return 4;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        if (!\defined('T_READONLY')) {
            return false;
        }

        return $tokens->isTokenKindFound(T_DOC_COMMENT);
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Converts readonly comment on classes to the readonly keyword.',
            [
                new CodeSample(
                    <<<EOT
                            <?php
                            /** @readonly */
                            class C {
                            }\n
                        EOT,
                ),
            ]
        );
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_DOC_COMMENT)) {
                continue;
            }

            $doc = new DocBlock($token->getContent());

            $annotations = $doc->getAnnotationsOfType('readonly');

            foreach ($annotations as $annotation) {
                $annotation->remove();
            }

            if (0 === \count($annotations)) {
                continue;
            }

            $mainIndex = $index;
            $index = $tokens->getNextMeaningfulToken($index);
            $addReadonly = true;

            while ($tokens[$index]->isGivenKind([
                T_ABSTRACT,
                T_FINAL,
                T_PRIVATE,
                T_PUBLIC,
                T_PROTECTED,
                T_READONLY,
            ])) {
                if ($tokens[$index]->isGivenKind([T_READONLY])) {
                    $addReadonly = false;
                }

                $index = $tokens->getNextMeaningfulToken($index);
            }

            if (!$tokens[$index]->isGivenKind(T_CLASS)) {
                continue;
            }

            if ($addReadonly) {
                $tokens->insertAt($index, [new Token([T_READONLY, 'readonly']), new Token([T_WHITESPACE, ' '])]);
            }

            $newContent = $doc->getContent();

            if ($newContent === $token->getContent()) {
                continue;
            }

            if ('' === $newContent) {
                $tokens->clearTokenAndMergeSurroundingWhitespace($mainIndex);

                continue;
            }

            $tokens[$mainIndex] = new Token([T_DOC_COMMENT, $doc->getContent()]);
        }
    }
}
