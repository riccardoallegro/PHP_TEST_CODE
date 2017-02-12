<?php

namespace PhpIntegrator\UserInterface\Command;

use ArrayAccess;

use PhpIntegrator\Parsing\PartialParser;

use PhpIntegrator\Utility\SourceCodeHelpers;
use PhpIntegrator\Utility\SourceCodeStreamReader;

/**
 * Allows fetching invocation information of a method or function call.
 */
class InvocationInfoCommand extends AbstractCommand
{
    /**
     * @var PartialParser
     */
    protected $partialParser;

    /**
     * @var SourceCodeStreamReader
     */
    protected $sourceCodeStreamReader;

    /**
     * @param PartialParser          $partialParser
     * @param SourceCodeStreamReader $sourceCodeStreamReader
     */
    public function __construct(PartialParser $partialParser, SourceCodeStreamReader $sourceCodeStreamReader)
    {
        $this->partialParser = $partialParser;
        $this->sourceCodeStreamReader = $sourceCodeStreamReader;
    }

    /**
     * @inheritDoc
     */
    public function execute(ArrayAccess $arguments)
    {
        if (!isset($arguments['offset'])) {
            throw new InvalidArgumentsException('An --offset must be supplied into the source code!');
        }

        $code = null;

        if (isset($arguments['stdin']) && $arguments['stdin']) {
            $code = $this->sourceCodeStreamReader->getSourceCodeFromStdin();
        } elseif (isset($arguments['file']) && $arguments['file']) {
            $code = $this->sourceCodeStreamReader->getSourceCodeFromFile($arguments['file']);
        } else {
            throw new InvalidArgumentsException('Either a --file file must be supplied or --stdin must be passed!');
        }

        $offset = $arguments['offset'];

        if (isset($arguments['charoffset']) && $arguments['charoffset'] == true) {
            $offset = SourceCodeHelpers::getByteOffsetFromCharacterOffset($offset, $code);
        }

        $result = $this->getInvocationInfoAt($code, $offset);

        return $result;
    }

    /**
     * @param string $code
     * @param int    $offset
     *
     * @return array
     */
    public function getInvocationInfoAt($code, $offset)
    {
        return $this->getInvocationInfo(substr($code, 0, $offset));
    }

    /**
     * @param string $code
     *
     * @return array
     */
    public function getInvocationInfo($code)
    {
        return $this->partialParser->getInvocationInfoAt($code);
    }
}
