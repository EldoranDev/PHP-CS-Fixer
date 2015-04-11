<?php

/*
 * This file is part of the PHP CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS\Tokenizer;

use Symfony\CS\Utils;

/**
 * Collection of code tokens.
 *
 * Its role is to provide the ability to manage collection and navigate through it.
 *
 * As a token prototype you should understand a single element generated by token_get_all.
 *
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
class Tokens extends \SplFixedArray
{
    const BLOCK_TYPE_PARENTHESIS_BRACE = 1;
    const BLOCK_TYPE_CURLY_BRACE = 2;
    const BLOCK_TYPE_INDEX_SQUARE_BRACE = 3;
    const BLOCK_TYPE_ARRAY_SQUARE_BRACE = 4;
    const BLOCK_TYPE_DYNAMIC_PROP_BRACE = 5;

    /**
     * Static class cache.
     *
     * @var array
     */
    private static $cache = array();

    /**
     * crc32 hash of code string.
     *
     * @var string
     */
    private $codeHash;

    /**
     * Flag is collection was changed.
     *
     * It doesn't know about change of collection's items. To check it run `isChanged` method.
     *
     * @var bool
     */
    private $changed = false;

    /**
     * Clear cache - one position or all of them.
     *
     * @param string|null $key position to clear, when null clear all
     */
    public static function clearCache($key = null)
    {
        if (null === $key) {
            self::$cache = array();

            return;
        }

        if (self::hasCache($key)) {
            unset(self::$cache[$key]);
        }
    }

    /**
     * Detect type of block.
     *
     * @param Token $token token
     *
     * @return null|array array with 'type' and 'isStart' keys or null if not found
     */
    public static function detectBlockType(Token $token)
    {
        foreach (self::getBlockEdgeDefinitions() as $type => $definition) {
            if ($token->equals($definition['start'])) {
                return array('type' => $type, 'isStart' => true);
            }

            if ($token->equals($definition['end'])) {
                return array('type' => $type, 'isStart' => false);
            }
        }
    }

    /**
     * Create token collection from array.
     *
     * @param array $array       the array to import
     * @param bool  $saveIndexes save the numeric indexes used in the original array, default is yes
     *
     * @return Tokens
     */
    public static function fromArray($array, $saveIndexes = null)
    {
        $tokens = new self(count($array));

        if (null === $saveIndexes || $saveIndexes) {
            foreach ($array as $key => $val) {
                $tokens[$key] = $val;
            }

            return $tokens;
        }

        $index = 0;

        foreach ($array as $val) {
            $tokens[$index++] = $val;
        }

        return $tokens;
    }

    /**
     * Create token collection directly from code.
     *
     * @param string $code PHP code
     *
     * @return Tokens
     */
    public static function fromCode($code)
    {
        $codeHash = crc32($code);

        if (self::hasCache($codeHash)) {
            $tokens = self::getCache($codeHash);

            // generate the code to recalculate the hash
            $tokens->generateCode();

            if ($codeHash === $tokens->codeHash) {
                $tokens->clearEmptyTokens();
                $tokens->clearChanged();

                return $tokens;
            }
        }

        $tokens = token_get_all($code);

        foreach ($tokens as $index => $tokenPrototype) {
            $tokens[$index] = new Token($tokenPrototype);
        }

        $collection = self::fromArray($tokens);
        $transformers = Transformers::create();
        $transformers->transform($collection);
        $collection->changeCodeHash($codeHash);
        $collection->clearChanged();

        return $collection;
    }

    /**
     * Return block edge definitions.
     *
     * @return array
     */
    public static function getBlockEdgeDefinitions()
    {
        return array(
            self::BLOCK_TYPE_CURLY_BRACE => array(
                'start' => '{',
                'end'   => '}',
            ),
            self::BLOCK_TYPE_PARENTHESIS_BRACE => array(
                'start' => '(',
                'end'   => ')',
            ),
            self::BLOCK_TYPE_INDEX_SQUARE_BRACE => array(
                'start' => '[',
                'end'   => ']',
            ),
            self::BLOCK_TYPE_ARRAY_SQUARE_BRACE => array(
                'start' => array(CT_ARRAY_SQUARE_BRACE_OPEN, '['),
                'end'   => array(CT_ARRAY_SQUARE_BRACE_CLOSE, ']'),
            ),
            self::BLOCK_TYPE_DYNAMIC_PROP_BRACE => array(
                'start' => array(CT_DYNAMIC_PROP_BRACE_OPEN, '{'),
                'end' => array(CT_DYNAMIC_PROP_BRACE_CLOSE, '}'),
            ),
        );
    }

    /**
     * Get cache value for given key.
     *
     * @param string $key item key
     *
     * @return Tokens
     */
    private static function getCache($key)
    {
        if (!self::hasCache($key)) {
            throw new \OutOfBoundsException('Unknown cache key: '.$key);
        }

        return self::$cache[$key];
    }

    /**
     * Check if given key exists in cache.
     *
     * @param string $key item key
     *
     * @return bool
     */
    private static function hasCache($key)
    {
        return isset(self::$cache[$key]);
    }

    /**
     * Set cache item.
     *
     * @param string $key   item key
     * @param Tokens $value item value
     */
    private static function setCache($key, Tokens $value)
    {
        self::$cache[$key] = $value;
    }

    /**
     * Set new size of collection.
     *
     * @param int $size
     */
    public function setSize($size)
    {
        if ($this->getSize() !== $size) {
            $this->changed = true;
            parent::setSize($size);
        }
    }

    /**
     * Unset collection item.
     *
     * @param int $index
     */
    public function offsetUnset($index)
    {
        $this->changed = true;
        parent::offsetUnset($index);
    }

    /**
     * Set collection item.
     *
     * Warning! `$newval` must not be typehinted to be compatible with `ArrayAccess::offsetSet` method.
     *
     * @param int   $index
     * @param Token $newval
     *
     * @warning
     */
    public function offsetSet($index, $newval)
    {
        $this->changed = true;
        parent::offsetSet($index, $newval);
    }

    /**
     * Change code hash.
     *
     * Remove old cache and set new one.
     *
     * @param string $codeHash new code hash
     */
    private function changeCodeHash($codeHash)
    {
        if (null !== $this->codeHash) {
            self::clearCache($this->codeHash);
        }

        $this->codeHash = $codeHash;
        self::setCache($this->codeHash, $this);
    }

    /**
     * Clear internal flag if collection was changed and flag for all collection's items.
     */
    public function clearChanged()
    {
        $this->changed = false;

        foreach ($this as $token) {
            $token->clearChanged();
        }
    }

    /**
     * Clear empty tokens.
     *
     * Empty tokens can occur e.g. after calling clear on item of collection.
     */
    public function clearEmptyTokens()
    {
        $limit = $this->count();
        $index = 0;

        for (; $index < $limit; ++$index) {
            if ($this[$index]->isEmpty()) {
                break;
            }
        }

        // no empty token found, therefore there is no need to override collection
        if ($limit === $index) {
            return;
        }

        for ($count = $index; $index < $limit; ++$index) {
            $token = $this[$index];

            if (!$token->isEmpty()) {
                $this[$count++] = $token;
            }
        }

        $this->setSize($count);
    }

    /**
     * Ensure that on given index is a whitespace with given kind.
     *
     * If there is a whitespace then it's content will be modified.
     * If not - the new Token will be added.
     *
     * @param int    $index       index
     * @param int    $indexOffset index offset for Token insertion
     * @param string $whitespace  whitespace to set
     *
     * @return bool if new Token was added
     */
    public function ensureWhitespaceAtIndex($index, $indexOffset, $whitespace)
    {
        $removeLastCommentLine = function (Token $token, $indexOffset) {
            // because comments tokens are greedy and may consume single \n if we are putting whitespace after it let trim that \n
            if (1 === $indexOffset && $token->isGivenKind(array(T_COMMENT, T_DOC_COMMENT))) {
                $content = $token->getContent();

                if ("\n" === $content[strlen($content) - 1]) {
                    $token->setContent(substr($content, 0, -1));
                }
            }
        };

        $token = $this[$index];

        if ($token->isWhitespace()) {
            $removeLastCommentLine($this[$index - 1], $indexOffset);
            $token->override(array(T_WHITESPACE, $whitespace));

            return false;
        }

        $removeLastCommentLine($token, $indexOffset);

        $this->insertAt(
            $index + $indexOffset,
            array(
                new Token(array(T_WHITESPACE, $whitespace)),
            )
        );

        return true;
    }

    /**
     * Find block end.
     *
     * @param int  $type        type of block, one of BLOCK_TYPE_*
     * @param int  $searchIndex index of opening brace
     * @param bool $findEnd     if method should find block's end, default true, otherwise method find block's start
     *
     * @return int index of closing brace
     */
    public function findBlockEnd($type, $searchIndex, $findEnd = true)
    {
        $blockEdgeDefinitions = self::getBlockEdgeDefinitions();

        if (!isset($blockEdgeDefinitions[$type])) {
            throw new \InvalidArgumentException('Invalid param $type');
        }

        $startEdge = $blockEdgeDefinitions[$type]['start'];
        $endEdge = $blockEdgeDefinitions[$type]['end'];
        $startIndex = $searchIndex;
        $endIndex = $this->count() - 1;
        $indexOffset = 1;

        if (!$findEnd) {
            list($startEdge, $endEdge) = array($endEdge, $startEdge);
            $indexOffset = -1;
            $endIndex = 0;
        }

        if (!$this[$startIndex]->equals($startEdge)) {
            throw new \InvalidArgumentException('Invalid param $startIndex - not a proper block start');
        }

        $blockLevel = 0;

        for ($index = $startIndex; $index !== $endIndex; $index += $indexOffset) {
            $token = $this[$index];

            if ($token->equals($startEdge)) {
                ++$blockLevel;

                continue;
            }

            if ($token->equals($endEdge)) {
                --$blockLevel;

                if (0 === $blockLevel) {
                    break;
                }

                continue;
            }
        }

        if (!$this[$index]->equals($endEdge)) {
            throw new \UnexpectedValueException('Missing block end');
        }

        return $index;
    }

    /**
     * Find tokens of given kind.
     *
     * @param int|array $possibleKind kind or array of kind
     *
     * @return array array of tokens of given kinds or assoc array of arrays
     */
    public function findGivenKind($possibleKind)
    {
        $this->rewind();

        $elements = array();
        $possibleKinds = (array) $possibleKind;

        foreach ($possibleKinds as $kind) {
            $elements[$kind] = array();
        }

        foreach ($this as $index => $token) {
            if ($token->isGivenKind($possibleKinds)) {
                $elements[$token->getId()][$index] = $token;
            }
        }

        return is_array($possibleKind) ? $elements : $elements[$possibleKind];
    }

    /**
     * Generate code from tokens.
     *
     * @return string
     */
    public function generateCode()
    {
        $code = $this->generatePartialCode(0, count($this) - 1);
        $this->changeCodeHash(crc32($code));

        return $code;
    }

    /**
     * Generate code from tokens between given indexes.
     *
     * @param int $start start index
     * @param int $end   end index
     *
     * @return string
     */
    public function generatePartialCode($start, $end)
    {
        $code = '';

        for ($i = $start; $i <= $end; ++$i) {
            $code .= $this[$i]->getContent();
        }

        return $code;
    }

    /**
     * Get hash of code.
     *
     * @return string
     */
    public function getCodeHash()
    {
        return $this->codeHash;
    }

    /**
     * Get index for closest next token which is non whitespace.
     *
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param int   $index token index
     * @param array $opts  array of extra options for isWhitespace method
     *
     * @return int|null
     */
    public function getNextNonWhitespace($index, array $opts = array())
    {
        return $this->getNonWhitespaceSibling($index, 1, $opts);
    }

    /**
     * Get index for closest next token of given kind.
     *
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param int         $index         token index
     * @param array       $tokens        possible tokens
     * @param bool|bool[] $caseSensitive global case sensitiveness or an array of booleans, whose keys should match
     *                                   the ones used in $others. If any is missing, the default case-sensitive
     *                                   comparison is used.
     *
     * @return int|null
     */
    public function getNextTokenOfKind($index, array $tokens = array(), $caseSensitive = true)
    {
        return $this->getTokenOfKindSibling($index, 1, $tokens, $caseSensitive);
    }

    /**
     * Get index for closest sibling token which is non whitespace.
     *
     * @param int   $index     token index
     * @param int   $direction direction for looking, +1 or -1
     * @param array $opts      array of extra options for isWhitespace method
     *
     * @return int|null
     */
    public function getNonWhitespaceSibling($index, $direction, array $opts = array())
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return;
            }

            $token = $this[$index];

            if (!$token->isWhitespace($opts)) {
                return $index;
            }
        }
    }

    /**
     * Get index for closest previous token which is non whitespace.
     *
     * This method is shorthand for getNonWhitespaceSibling method.
     *
     * @param int   $index token index
     * @param array $opts  array of extra options for isWhitespace method
     *
     * @return int|null
     */
    public function getPrevNonWhitespace($index, array $opts = array())
    {
        return $this->getNonWhitespaceSibling($index, -1, $opts);
    }

    /**
     * Get index for closest previous token of given kind.
     * This method is shorthand for getTokenOfKindSibling method.
     *
     * @param int         $index         token index
     * @param array       $tokens        possible tokens
     * @param bool|bool[] $caseSensitive global case sensitiveness or an array of booleans, whose keys should match
     *                                   the ones used in $others. If any is missing, the default case-sensitive
     *                                   comparison is used.
     *
     * @return int|null
     */
    public function getPrevTokenOfKind($index, array $tokens = array(), $caseSensitive = true)
    {
        return $this->getTokenOfKindSibling($index, -1, $tokens, $caseSensitive);
    }

    /**
     * Get index for closest sibling token of given kind.
     *
     * @param int         $index         token index
     * @param int         $direction     direction for looking, +1 or -1
     * @param array       $tokens        possible tokens
     * @param bool|bool[] $caseSensitive global case sensitiveness or an array of booleans, whose keys should match
     *                                   the ones used in $others. If any is missing, the default case-sensitive
     *                                   comparison is used.
     *
     * @return int|null
     */
    public function getTokenOfKindSibling($index, $direction, array $tokens = array(), $caseSensitive = true)
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return;
            }

            $token = $this[$index];

            if ($token->equalsAny($tokens, $caseSensitive)) {
                return $index;
            }
        }
    }

    /**
     * Get index for closest sibling token not of given kind.
     *
     * @param int   $index     token index
     * @param int   $direction direction for looking, +1 or -1
     * @param array $tokens    possible tokens
     *
     * @return int|null
     */
    public function getTokenNotOfKindSibling($index, $direction, array $tokens = array())
    {
        while (true) {
            $index += $direction;

            if (!$this->offsetExists($index)) {
                return;
            }

            $token = $this[$index];

            if ($token->isEmpty()) {
                continue;
            }

            if ($token->equalsAny($tokens)) {
                continue;
            }

            return $index;
        }
    }

    /**
     * Get index for closest sibling token that is not a whitespace or comment.
     *
     * @param int $index     token index
     * @param int $direction direction for looking, +1 or -1
     *
     * @return int|null
     */
    public function getMeaningfulTokenSibling($index, $direction)
    {
        return $this->getTokenNotOfKindSibling(
            $index,
            $direction,
            array(array(T_WHITESPACE), array(T_COMMENT), array(T_DOC_COMMENT))
        );
    }

    /**
     * Get index for closest next token that is not a whitespace or comment.
     *
     * @param int $index token index
     *
     * @return int|null
     */
    public function getNextMeaningfulToken($index)
    {
        return $this->getMeaningfulTokenSibling($index, 1);
    }

    /**
     * Get index for closest previous token that is not a whitespace or comment.
     *
     * @param int $index token index
     *
     * @return int|null
     */
    public function getPrevMeaningfulToken($index)
    {
        return $this->getMeaningfulTokenSibling($index, -1);
    }

    /**
     * Find a sequence of meaningful tokens and returns the array of their locations.
     *
     * @param array      $sequence      an array of tokens (same format used by getNextTokenOfKind)
     * @param int        $start         start index, defaulting to the start of the file
     * @param int        $end           end index, defaulting to the end of the file
     * @param bool|array $caseSensitive global case sensitiveness or an array of booleans, whose keys should match
     *                                  the ones used in $others. If any is missing, the default case-sensitive
     *                                  comparison is used.
     *
     * @return array|null an array containing the tokens matching the sequence elements, indexed by their position
     */
    public function findSequence(array $sequence, $start = 0, $end = null, $caseSensitive = true)
    {
        // $end defaults to the end of the collection
        if (null === $end) {
            $end = count($this) - 1;
        }

        if (!count($sequence)) {
            throw new \InvalidArgumentException('Invalid sequence');
        }

        // make sure the sequence content is "meaningful"
        foreach ($sequence as $key => $token) {
            // if not a Token instance already, we convert it to verify the meaningfulness
            if (!$token instanceof Token) {
                if (is_array($token) && !isset($token[1])) {
                    // fake some content as it is required by the Token constructor,
                    // although optional for search purposes
                    $token[1] = '';
                }
                $token = new Token($token);
            }
            if ($token->isWhitespace() || $token->isComment() || $token->isEmpty()) {
                throw new \InvalidArgumentException('Non-meaningful token at position: '.$key);
            }
        }

        // remove the first token from the sequence, so we can freely iterate through the sequence after a match to
        // the first one is found
        $key = key($sequence);
        $firstCs = Token::isKeyCaseSensitive($caseSensitive, $key);
        $firstToken = $sequence[$key];
        unset($sequence[$key]);

        // begin searching for the first token in the sequence (start included)
        $index = $start - 1;
        while (null !== $index && $index <= $end) {
            $index = $this->getNextTokenOfKind($index, array($firstToken), $firstCs);

            // ensure we found a match and didn't get past the end index
            if (null === $index || $index > $end) {
                return;
            }

            // initialise the result array with the current index
            $result = array($index => $this[$index]);

            // advance cursor to the current position
            $currIdx = $index;

            // iterate through the remaining tokens in the sequence
            foreach ($sequence as $key => $token) {
                $currIdx = $this->getNextMeaningfulToken($currIdx);

                // ensure we didn't go too far
                if (null === $currIdx || $currIdx > $end) {
                    return;
                }

                if (!$this[$currIdx]->equals($token, Token::isKeyCaseSensitive($caseSensitive, $key))) {
                    // not a match, restart the outer loop
                    continue 2;
                }

                // append index to the result array
                $result[$currIdx] = $this[$currIdx];
            }

            // do we have a complete match?
            // hint: $result is bigger than $sequence since the first token has been removed from the latter
            if (count($sequence) < count($result)) {
                return $result;
            }
        }
    }

    /**
     * Insert instances of Token inside collection.
     *
     * @param int                  $index start inserting index
     * @param Tokens|Token[]|Token $items instances of Token to insert
     */
    public function insertAt($index, $items)
    {
        $items = is_array($items) || $items instanceof self ? $items : array($items);
        $itemsCnt = count($items);

        if (0 === $itemsCnt) {
            return;
        }

        $oldSize = count($this);
        $this->changed = true;
        $this->setSize($oldSize + $itemsCnt);

        for ($i = $oldSize + $itemsCnt - 1; $i >= $index; --$i) {
            $this[$i] = isset($this[$i - $itemsCnt]) ? $this[$i - $itemsCnt] : new Token('');
        }

        for ($i = 0; $i < $itemsCnt; ++$i) {
            if ('' === $items[$i]->getContent()) {
                throw new \InvalidArgumentException('Must not add empty item to collection');
            }

            $this[$i + $index] = $items[$i];
        }
    }

    /**
     * Check if collection was change: collection itself (like insert new tokens) or any of collection's elements.
     *
     * @return bool
     */
    public function isChanged()
    {
        if ($this->changed) {
            return true;
        }

        foreach ($this as $token) {
            if ($token->isChanged()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Override tokens at given range.
     *
     * @param int            $indexStart start overriding index
     * @param int            $indexEnd   end overriding index
     * @param Tokens|Token[] $items      tokens to insert
     */
    public function overrideRange($indexStart, $indexEnd, $items)
    {
        $oldCode = $this->generatePartialCode($indexStart, $indexEnd);

        $newCode = '';
        foreach ($items as $item) {
            $newCode .= $item->getContent();
        }

        // no changes, return
        if ($oldCode === $newCode) {
            return;
        }

        $indexToChange = $indexEnd - $indexStart + 1;
        $itemsCount = count($items);

        // If we want to add more items than passed range contains we need to
        // add placeholders for overhead items.
        if ($itemsCount > $indexToChange) {
            $placeholders = array();
            while ($itemsCount > $indexToChange) {
                $placeholders[] = new Token('__PLACEHOLDER__');
                ++$indexToChange;
            }
            $this->insertAt($indexEnd + 1, $placeholders);
        }

        // Override each items.
        foreach ($items as $itemIndex => $item) {
            $this[$indexStart + $itemIndex]->override($item);
        }

        // If we want to add less tokens than passed range contains then clear
        // not needed tokens.
        if ($itemsCount < $indexToChange) {
            for ($i = $indexStart + $itemsCount; $i <= $indexEnd; ++$i) {
                $this[$i]->clear();
            }
        }
    }

    /**
     * If $index is below zero, we know that it does not exist.
     *
     * This was added to be compatible with HHVM 3.2.0.
     * Note that HHVM 3.3.0 no longer requires this work around.
     *
     * @param int $index
     *
     * @return bool
     */
    public function offsetExists($index)
    {
        return $index >= 0 && parent::offsetExists($index);
    }

    /**
     * Removes all the leading whitespace.
     *
     * @param int   $index
     * @param array $opts  optional array of extra options for Token::isWhitespace method
     */
    public function removeLeadingWhitespace($index, array $opts = array())
    {
        if (isset($this[$index - 1]) && $this[$index - 1]->isWhitespace($opts)) {
            $this[$index - 1]->clear();
        }
    }

    /**
     * Removes all the trailing whitespace.
     *
     * @param int   $index
     * @param array $opts  optional array of extra options for Token::isWhitespace method
     */
    public function removeTrailingWhitespace($index, array $opts = array())
    {
        if (isset($this[$index + 1]) && $this[$index + 1]->isWhitespace($opts)) {
            $this[$index + 1]->clear();
        }
    }

    /**
     * Set code. Clear all current content and replace it by new Token items generated from code directly.
     *
     * @param string $code PHP code
     */
    public function setCode($code)
    {
        // No need to work when the code is the same.
        // That is how we avoid a lot of work and setting changed flag.
        if ($code === $this->generateCode()) {
            return;
        }

        // clear memory
        $this->setSize(0);

        $tokens = token_get_all($code);
        $this->setSize(count($tokens));

        foreach ($tokens as $index => $token) {
            $this[$index] = new Token($token);
        }

        $transformers = Transformers::create();
        $transformers->transform($this);

        $this->rewind();
        $this->changeCodeHash(crc32($code));
        $this->changed = true;
    }

    public function toJson()
    {
        static $options = null;

        if (null === $options) {
            $options = Utils::calculateBitmask(array('JSON_PRETTY_PRINT', 'JSON_NUMERIC_CHECK'));
        }

        $output = new \SplFixedArray(count($this));

        foreach ($this as $index => $token) {
            $output[$index] = $token->toArray();
        }

        $this->rewind();

        return json_encode($output, $options);
    }

    /**
     * Clone tokens collection.
     */
    public function __clone()
    {
        foreach ($this as $key => $val) {
            $this[$key] = clone $val;
        }
    }

    /**
     * Clear tokens in the given range.
     *
     * @param int $indexStart
     * @param int $indexEnd
     */
    public function clearRange($indexStart, $indexEnd)
    {
        for ($i = $indexStart; $i <= $indexEnd; ++$i) {
            $this[$i]->clear();
        }
    }

    /**
     * Checks for monolithic PHP code.
     *
     * Checks that the code is pure PHP code, in a single code block, starting
     * with an open tag.
     *
     * @return bool
     */
    public function isMonolithicPhp()
    {
        $kinds = $this->findGivenKind(array(T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_INLINE_HTML));

        /*
         * Fix HHVM incompatibilities
         */
        $hhvmOpenTagsWithEcho = array();
        $hhvmHashBangs = array();

        if (defined('HHVM_VERSION')) {
            /*
             * HHVM parses '<?=' as T_ECHO instead of T_OPEN_TAG_WITH_ECHO
             *
             * @see https://github.com/facebook/hhvm/issues/4809
             */
            $hhvmEchoes = $this->findGivenKind(T_ECHO);
            foreach ($hhvmEchoes as $token) {
                if (0 === strpos($token->getContent(), '<?=')) {
                    $hhvmOpenTagsWithEcho[] = $token;
                }
            }

            /*
             * HHVM parses "#!/usr/bin/env php\n" as T_HASHBANG (not defined in
             * PHP and T_HASHBANG. Moreover, HHVM does not define T_HASHBANG
             * as a constant
             *
             * @see https://github.com/facebook/hhvm/issues/4810
             */
            $tokens = self::fromCode("#!/usr/bin/env php\n");
            if (!$tokens[0]->isGivenKind(T_INLINE_HTML)) {
                $hashBangId = $tokens[0]->getId();
                $hhvmHashBangs = $this->findGivenKind($hashBangId);
            }
        }

        return 0 === count($kinds[T_INLINE_HTML]) + count($hhvmHashBangs) && 1 === count($kinds[T_OPEN_TAG]) + count($kinds[T_OPEN_TAG_WITH_ECHO]) + count($hhvmOpenTagsWithEcho);
    }
}
