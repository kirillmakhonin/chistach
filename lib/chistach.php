<?php

define('CHISTACH_ROOT', dirname(__FILE__));
define('CHISTACH', true);


function getRelativePath(string $base, string $path)
{
    $base = array_slice(explode(DIRECTORY_SEPARATOR, rtrim($base, DIRECTORY_SEPARATOR)),1);
    $path = array_slice(explode(DIRECTORY_SEPARATOR, rtrim($path, DIRECTORY_SEPARATOR)),1);

    return '.' . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_slice($path, count($base)));
}

/**
 * Get all files with .php extension in path (recursive)
 * @param string $path path to directory for search
 * @return array array of file paths
 */
function get_php_files(string $path)
{
    $matches = [];
    $folders = [rtrim($path, DIRECTORY_SEPARATOR)];

    while( $folder = array_shift($folders) )
    {
        $matches = array_merge($matches, glob($folder . DIRECTORY_SEPARATOR . '*.php', GLOB_MARK));
        $childFolders = glob($folder . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        $folders = array_merge($folders, $childFolders);
    }
    return $matches;
}

/**
 * Class CodeBlockElement
 * Base class for all parsed code elements
 * Contains:
 *  - no. of line
 *  - relative path to file (from project directory)
 */
class CodeBlockElement
{
    private $lineNo;
    private $filePath;

    public function __construct(string $filePath, int $lineNo)
    {
        $this->filePath = $filePath;
        $this->lineNo = $lineNo;
    }

    /**
     * @return string
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }


    /**
     * @return int
     */
    public function getLineNo(): int
    {
        return $this->lineNo;
    }
}

/**
 * Class FunctionCall
 * Uses for store information about functions call operations
 * Contains:
 * - target function name (only name)
 */
class FunctionCall extends CodeBlockElement implements JsonSerializable
{
    private $targetFunctionName;

    public function __construct(string $filePath, int $lineNo, $targetFunctionName)
    {
        parent::__construct($filePath, $lineNo);
        $this->targetFunctionName = $targetFunctionName;
    }

    /**
     * @return string
     */
    public function getTargetFunctionName()
    {
        return $this->targetFunctionName;
    }

    public function jsonSerialize()
    {
        return [
            'type' => 'FunctionCall',
            'targetFunctionName' => $this->getTargetFunctionName(),
            'lineNo' => $this->getLineNo(),
        ];
    }

    public static function build($relativeFilePath, $serializedObject){
        return new FunctionCall($relativeFilePath,
            $serializedObject['lineNo'],
            $serializedObject['targetFunctionName']);
    }
}

/**
 * Class FunctionDeclaration
 * Uses for store information about functions declarations
 * Contains:
 *  - function name
 *  - nested function declarations
 *  - nested function calls
 */
class FunctionDeclaration extends CodeBlockElement implements JsonSerializable
{
    private $functionName;
    private $functionCalls;
    private $functionDeclarations;

    public function __construct(string $filePath, int $lineNo, $functionName)
    {
        parent::__construct($filePath, $lineNo);
        $this->functionName = $functionName;
        $this->functionCalls = [];
        $this->functionDeclarations = [];
    }

    public function addFunctionCall($functionCall)
    {
        $this->functionCalls[] = $functionCall;
    }

    public function addFunctionDeclaration($functionDeclaration)
    {
        $this->functionDeclarations[] = $functionDeclaration;
    }

    /**
     * @return array
     */
    public function getFunctionCalls(): array
    {
        return $this->functionCalls;
    }

    /**
     * @return array
     */
    public function getFunctionDeclarations(): array
    {
        return $this->functionDeclarations;
    }

    /**
     * @return string
     */
    public function getFunctionName()
    {
        return $this->functionName;
    }

    /**
     * @return array
     */
    public function getAllInnerFunctionCalls()
    {
        $allFunctionCalls = [];

        foreach ($this->functionCalls as $functionCall)
        {
            $allFunctionCalls[] = $functionCall;
        }

        foreach ($this->functionDeclarations as $functionDeclaration)
        {
            $allFunctionCalls = array_merge($allFunctionCalls, $functionDeclaration->getAllInnerFunctionCalls());
        }

        return $allFunctionCalls;
    }

    public function jsonSerialize()
    {
        return [
            'type' => 'FunctionDeclaration',
            'functionName' => $this->getFunctionName(),
            'functionCalls' => $this->getFunctionCalls(),
            'functionDeclarations' => $this->getFunctionDeclarations(),
            'lineNo' => $this->getLineNo(),
        ];
    }

    public function getSignature()
    {
        return $this->getFunctionName();
    }

    public static function build($relativeFilePath, $serializedObject){
        $instance =  new FunctionDeclaration($relativeFilePath,
            $serializedObject['lineNo'],
            $serializedObject['functionName']);

        foreach ($serializedObject['functionCalls'] as $functionCall)
            $instance->addFunctionCall(FunctionCall::build($relativeFilePath, $functionCall));

        foreach ($serializedObject['functionDeclarations'] as $functionDeclaration)
            $instance->addFunctionDeclaration(FunctionDeclaration::build($relativeFilePath, $functionDeclaration));

        return $instance;
    }

}

/**
 * Class ImportedElement
 *
 * Uses for store information about import operations
 * Contains:
 *  - target path (which will be imported)
 */
class ImportedElement extends CodeBlockElement implements JsonSerializable
{
    private $targetPath;

    public function __construct(string $filePath, int $lineNo, $targetPath)
    {
        parent::__construct($filePath, $lineNo);
        $this->targetPath = trim($targetPath, "\"'");
    }

    /**
     * @return string
     */
    public function getTargetPath()
    {
        return $this->targetPath;
    }

    public function jsonSerialize()
    {
        return [
            'type' => 'ImportedElement',
            'targetPath' => $this->getTargetPath(),
            'lineNo' => $this->getLineNo(),
        ];
    }

    public static function build($relativeFilePath, $serializedObject){
        return new ImportedElement($relativeFilePath,
            $serializedObject['lineNo'],
            $serializedObject['targetPath']);
    }
}

/**
 * Class ParseConfiguration
 * Configuration
 */
class ParseConfiguration
{
    public $cacheDirectory = '.chistach';
    public $verbose = false;
    public $multiFile = false;
}

/**
 * Class FileInformation
 * Stores information about one file
 * Contains:
 *  - relative path to file (from project root)
 *  - functions calls
 *  - functions declarations
 *  - functions imports
 */
class FileInformation implements JsonSerializable
{

    public static function buildFromSourceFile(string $projectDirectory, string $pathToFile, ParseConfiguration $config) : FileInformation
    {
        $analyzer = new TokenAnalyzer($projectDirectory, $pathToFile);
        $analyzer->parse();
        return $analyzer->getFileInformation();
    }

    public static function getCachedVersionTime(string $projectDirectory, string $pathToFile, ParseConfiguration $config)
    {
        $cachedFilePath = self::getCacheFileLocation($projectDirectory, $pathToFile, $config);
        if (!file_exists($cachedFilePath) || !is_readable($cachedFilePath))
            return null;

        $cachedVersionTime = @filemtime($cachedFilePath);
        if (!is_integer($cachedVersionTime))
            return null;

        return $cachedVersionTime;
    }

    public static function isNeedToRebuildCacheFile(string $projectDirectory, string $pathToFile, ParseConfiguration $config)
    {
        $currentFileModificationTime = @filemtime($pathToFile);
        if (!is_integer($currentFileModificationTime))
            throw new Exception("Cannot get time of last modification for file $pathToFile");

        $cachedVersionTime = self::getCachedVersionTime($projectDirectory, $pathToFile, $config);
        if (is_null($cachedVersionTime))
            return true;

        return $currentFileModificationTime > $cachedVersionTime;
    }

    public static function cache(string $projectDirectory, string $pathToFile, ParseConfiguration $config)
    {

        if (self::isNeedToRebuildCacheFile($projectDirectory, $pathToFile, $config)) {
            $fileInformation = self::buildFromSourceFile($projectDirectory, $pathToFile, $config);
            $fileLocation = self::getCacheFileLocation($projectDirectory, $pathToFile, $config);
            $fileInformation->save($fileLocation);
        }
    }

    public static function getCacheFileLocation(string $projectDirectory, string $pathToFile, ParseConfiguration $config)
    {
        $relativePathToFile = getRelativePath($projectDirectory, $pathToFile);
        $cachedPath =   $projectDirectory . DIRECTORY_SEPARATOR .
                        $config->cacheDirectory . DIRECTORY_SEPARATOR .
                        $relativePathToFile . '.json';

        return $cachedPath;
    }

    private $relativePath;

    private $functionDeclarations;
    private $functionCalls;
    private $imports;

    public function __construct($relativePath, $functionDeclarations, $functionCalls, $imports)
    {
        $this->functionDeclarations = $functionDeclarations;
        $this->functionCalls = $functionCalls;
        $this->imports = $imports;
    }

    public function jsonSerialize()
    {
        return [
            'root' => true,
            'functionDeclarations' => $this->functionDeclarations,
            'functionCalls' => $this->functionCalls,
            'imports' => $this->imports,
        ];
    }

    public static function load(string $projectDirectory, string $pathToFile, ParseConfiguration $config)
    {
        $cachedPath = self::getCacheFileLocation($projectDirectory, $pathToFile, $config);

        if (!file_exists($cachedPath) || !is_readable($cachedPath))
            throw new Exception("Cannot read cache file: $cachedPath");

        $relativePathToFile = getRelativePath($projectDirectory, $pathToFile);
        $cachedFileContent = @json_decode(file_get_contents($cachedPath), true);

        if (!is_array($cachedFileContent))
            throw new Exception("Corrupted cache file: $cachedPath");

        $functionDeclarations = [];
        $functionCalls = [];
        $imports = [];

        foreach ($cachedFileContent['functionDeclarations'] as $functionDeclaration)
            $functionDeclarations[] = FunctionDeclaration::build($relativePathToFile, $functionDeclaration);

        foreach ($cachedFileContent['functionCalls'] as $functionCall)
            $functionCalls[] = FunctionCall::build($relativePathToFile, $functionCall);

        foreach ($cachedFileContent['imports'] as $importedElement)
            $imports[] = ImportedElement::build($relativePathToFile, $importedElement);

        return new FileInformation($relativePathToFile, $functionDeclarations, $functionCalls, $imports);
    }

    public function save($path)
    {
        $directory = dirname($path);
        if (!file_exists($directory))
            @mkdir($directory, 0777, true);
        if (!file_exists($directory) || !is_writeable($directory))
            throw new Exception("Cannot create directory for cache: $directory");

        file_put_contents($path, json_encode($this, JSON_PRETTY_PRINT));
    }

    /**
     * @return string
     */
    public function getRelativePath()
    {
        return $this->relativePath;
    }

    /**
     * @return array
     */
    public function getFunctionDeclarations()
    {
        return $this->functionDeclarations;
    }

    /**
     * @return array
     */
    public function getFunctionCalls()
    {
        return $this->functionCalls;
    }

    /**
     * @return array
     */
    public function getImports()
    {
        return $this->imports;
    }

}

/**
 * Class TokenAnalyzer
 * Uses for token-analyzing of php files
 */
class TokenAnalyzer
{
    private $projectDirectory;
    private $file;
    private $relativePath;

    private $parseConfiguration;

    private $tokens;
    private $currentToken;
    private $stack;

    private $functionDeclarations;
    private $functionCalls;
    private $imports;

    private $isParsed;

    public function __construct($projectDirectory, $pathToFile, ParseConfiguration $parseConfiguration = null)
    {
        $this->file = $pathToFile;
        $this->projectDirectory = $projectDirectory;
        $this->relativePath = getRelativePath($projectDirectory, $pathToFile);
        $this->parseConfiguration = $parseConfiguration;

        $this->reset();
    }

    private function reset()
    {
        $this->stack = [];

        $this->functionCalls = [];
        $this->functionDeclarations = [];
        $this->imports = [];

        $this->isParsed = false;
    }

    public function parse()
    {
        $this->tokenize();
        $this->reset();

        $lastString = null;
        $lastFunction = null;
        $lastLine = 0;

        do
        {
            if ($this->tokenIsImport())
            {
                $this->nextToken();
                $this->readNonCode();

                if (!$this->tokenIsConstantString())
                    throw new Exception('Cannot find file for import. Find: ' . $this->getTokenText());

                $fileName = $this->getTokenText();
                $fileName = trim($fileName, "\"'");

                $function = new ImportedElement($this->relativePath, $this->getLine(), $fileName);
                $this->registerImport($function);

            }
            else if ($this->tokenIsFunction())
            {
                $this->nextToken();
                $this->readNonCode();

                if (!$this->tokenIsString())
                    throw new Exception('Cannot find function name after `function`. Find: ' . $this->getTokenText());

                $functionName = $this->getTokenText();

                $function = new FunctionDeclaration($this->relativePath, $this->getLine(), $functionName);
                $this->registerFunctionDeclaration($function);

                $lastFunction = $function;

            }
            else if ($this->tokenIsString())
            {
                $lastString = $this->getTokenText();
                $lastLine = $this->getLine();
            }
            else if ($this->tokenIsIndentation())
            {
                if ($this->tokenIsIndentationPlus())
                {
                    if (!is_null($lastFunction))
                    {
                        $this->indentationFunctionDeclaration($lastFunction);
                        $lastFunction = null;
                    }
                    else
                        $this->indentationAnother();
                }
                else
                    $this->indentationUp();
            }
            else if ($this->tokenIsOpenBracket())
            {
                if (!is_null($lastString))
                {
                    $function = new FunctionCall($this->relativePath, $lastLine, $lastString);
                    $this->registerFunctionCall($function);
                }
            }

            if (!$this->tokenIsNonCode() && !$this->tokenIsString())
                $lastString = null;

            $this->nextToken();
        }
        while ($this->hasNextToken());

        $this->isParsed = true;
    }

    public function getFileInformation(){
        if (!$this->isParsed)
            return null;

        return new FileInformation($this->relativePath, $this->functionDeclarations, $this->functionCalls, $this->imports);
    }

    private function registerFunctionDeclaration($function)
    {
        $lastDeclaration = $this->getLastFunctionDeclaration();

        if (is_null($lastDeclaration))
            $this->functionDeclarations[] = $function;
        else
            $lastDeclaration->addFunctionDeclaration($function);

    }

    private function registerFunctionCall($function)
    {
        $internalFunctions = get_defined_functions()['internal'];

        if (in_array($function->getTargetFunctionName(), $internalFunctions))
            return;

        $lastDeclaration = $this->getLastFunctionDeclaration();

        if (is_null($lastDeclaration))
            $this->functionCalls[] = $function;
        else
            $lastDeclaration->addFunctionCall($function);

    }

    private function registerImport($import)
    {
        $this->imports[] = $import;
    }

    private function indentationFunctionDeclaration($lastFunction)
    {
        array_push($this->stack, $lastFunction);
    }

    private function indentationAnother()
    {
        array_push($this->stack, null);
    }

    private function indentationUp()
    {
        if (count($this->stack) == 0)
            throw new Exception('Invalid indentation level');

        array_pop($this->stack);
    }

    private function getLastFunctionDeclaration()
    {
        if (count($this->stack) == 0)
            return null;

        for ($i = count($this->stack) - 1; $i >=0; $i--)
            if ($this->stack[$i] instanceof FunctionDeclaration)
                return $this->stack[$i];

        return null;
    }

    //=======================================================
    //             TOKENS OPERATIONS

    private function tokenize()
    {
        $file_contents = file_get_contents($this->file);
        if ( !$file_contents )
            throw new Exception('Cannot read file');

        $tokens = token_get_all($file_contents);
        if (!$tokens || count($tokens) == 0)
            throw new Exception('Cannot find any token');

        $this->tokens = $tokens;
        $this->currentToken = 0;
    }

    public function hasNextToken()
    {
        return $this->currentToken < count($this->tokens) - 1;
    }

    public function nextToken()
    {
        if (!$this->hasNextToken())
            throw new Exception('End of tokens');

        $this->currentToken++;
    }

    private function isFullToken()
    {
        return is_array($this->getToken());
    }

    private function getToken()
    {
        return $this->tokens[$this->currentToken];
    }

    private function getLine()
    {
        if ($this->isFullToken())
            return $this->getToken()[2];
        return null;
    }

    private function getTokenText()
    {
        if ($this->isFullToken())
            return $this->getToken()[1];
        return $this->getToken();
    }

    private function getTokenObject()
    {
        if ($this->isFullToken())
            return $this->getToken()[0];
        return null;
    }

    //=======================================================
    //             TOKENS OPERATIONS - CHECKS

    private function tokenIsImport()
    {
        return $this->isFullToken()
            && in_array($this->getTokenObject(), [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE]);
    }

    private function tokenIsFunction()
    {
        return $this->isFullToken() && $this->getTokenObject() == T_FUNCTION;
    }

    private function tokenIsString()
    {
        return $this->isFullToken() && $this->getTokenObject() == T_STRING;
    }

    private function tokenIsConstantString()
    {
        return $this->isFullToken() && $this->getTokenObject() == T_CONSTANT_ENCAPSED_STRING;
    }

    private function tokenIsNonCode()
    {
        if ($this->isFullToken())
            return in_array($this->getTokenObject(), [T_WHITESPACE, T_COMMENT]);

        return false;
    }

    private function tokenIsIndentation()
    {
        return $this->tokenIsIndentationMinus() || $this->tokenIsIndentationPlus();
    }

    private function tokenIsIndentationPlus()
    {
        return !$this->isFullToken() && $this->getTokenText() == '{';
    }

    private function tokenIsIndentationMinus()
    {
        return !$this->isFullToken() && $this->getTokenText() == '}';
    }

    private function tokenIsOpenBracket()
    {
        return !$this->isFullToken() && $this->getTokenText() == '(';
    }

    private function readNonCode()
    {
        while ($this->tokenIsNonCode() && $this->hasNextToken())
            $this->nextToken();
    }


}


/**
 * Class Analyzer
 * Main logic
 */
class Analyzer
{
    private $projectDirectory;
    private $startFile;
    private $parseConfiguration;

    private $functionDeclarationsMap;
    private $functionDeclarationsArray;
    private $functionDeclarationsUseFlagArray;
    private $functionCallsArray;
    private $functionCallIndexes;

    private $unusedFunctions;
    private $finished;

    public function __construct(string $projectDirectory, string $startFile, ParseConfiguration $parseConfiguration = null)
    {
        $this->projectDirectory = realpath($projectDirectory);
        $this->startFile = $this->projectDirectory . DIRECTORY_SEPARATOR . $startFile;

        if (!file_exists($this->startFile))
            throw new Exception("Cannot find start file: {$this->startFile}");

        if (!$parseConfiguration)
            $parseConfiguration = new ParseConfiguration();

        $this->parseConfiguration = $parseConfiguration;
        $this->finished = false;
    }

    private function checkDirectories()
    {
        if (!is_dir($this->projectDirectory) || !is_readable($this->projectDirectory))
            throw new Exception("$this->projectDirectory is not a readable directory");
    }

    private function buildInformationFiles()
    {
        $phpFiles = get_php_files($this->projectDirectory);
        foreach ($phpFiles as $file)
            FileInformation::cache($this->projectDirectory, $file, $this->parseConfiguration);
        @gc_collect_cycles();
    }

    private function load()
    {
        $this->functionDeclarationsMap = [];
        $this->functionDeclarationsArray = [];
        $this->functionDeclarationsUseFlagArray = [];
        $this->functionCallsArray = [];
        $this->functionCallIndexes = [];
        $this->unusedFunctions = [];

        $phpFiles = get_php_files($this->projectDirectory);
        foreach ($phpFiles as $file)
        {
            $information = FileInformation::load($this->projectDirectory, $file, $this->parseConfiguration);
            foreach ($information->getFunctionCalls() as $functionCall)
                $this->functionCallsArray[] = $functionCall;

            foreach ($information->getFunctionDeclarations() as $functionDeclaration){
                $this->functionDeclarationsArray[] = $functionDeclaration;
                $this->functionDeclarationsMap[$functionDeclaration->getSignature()] = count($this->functionDeclarationsArray) - 1;
            }
        }

        $this->functionDeclarationsUseFlagArray = array_fill(0, count( $this->functionDeclarationsArray), false);
    }

    private function markFunctionCallsAsUsed($functionDeclarationIndex)
    {
        if ($this->parseConfiguration->verbose)
        {
            $functionDeclaration = $this->functionDeclarationsArray[$functionDeclarationIndex];
            print("Analyze function decl. no. $functionDeclarationIndex: {$functionDeclaration->getFunctionName()}\n");
        }

        $stack = [];
        array_push($stack, $functionDeclarationIndex);

        while (count($stack) > 0)
        {
            $currentItemIndex = array_pop($stack);
            $functionCallsIndexes = $this->functionCallIndexes[$currentItemIndex];

            foreach ($functionCallsIndexes as $functionCallIndex)
            {
                $targetFunctionIndex = $functionCallIndex;

                if ($this->functionDeclarationsUseFlagArray[$targetFunctionIndex])
                    continue;
                else {
                    $this->functionDeclarationsUseFlagArray[$targetFunctionIndex] = true;

                    if ($this->parseConfiguration->verbose)
                    {
                        $functionDeclaration = $this->functionDeclarationsArray[$targetFunctionIndex];
                        print("Mark as used function decl. no. $targetFunctionIndex: {$functionDeclaration->getFunctionName()}\n");
                    }

                   array_push($stack, $targetFunctionIndex);
                }
            }
        }
    }

    private function analyzeUnused()
    {
        /**
         * Step 1. Iterate over functions that has been called in root scope, mark they as *USED*
         */
        foreach ($this->functionCallsArray as $functionCall)
        {
            $targetFunctionName = $functionCall->getTargetFunctionName();
            if (!isset($this->functionDeclarationsMap[$targetFunctionName]))
                throw new Exception("Call of undefined function $targetFunctionName");

            $targetFunctionIndex = $this->functionDeclarationsMap[$targetFunctionName];

            $this->functionDeclarationsUseFlagArray[$targetFunctionIndex] = true;

            if ($this->parseConfiguration->verbose)
            {
                $functionDeclaration = $this->functionDeclarationsArray[$targetFunctionIndex];
                print("Used from scope function decl. no. $targetFunctionIndex: {$functionDeclaration->getFunctionName()}\n");
            }
        }

        /**
         * Step 2. Iterate over all function declarations, build call map (functionCallIndexes)
         */
        foreach ($this->functionDeclarationsUseFlagArray as $index => $isCalled)
        {
            $functionDeclaration = $this->functionDeclarationsArray[$index];
            $functionCallsIndexes = [];

            foreach ($functionDeclaration->getAllInnerFunctionCalls() as $functionCall)
            {
                $targetFunctionName = $functionCall->getTargetFunctionName();
                if (!isset($this->functionDeclarationsMap[$targetFunctionName]))
                    throw new Exception("Call of undefined function $targetFunctionName in {$functionDeclaration->getFunctionName()}");

                $targetFunctionIndex = $this->functionDeclarationsMap[$targetFunctionName];

                $functionCallsIndexes[] = $targetFunctionIndex;
            }

            $this->functionCallIndexes[] = $functionCallsIndexes;
        }

        /**
         * Step 3. Recursive mark used functions (start from functions from step 1)
         */

        for ($i = 0; $i < count($this->functionDeclarationsUseFlagArray); $i++)
        {
            if ($this->functionDeclarationsUseFlagArray[$i])
                $this->markFunctionCallsAsUsed($i);
        }

        /**
         * Step 4. Build list of unusedFunctions
         */
        for ($i = 0; $i < count($this->functionDeclarationsUseFlagArray); $i++)
        {
            if (!$this->functionDeclarationsUseFlagArray[$i]){
                $functionInfo = $this->functionDeclarationsArray[$i];

                if (!$this->parseConfiguration->multiFile){
                    $startFileAbsolutePath = realpath($this->startFile);
                    $currentFileAbsolutePath = realpath($this->projectDirectory . DIRECTORY_SEPARATOR . $functionInfo->getFilePath());

                    if (realpath($startFileAbsolutePath) != realpath($currentFileAbsolutePath))
                        continue;
                }


                $this->unusedFunctions[] = $functionInfo;

                if ($this->parseConfiguration->verbose) {
                    print("Unused function {$functionInfo->getFunctionName()} on line {$functionInfo->getLineNo()}\n");
                }
            }
        }

    }

    public function getUnusedFunctions()
    {
        if (!$this->finished)
            die("Please analyze first");

        return $this->unusedFunctions;
    }

    public function generateGraphvizFile(string $outputFile)
    {
        if (!$this->finished)
            die("Please analyze first");

        $content = "digraph G {\n";
        $content .= "\tratio=fill; node[fontsize=24];\n";

        $content .= "\n";
        $content .= "\tENTRY [shape=diamond,style=filled,color=\"1.0 .3 1.0\"];\n";

        foreach ($this->functionCallsArray as $functionCall)
        {
            $content .= "\tENTRY->{$functionCall->getTargetFunctionName()};\n";
        }

        $fileMap = [];

        foreach ($this->functionDeclarationsArray as $functionDeclaration)
        {
            $file = $functionDeclaration->getFilePath();
            if (!isset($fileMap[$file]))
                $fileMap[$file] = [];

            $fileMap[$file][] = $functionDeclaration->getFunctionName();
        }

        foreach ($this->functionCallIndexes as $index => $calls)
        {
            $sourceFunction = $this->functionDeclarationsArray[$index];

            foreach ($calls as $call) {
                $targetFunction = $this->functionDeclarationsArray[$call];

                $content .= "\t{$sourceFunction->getFunctionName()}->{$targetFunction->getFunctionName()};\n";
            }

            $content .= "\n";
        }

        foreach ($fileMap as $filePath => $functionsNames)
        {
            $innerFunctions = implode('; ', $functionsNames);
            $content .= "\tsubgraph \"cluster_$filePath\" { label=\"$filePath\"; $innerFunctions; };\n";
        }


        $content .= "}";

        file_put_contents($outputFile, $content);
    }

    public function analyze()
    {
        $this->checkDirectories();
        $this->buildInformationFiles();
        $this->load();
        $this->analyzeUnused();

        $this->finished = true;
    }

}
