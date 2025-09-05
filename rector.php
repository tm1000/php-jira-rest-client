<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassConst\AddTypeToConstRector;
use Rector\Php80\Rector\FuncCall\ClassOnObjectRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/tests',
		__DIR__ . '/src',
		//__DIR__ . '/routes',
		//__DIR__ . '/tools',
		//__DIR__ . '/workbench',
	])
	->withSkip([
		//__DIR__ . '/src/_ide_helper.php',
		AddTypeToConstRector::class, //Unsupported in Prettier
		ClassOnObjectRector::class, //Unsupported in Prettier
		NewMethodCallWithoutParenthesesRector::class, //Unsupported in Prettier
	])
	->withPhpSets();
