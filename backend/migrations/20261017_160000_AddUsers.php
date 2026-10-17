<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Accounts. The first admin is created with `bin/console admin:create` (`make admin`). */
final class AddUsersMigration extends Migration
{
	public function up(): void
	{
		$this->table('users')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('email', Type::String)
			->addColumn('password', Type::String)
			->addColumn('name', Type::String)
			->addColumn('role', Type::Enum, enum: ['Admin', 'User'], default: 'User')
			->addColumn('failed_login_attempts', Type::Int, default: 0)
			->addColumn('token_version', Type::Int, default: 0)
			->addColumn('locked_until', Type::Timestamp, nullable: true)
			->addColumn('last_login_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['email'], 'users_email_unique', true)
			->create();
	}

	public function down(): void
	{
		$this->table('users')->drop();
	}
}
