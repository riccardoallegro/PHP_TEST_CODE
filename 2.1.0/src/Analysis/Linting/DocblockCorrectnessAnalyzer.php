<?php

namespace PhpIntegrator\Analysis\Linting;

use UnexpectedValueException;

use PhpIntegrator\Analysis\DocblockAnalyzer;
use PhpIntegrator\Analysis\ClasslikeInfoBuilder;

use PhpIntegrator\Analysis\Typing\TypeAnalyzer;

use PhpIntegrator\Analysis\Visiting\OutlineFetchingVisitor;

use PhpIntegrator\Parsing\DocblockParser;

/**
 * Analyzes the correctness of docblocks.
 */
class DocblockCorrectnessAnalyzer implements AnalyzerInterface
{
    /**
     * @var OutlineFetchingVisitor
     */
    protected $outlineIndexingVisitor;

    /**
     * @var DocblockParser
     */
    protected $docblockParser;

    /**
     * @var TypeAnalyzer
     */
    protected $typeAnalyzer;

    /**
     * @var DocblockAnalyzer
     */
    protected $docblockAnalyzer;

    /**
     * @var ClasslikeInfoBuilder
     */
    protected $classlikeInfoBuilder;

    /**
     * @var array
     */
    protected $classCache = [];

    /**
     * @param string               $code
     * @param ClasslikeInfoBuilder $classlikeInfoBuilder
     * @param DocblockParser       $docblockParser
     * @param TypeAnalyzer         $typeAnalyzer
     * @param DocblockAnalyzer     $docblockAnalyzer
     */
    public function __construct(
        $code,
        ClasslikeInfoBuilder $classlikeInfoBuilder,
        DocblockParser $docblockParser,
        TypeAnalyzer $typeAnalyzer,
        DocblockAnalyzer $docblockAnalyzer
    ) {
        $this->classlikeInfoBuilder = $classlikeInfoBuilder;
        $this->docblockParser = $docblockParser;
        $this->typeAnalyzer = $typeAnalyzer;
        $this->docblockAnalyzer = $docblockAnalyzer;

        $this->outlineIndexingVisitor = new OutlineFetchingVisitor($typeAnalyzer, $code);
    }

    /**
     * @inheritDoc
     */
    public function getVisitors()
    {
        return [
            $this->outlineIndexingVisitor
        ];
    }

    /**
     * @inheritDoc
     */
    public function getOutput()
    {
        $docblockIssues = [
            'varTagMissing'           => [],
            'missingDocumentation'    => [],
            'parameterMissing'        => [],
            'parameterTypeMismatch'   => [],
            'superfluousParameter'    => [],
            'deprecatedCategoryTag'   => [],
            'deprecatedSubpackageTag' => [],
            'deprecatedLinkTag'       => []
        ];

        $structures = $this->outlineIndexingVisitor->getStructures();

        foreach ($structures as $structure) {
            $docblockIssues = array_merge_recursive(
                $docblockIssues,
                $this->analyzeStructureDocblock($structure)
            );

            foreach ($structure['methods'] as $method) {
                $docblockIssues = array_merge_recursive(
                    $docblockIssues,
                    $this->analyzeMethodDocblock($structure, $method)
                );
            }

            foreach ($structure['properties'] as $property) {
                $docblockIssues = array_merge_recursive(
                    $docblockIssues,
                    $this->analyzePropertyDocblock($structure, $property)
                );
            }

            foreach ($structure['constants'] as $constant) {
                $docblockIssues = array_merge_recursive(
                    $docblockIssues,
                    $this->analyzeClassConstantDocblock($structure, $constant)
                );
            }
        }

        $globalFunctions = $this->outlineIndexingVisitor->getGlobalFunctions();

        foreach ($globalFunctions as $function) {
            $docblockIssues = array_merge_recursive(
                $docblockIssues,
                $this->analyzeFunctionDocblock($function)
            );
        }

        return $docblockIssues;
    }

    /**
     * @param array $structure
     *
     * @return array
     */
    protected function analyzeStructureDocblock(array $structure)
    {
        $docblockIssues = [
            'missingDocumentation'    => [],
            'deprecatedCategoryTag'   => [],
            'deprecatedSubpackageTag' => [],
            'deprecatedLinkTag'       => []
        ];

        if ($structure['docComment']) {
            $result = $this->docblockParser->parse($structure['docComment'], [
                DocblockParser::CATEGORY,
                DocblockParser::SUBPACKAGE,
                DocblockParser::LINK
            ], $structure['name']);

            if ($result['category']) {
                $docblockIssues['deprecatedCategoryTag'][] = [
                    'name'  => $structure['name'],
                    'line'  => $structure['startLine'],
                    'start' => $structure['startPosName'],
                    'end'   => $structure['endPosName']
                ];
            }

            if ($result['subpackage']) {
                $docblockIssues['deprecatedSubpackageTag'][] = [
                    'name'  => $structure['name'],
                    'line'  => $structure['startLine'],
                    'start' => $structure['startPosName'],
                    'end'   => $structure['endPosName']
                ];
            }

            if ($result['link']) {
                $docblockIssues['deprecatedLinkTag'][] = [
                    'name'  => $structure['name'],
                    'line'  => $structure['startLine'],
                    'start' => $structure['startPosName'],
                    'end'   => $structure['endPosName']
                ];
            }

            return $docblockIssues;
        }

        $classInfo = $this->getClassInfo($structure['fqcn']);

        if ($classInfo && !$classInfo['hasDocumentation']) {
            $docblockIssues['missingDocumentation'][] = [
                'name'  => $structure['name'],
                'line'  => $structure['startLine'],
                'start' => $structure['startPosName'],
                'end'   => $structure['endPosName']
            ];
        }

        return $docblockIssues;
    }

    /**
     * @param array $structure
     * @param array $method
     *
     * @return array
     */
    protected function analyzeMethodDocblock(array $structure, array $method)
    {
        if ($method['docComment']) {
            return $this->analyzeFunctionDocblock($method);
        }

        $docblockIssues = [
            'missingDocumentation' => []
        ];

        $classInfo = $this->getClassInfo($structure['fqcn']);

        if ($classInfo &&
            isset($classInfo['methods'][$method['name']]) &&
            !$classInfo['methods'][$method['name']]['hasDocumentation']
        ) {
            $docblockIssues['missingDocumentation'][] = [
                'name'  => $method['name'],
                'line'  => $method['startLine'],
                'start' => $method['startPosName'],
                'end'   => $method['endPosName']
            ];
        }

        return $docblockIssues;
    }

    /**
     * @param array $structure
     * @param array $property
     *
     * @return array
     */
    protected function analyzePropertyDocblock(array $structure, array $property)
    {
        $docblockIssues = [
            'varTagMissing'        => [],
            'missingDocumentation' => []
        ];

        if ($property['docComment']) {
            $result = $this->docblockParser->parse($property['docComment'], [DocblockParser::VAR_TYPE], $property['name']);

            if (!isset($result['var']['$' . $property['name']]['type'])) {
                $docblockIssues['varTagMissing'][] = [
                    'name'  => $property['name'],
                    'line'  => $property['startLine'],
                    'start' => $property['startPosName'],
                    'end'   => $property['endPosName']
                ];
            }
        } else {
            $classInfo = $this->getClassInfo($structure['fqcn']);

            if ($classInfo &&
                isset($classInfo['properties'][$property['name']]) &&
                !$classInfo['properties'][$property['name']]['hasDocumentation']
            ) {
                $docblockIssues['missingDocumentation'][] = [
                    'name'  => $property['name'],
                    'line'  => $property['startLine'],
                    'start' => $property['startPosName'],
                    'end'   => $property['endPosName']
                ];
            }
        }

        return $docblockIssues;
    }

    /**
     * @param array $structure
     * @param array $constant
     *
     * @return array
     */
    protected function analyzeClassConstantDocblock(array $structure, array $constant)
    {
        $docblockIssues = [
            'varTagMissing'        => [],
            'missingDocumentation' => []
        ];

        if ($constant['docComment']) {
            $result = $this->docblockParser->parse($constant['docComment'], [DocblockParser::VAR_TYPE], $constant['name']);

            if (!isset($result['var']['$' . $constant['name']]['type'])) {
                $docblockIssues['varTagMissing'][] = [
                    'name'  => $constant['name'],
                    'line'  => $constant['startLine'],
                    'start' => $constant['startPosName'],
                    'end'   => $constant['endPosName'] + 1
                ];
            }
        } else {
            $docblockIssues['missingDocumentation'][] = [
                'name'  => $constant['name'],
                'line'  => $constant['startLine'],
                'start' => $constant['startPosName'],
                'end'   => $constant['endPosName']
            ];
        }

        return $docblockIssues;
    }

    /**
     * @param array $function
     *
     * @return array
     */
    protected function analyzeFunctionDocblock(array $function)
    {
        $docblockIssues = [
            'missingDocumentation'  => [],
            'parameterMissing'      => [],
            'parameterTypeMismatch' => [],
            'superfluousParameter'  => []
        ];

        if (!$function['docComment']) {
            $docblockIssues['missingDocumentation'][] = [
                'name'  => $function['name'],
                'line'  => $function['startLine'],
                'start' => $function['startPosName'],
                'end'   => $function['endPosName']
            ];

            return $docblockIssues;
        }

        $result = $this->docblockParser->parse(
            $function['docComment'],
            [DocblockParser::DESCRIPTION, DocblockParser::PARAM_TYPE],
            $function['name']
        );

        if ($this->docblockAnalyzer->isFullInheritDocSyntax($result['descriptions']['short'])) {
            return $docblockIssues;
        }

        $keysFound = [];
        $docblockParameters = $result['params'];

        foreach ($function['parameters'] as $parameter) {
            $dollarName = '$' . $parameter['name'];

            if (isset($docblockParameters[$dollarName])) {
                $keysFound[] = $dollarName;
            }

            if (!isset($docblockParameters[$dollarName])) {
                $docblockIssues['parameterMissing'][] = [
                    'name'      => $function['name'],
                    'parameter' => $dollarName,
                    'line'      => $function['startLine'],
                    'start'     => $function['startPosName'],
                    'end'       => $function['endPosName']
                ];
            } elseif ($parameter['type']) {
                $docblockType = $docblockParameters[$dollarName]['type'];

                $parameterType = $parameter['type'];

                if ($parameter['isVariadic']) {
                    $parameterType .= '[]';
                }

                if (!$this->typeAnalyzer->isTypeConformantWithDocblockType($parameterType, $docblockType)) {
                    $docblockIssues['parameterTypeMismatch'][] = [
                        'name'      => $function['name'],
                        'parameter' => $dollarName,
                        'line'      => $function['startLine'],
                        'start'     => $function['startPosName'],
                        'end'       => $function['endPosName']
                    ];
                }
            }
        }

        $superfluousParameterNames = array_values(array_diff(array_keys($docblockParameters), $keysFound));

        if (!empty($superfluousParameterNames)) {
            $docblockIssues['superfluousParameter'][] = [
                'name'       => $function['name'],
                'parameters' => $superfluousParameterNames,
                'line'       => $function['startLine'],
                'start'      => $function['startPosName'],
                'end'        => $function['endPosName']
            ];
        }

        return $docblockIssues;
    }

    /**
     * @param string $fqcn
     *
     * @return array|null
     */
    protected function getClassInfo($fqcn)
    {
        if (!isset($classCache[$fqcn])) {
            try {
                $classCache[$fqcn] = $this->classlikeInfoBuilder->getClasslikeInfo($fqcn);
            } catch (UnexpectedValueException $e) {
                $classCache[$fqcn] = null;
            }
        }

        return $classCache[$fqcn];
    }
}
