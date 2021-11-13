<?php

declare(strict_types=1);

namespace OCA\UserISPConfig\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version0049Date20211023151735 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('users_ispconfig')) {
			$table = $schema->createTable('users_ispconfig');
			$table->addColumn('uid', 'string', [
				'notnull' => true,
				'length' => 64,
				'default' => '',
			]);
			$table->addColumn('displayname', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('mailbox', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('domain', 'string', [
				'notnull' => false,
				'length' => 64,
			]);
			$table->setPrimaryKey(['uid']);
			$table->addUniqueIndex(['mailbox', 'domain'], 'mailaddress_unique');
		}
		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
