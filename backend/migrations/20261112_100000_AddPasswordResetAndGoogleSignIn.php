<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Password reset by email (one-time links stored as token hashes) and Sign in with Google: an account linked to a Google
 * account by its `google_id` (the token's `sub`) may have no password.
 */
final class AddPasswordResetAndGoogleSignInMigration extends Migration
{
	public function up(): void
	{
		$this->table('users')
			->addColumn('google_id', Type::String, nullable: true, default: null)
			->alterColumn('password', Type::String, nullable: true)
			->addIndex(['google_id'], 'users_google_id_unique', true)
			->alter();

		$this->table('password_reset_tokens')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('user_id', Type::Int, size: 11)
			->addColumn('token_hash', Type::String, size: 64)
			->addColumn('expires_at', Type::Timestamp)
			->addColumn('used_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['token_hash'], 'password_reset_tokens_token_hash_unique', true)
			->addIndex(['user_id'], 'password_reset_tokens_user_id_index', false)
			->addForeignKey('user_id', 'users', 'id', 'password_reset_tokens_user_id_fk')
			->create();
	}

	public function down(): void
	{
		$this->table('password_reset_tokens')->drop();

		$this->table('users')
			->dropIndex('users_google_id_unique')
			->dropColumn('google_id')
			->alterColumn('password', Type::String, nullable: false)
			->alter();
	}
}
