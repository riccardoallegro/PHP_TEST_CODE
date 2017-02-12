<?php

namespace PhpIntegrator\Parsing;

use LogicException;
use UnexpectedValueException;

use PhpIntegrator\Utility\NodeHelpers;

use PhpParser\Node;
use PhpParser\Lexer;
use PhpParser\Parser;
use PhpParser\ErrorHandler;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinterAbstract;

/**
 * Parses partial (incomplete) PHP code.
 *
 * This class can parse PHP code that is incomplete (and thus erroneous), which is only partially supported by
 * php-parser. This is necessary for being able to deal with incomplete expressions such as "$this->" to see what the
 * type of the expression is. This information can in turn be used by client functionality such as autocompletion.
 */
class PartialParser implements Parser
{
    /**
     * @var Parser
     */
    protected $strictParser;

    /**
     * @var ParserFactory
     */
    protected $parserFactory;

    /**
     * @var PrettyPrinterAbstract
     */
    protected $prettyPrinter;

    /**
     * @param ParserFactory         $parserFactory
     * @param PrettyPrinterAbstract $prettyPrinter
     */
    public function __construct(ParserFactory $parserFactory, PrettyPrinterAbstract $prettyPrinter)
    {
        $this->parserFactory = $parserFactory;
        $this->prettyPrinter = $prettyPrinter;
    }

    /**
     * Retrieves the start of the expression (as byte offset) that ends at the end of the specified source code string.
     *
     * @param string $code
     *
     * @return int
     */
    public function getStartOfExpression($code)
    {
        if (empty($code)) {
            return 0;
        }

        $parenthesesOpened = 0;
        $parenthesesClosed = 0;
        $squareBracketsOpened = 0;
        $squareBracketsClosed = 0;
        $squiggleBracketsOpened = 0;
        $squiggleBracketsClosed = 0;

        $startedStaticClassName = false;

        $token = null;
        $tokens = @token_get_all($code);
        $currentTokenIndex = count($tokens);
        $tokenStartOffset = strlen($code);

        $skippableTokens = $this->getSkippableTokens();
        $castBoundaryTokens = $this->getCastBoundaryTokens();
        $expressionBoundaryTokens = $this->getExpressionBoundaryTokens();

        // Characters that include operators that are, for some reason, not token types...
        $expressionBoundaryCharacters = [
            '.', ',', '?', ';', '=', '+', '-', '*', '/', '<', '>', '%', '|', '&', '^', '~', '!', '@'
        ];

        for ($i = strlen($code) - 1; $i >= 0; --$i) {
            if ($i < $tokenStartOffset) {
                $token = $tokens[--$currentTokenIndex];

                $tokenString = is_array($token) ? $token[1] : $token;
                $tokenStartOffset = ($i + 1) - strlen($tokenString);

                $token = [
                    'type' => is_array($token) ? $token[0] : null,
                    'text' => $tokenString
                ];
            }

            if (in_array($token['type'], $skippableTokens)) {
                // Do nothing, we just keep parsing. (These can occur inside call stacks.)
            } elseif ($code[$i] === '(') {
                ++$parenthesesOpened;

                // Ticket #164 - We're walking backwards, if we find an opening paranthesis that hasn't been closed
                // anywhere, we know we must stop.
                if ($parenthesesOpened > $parenthesesClosed) {
                    return ++$i;
                }
            } elseif ($code[$i] === ')') {
                if (in_array($token['type'], $castBoundaryTokens)) {
                    return ++$i;
                }

                ++$parenthesesClosed;
            }

            elseif ($code[$i] === '[') {
                ++$squareBracketsOpened;

                // Same as above.
                if ($squareBracketsOpened > $squareBracketsClosed) {
                    return ++$i;
                }
            } elseif ($code[$i] === ']') {
                ++$squareBracketsClosed;
            } elseif ($code[$i] === '{') {
                ++$squiggleBracketsOpened;

                // Same as above.
                if ($squiggleBracketsOpened > $squiggleBracketsClosed) {
                    return ++$i;
                }
            } elseif ($code[$i] === '}') {
                ++$squiggleBracketsClosed;

                if ($parenthesesOpened === $parenthesesClosed && $squareBracketsOpened === $squareBracketsClosed) {
                    $nextToken = $currentTokenIndex > 0 ? $tokens[$currentTokenIndex - 1] : null;
                    $nextTokenType = is_array($nextToken) ? $nextToken[0] : null;

                    // Subscopes can only exist when e.g. a closure is embedded as an argument to a function call,
                    // in which case they will be inside parentheses or brackets. If we find a subscope outside these
                    // simbols, it means we've moved beyond the call stack to e.g. the end of an if statement.
                    if ($nextTokenType !== T_VARIABLE) {
                        return ++$i;
                    }
                }
            } elseif (
                $parenthesesOpened === $parenthesesClosed &&
                $squareBracketsOpened === $squareBracketsClosed &&
                $squiggleBracketsOpened === $squiggleBracketsClosed
            ) {
                // NOTE: We may have entered a closure.
                if (
                    in_array($token['type'], $expressionBoundaryTokens) ||
                    (in_array($code[$i], $expressionBoundaryCharacters, true) && $token['type'] === null) ||
                    ($code[$i] === ':' && $token['type'] !== T_DOUBLE_COLON)
                ) {
                    return ++$i;
                } elseif ($token['type'] === T_DOUBLE_COLON) {
                    // For static class names and things like the self and parent keywords, we won't know when to stop.
                    // These always appear the start of the call stack, so we know we can stop if we find them.
                    $startedStaticClassName = true;
                }
            }

            if ($startedStaticClassName && !in_array($token['type'], [T_DOUBLE_COLON, T_STRING, T_NS_SEPARATOR, T_STATIC])) {
                return ++$i;
            }
        }

        return $i;
    }

    /**
     * Retrieves the last node (i.e. expression, statement, ...) at the specified location.
     *
     * This will also attempt to deal with incomplete expressions and statements.
     *
     * @param string   $source
     * @param int|null $offset
     *
     * @return Node|null
     */
    public function getLastNodeAt($source, $offset = null)
    {
        if ($offset !== null) {
            $source = substr($source, 0, $offset);
        }

        $nodes = $this->parse($source);

        return array_shift($nodes);
    }

    /**
     * @inheritDoc
     */
    public function parse($code, ErrorHandler $errorHandler = null)
    {
        if ($errorHandler) {
            throw new LogicException('Error handling is not supported as error recovery will be attempted automatically');
        }

        $code = $this->getNormalizedCode($code);
        $boundary = $this->getStartOfExpression($code);

        $expression = substr($code, $boundary);
        $expression = trim($expression);

        if ($expression === '') {
            throw new \PhpParser\Error('Could not parse last expression for code, the last expression was "' . $expression . '"');
        }

        $correctedExpression = $this->getNormalizedCode($expression);

        $nodes = $this->tryParse($correctedExpression);
        $nodes = $nodes ?: $this->tryParseWithKeywordCorrection($correctedExpression);
        $nodes = $nodes ?: $this->tryParseWithTrailingSemicolonCorrection($correctedExpression);
        $nodes = $nodes ?: $this->tryParseWithHeredocTerminationCorrection($correctedExpression);
        $nodes = $nodes ?: $this->tryParseWithDummyInsertion($correctedExpression);

        if (empty($nodes)) {
            throw new \PhpParser\Error('Could not parse the code, even after attempting corrections');
        } elseif (count($nodes) > 1) {
            throw new \PhpParser\Error('Parsing succeeded, but more than one node was returned for a single expression');
        }

        return $nodes;
    }

    /**
     * @param string $code
     *
     * @return string
     */
    protected function getNormalizedCode($code)
    {
        if (mb_substr(trim($code), 0, 5) !== '<?php') {
            return '<?php ' . $code;
        };

        return $code;
    }

    /**
     * @param string $code
     *
     * @return Node[]|null
     */
    protected function tryParseWithKeywordCorrection($code)
    {
        if (mb_strrpos($code, 'self') === (mb_strlen($code) - mb_strlen('self'))) {
            return [new \PhpIntegrator\Parsing\Node\Keyword\Self_()];
        } elseif (mb_strrpos($code, 'static') === (mb_strlen($code) - mb_strlen('static'))) {
            return [new \PhpIntegrator\Parsing\Node\Keyword\Static_()];
        } elseif (mb_strrpos($code, 'parent') === (mb_strlen($code) - mb_strlen('parent'))) {
            return [new \PhpIntegrator\Parsing\Node\Keyword\Parent_()];
        }

        return null;
    }

    /**
     * @param string $code
     *
     * @return Node[]|null
     */
    protected function tryParseWithTrailingSemicolonCorrection($code)
    {
        return $this->tryParse($code . ';');
    }

    /**
     * @param string $code
     *
     * @return Node[]|null
     */
    protected function tryParseWithHeredocTerminationCorrection($code)
    {
        return $this->tryParse($code . ";\n"); // Heredocs need to be suffixed by a semicolon and a newline.
    }

    /**
     * @param string $code
     *
     * @return Node[]|null
     */
    protected function tryParseWithDummyInsertion($code)
    {
        $removeDummy = false;
        $dummyName = '____DUMMY____';

        $nodes = $this->tryParse($code . $dummyName . ';');

        if (empty($nodes)) {
            return null;
        }

        $node = $nodes[count($nodes) - 1];

        if ($node instanceof Node\Expr\ClassConstFetch || $node instanceof Node\Expr\PropertyFetch) {
            if ($node->name === $dummyName) {
                $node->name = '';
            }
        }

        return $nodes;
    }

    /**
     * @param string $code
     *
     * @return Node[]|null
     */
    protected function tryParse($code)
    {
        try {
            return $this->getStrictParser()->parse($code);
        } catch (\PhpParser\Error $e) {
            return null;
        }

        return null;
    }

    /**
     * Retrieves the call stack of the function or method that is being invoked.
     *
     * This can be used to fetch information about the function or method call the cursor is in.
     *
     * @param string $code
     *
     * @return array|null With elements 'callStack' (array), 'argumentIndex', which denotes the argument in the
     *                    parameter list the position is located at, and offset which denotes the byte offset the
     *                    invocation was found at. Returns 'null' if not in a method or function call.
     */
    public function getInvocationInfoAt($code)
    {
        $scopesOpened = 0;
        $scopesClosed = 0;
        $bracketsOpened = 0;
        $bracketsClosed = 0;
        $parenthesesOpened = 0;
        $parenthesesClosed = 0;

        $argumentIndex = 0;

        $token = null;
        $tokens = @token_get_all($code);
        $currentTokenIndex = count($tokens);
        $tokenStartOffset = strlen($code);

        $skippableTokens = $this->getSkippableTokens();
        $expressionBoundaryTokens = $this->getExpressionBoundaryTokens();

        for ($i = strlen($code) - 1; $i >= 0; --$i) {
            if ($i < $tokenStartOffset) {
                $token = $tokens[--$currentTokenIndex];

                $tokenString = is_array($token) ? $token[1] : $token;
                $tokenStartOffset = ($i + 1) - strlen($tokenString);

                $token = [
                    'type' => is_array($token) ? $token[0] : null,
                    'text' => $tokenString
                ];
            }

            if (in_array($token['type'], $skippableTokens)) {
                continue;
            } elseif ($code[$i] === '}') {
                ++$scopesClosed;
            } elseif ($code[$i] === '{') {
                ++$scopesOpened;

                if ($scopesOpened > $scopesClosed) {
                    return null; // We reached the start of a block, we can never be in a method call.
                }
            } elseif ($code[$i] === ']') {
                ++$bracketsClosed;
            } elseif ($code[$i] === '[') {
                ++$bracketsOpened;

                if ($bracketsOpened > $bracketsClosed) {
                    // We must have been inside an array argument, reset.
                    $argumentIndex = 0;
                    --$bracketsOpened;
                }
            } elseif ($code[$i] === ')') {
                ++$parenthesesClosed;
            } elseif ($code[$i] === '(') {
                ++$parenthesesOpened;
            } elseif ($scopesOpened === $scopesClosed) {
                if ($code[$i] === ';') {
                    return null; // We've moved too far and reached another expression, stop here.
                } elseif ($code[$i] === ',') {
                    if ($parenthesesOpened === ($parenthesesClosed + 1)) {
                        // Pretend the parentheses were closed, the user is probably inside an argument that
                        // contains parentheses.
                        ++$parenthesesClosed;
                    }

                    if ($bracketsOpened >= $bracketsClosed && $parenthesesOpened === $parenthesesClosed) {
                        ++$argumentIndex;
                    }
                }
            }

            if ($scopesOpened === $scopesClosed && $parenthesesOpened === ($parenthesesClosed + 1)) {
                if (in_array($token['type'], $expressionBoundaryTokens)) {
                    break;
                }

                $node = null;

                try {
                    $node = $this->getLastNodeAt($code, $i);
                } catch (\PhpParser\Error $e) {
                    $node = null;
                }

                if ($node) {
                    $type = null;

                    if ($node instanceof Node\Expr\PropertyFetch ||
                        $node instanceof Node\Expr\StaticPropertyFetch ||
                        $node instanceof Node\Expr\MethodCall ||
                        $node instanceof Node\Expr\StaticCall ||
                        $node instanceof Node\Expr\ClassConstFetch
                    ) {
                        $type = 'method';
                    } else {
                        $type = 'function';

                        for ($j = $currentTokenIndex - 2; $j >= 0; --$j) {
                            if (
                                is_array($tokens[$j]) &&
                                in_array($tokens[$j][0], [T_WHITESPACE, T_NS_SEPARATOR, T_NEW, T_STRING])
                            ) {
                                if ($tokens[$j][0] === T_NEW) {
                                    $type = 'instantiation';
                                    break;
                                }


                                continue;
                            }

                            break;
                        }
                    }

                    $name = null;

                    if (isset($node->name)) {
                        if ($node->name instanceof Node\Expr) {
                            $name = $this->prettyPrinter->prettyPrintExpr($node->name);
                        } elseif ($node->name instanceof Node\Name) {
                            $name = NodeHelpers::fetchClassName($node->name);
                        } elseif (is_string($node->name)) {
                            $name = ((string) $node->name);
                        } else {
                            throw new UnexpectedValueException("Don't know how to handle type " . get_class($node->name));
                        }
                    } elseif ($node instanceof Node\Expr) {
                        $name = $this->prettyPrinter->prettyPrintExpr($node);
                    } else {
                        throw new UnexpectedValueException("Don't know how to handle node of type " . get_class($node));
                    }

                    return [
                        'name'           => $name,
                        'expression'     => $this->prettyPrinter->prettyPrintExpr($node),
                        'type'           => $type,
                        'argumentIndex'  => $argumentIndex,
                        'offset'         => $i
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @see https://secure.php.net/manual/en/tokens.php
     *
     * @return int[]
     */
    protected function getExpressionBoundaryTokens()
    {
        $expressionBoundaryTokens = [
            T_ABSTRACT, T_AND_EQUAL, T_AS, T_BOOLEAN_AND, T_BOOLEAN_OR, T_BREAK, T_CALLABLE, T_CASE, T_CATCH,
            T_CLONE, T_CLOSE_TAG, T_CONCAT_EQUAL, T_CONST, T_CONTINUE, T_DEC, T_DECLARE, T_DEFAULT, T_DIV_EQUAL, T_DO,
            T_DOUBLE_ARROW, T_ECHO, T_ELSE, T_ELSEIF, T_ENDDECLARE, T_ENDFOR, T_ENDFOREACH, T_ENDIF, T_ENDSWITCH,
            T_ENDWHILE, T_END_HEREDOC, T_EXIT, T_EXTENDS, T_FINAL, T_FOR, T_FOREACH, T_FUNCTION, T_GLOBAL, T_GOTO, T_IF,
            T_IMPLEMENTS, T_INC, T_INCLUDE, T_INCLUDE_ONCE, T_INSTANCEOF, T_INSTEADOF, T_INTERFACE, T_IS_EQUAL,
            T_IS_GREATER_OR_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL,
            T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_MINUS_EQUAL, T_MOD_EQUAL, T_MUL_EQUAL, T_NAMESPACE, T_NEW,
            T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_OR_EQUAL, T_PLUS_EQUAL, T_PRINT, T_PRIVATE, T_PUBLIC, T_PROTECTED,
            T_REQUIRE, T_REQUIRE_ONCE, T_RETURN, T_SL, T_SL_EQUAL, T_SR, T_SR_EQUAL, T_SWITCH, T_THROW, T_TRAIT, T_TRY,
            T_USE, T_VAR, T_WHILE, T_XOR_EQUAL
        ];

        // PHP >= 5.5
        if (defined('T_FINALLY')) {
            $expressionBoundaryTokens[] = T_FINALLY;
        }

        if (defined('T_YIELD')) {
            $expressionBoundaryTokens[] = T_YIELD;
        }

        // PHP >= 5.6
        if (defined('T_ELLIPSIS')) {
            $expressionBoundaryTokens[] = T_ELLIPSIS;
        }

        if (defined('T_POW')) {
            $expressionBoundaryTokens[] = T_POW;
        }

        if (defined('T_POW_EQUAL')) {
            $expressionBoundaryTokens[] = T_POW_EQUAL;
        }

        // PHP >= 7.0
        if (defined('T_SPACESHIP')) {
            $expressionBoundaryTokens[] = T_SPACESHIP;
        }

        return $expressionBoundaryTokens;
    }

    /**
     * @see https://secure.php.net/manual/en/tokens.php
     *
     * @return int[]
     */
    protected function getCastBoundaryTokens()
    {
        $expressionBoundaryTokens = [
            T_INT_CAST, T_UNSET_CAST, T_OBJECT_CAST, T_BOOL_CAST, T_ARRAY_CAST, T_DOUBLE_CAST, T_STRING_CAST
        ];

        return $expressionBoundaryTokens;
    }

    /**
     * @see https://secure.php.net/manual/en/tokens.php
     *
     * @return int[]
     */
    protected function getSkippableTokens()
    {
        $tokens = [
            T_COMMENT, T_DOC_COMMENT, T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_STRING
        ];

        return $tokens;
    }

    /**
     * @return Parser
     */
    protected function getStrictParser()
    {
        if (!$this->strictParser instanceof Parser) {
            $this->strictParser = $this->parserFactory->create(ParserFactory::PREFER_PHP7, new Lexer());
        }

        return $this->strictParser;
    }
}
