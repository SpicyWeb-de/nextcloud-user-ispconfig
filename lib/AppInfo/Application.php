<?php
namespace OCA\User_ISPConfig\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
	public function __construct()
	{
		parent::__construct('user_ispconfig');
	}

	public function boot(IBootContext $context): void
	{
		$currentVersion = implode('.', \OCP\Util::getVersion());
		$isVersionGE27 = version_compare($currentVersion, '27.0.0', '>=');

		if ($isVersionGE27)
			\OC::$loader->addValidRoot(dirname(__DIR__));

		\OC::$CLASSPATH['OC_User_ISPCONFIG'] = 'user_ispconfig/lib/user_ispconfig.php';
	}

	public function register(IRegistrationContext $context): void
	{
	}
}
