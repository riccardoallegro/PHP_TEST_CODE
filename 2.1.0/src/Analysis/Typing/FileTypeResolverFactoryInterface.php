<?php

namespace PhpIntegrator\Analysis\Typing;

use UnexpectedValueException;

/**
 * Interface for factories that creates instances of {@see FileTypeResolver}.
 */
interface FileTypeResolverFactoryInterface
{
    /**
     * @param string $filePath
     *
     * @throws UnexpectedValueException if no namespaces exist for a file.
     *
     * @return FileTypeResolver
     */
    public function create($filePath);
}
