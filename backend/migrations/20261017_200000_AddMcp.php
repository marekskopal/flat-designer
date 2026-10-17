<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * MCP server: OAuth 2.1 clients (dynamic registration) and their authorizations (codes and opaque
 * tokens, stored as SHA-256 hashes), plus the MCP transport's sessions.
 */
final class AddMcpMigration extends Migration
{
	public function up(): void
	{
		$this->table('oauth_clients')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('client_id', Type::String, size: 128)
			->addColumn('client_name', Type::String)
			->addColumn('redirect_uris', Type::Text)
			->addColumn('user_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['client_id'], 'oauth_clients_client_id_unique', true)
			->addForeignKey('user_id', 'users', 'id', 'oauth_clients_user_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('oauth_authorizations')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('client_id', Type::String, size: 128)
			->addColumn('user_id', Type::Int, size: 11)
			->addColumn('authorization_code_hash', Type::String, size: 64, nullable: true)
			->addColumn('code_challenge', Type::String, nullable: true)
			->addColumn('code_challenge_method', Type::String, size: 10, nullable: true)
			->addColumn('redirect_uri', Type::Text, nullable: true)
			->addColumn('access_token_hash', Type::String, size: 64, nullable: true)
			->addColumn('refresh_token_hash', Type::String, size: 64, nullable: true)
			->addColumn('access_token_expires', Type::Int, nullable: true)
			->addColumn('refresh_token_expires', Type::Int, nullable: true)
			->addColumn('code_expires', Type::Int, nullable: true)
			->addColumn('revoked', Type::Boolean, default: false)
			->addColumn('family_id', Type::String, size: 32, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['authorization_code_hash'], 'oauth_authorizations_code_hash_index', false)
			->addIndex(['access_token_hash'], 'oauth_authorizations_access_token_hash_index', false)
			->addIndex(['refresh_token_hash'], 'oauth_authorizations_refresh_token_hash_index', false)
			->addIndex(['family_id'], 'oauth_authorizations_family_id_index', false)
			->addIndex(['user_id', 'client_id'], 'oauth_authorizations_user_id_client_id_index', false)
			->addForeignKey('user_id', 'users', 'id', 'oauth_authorizations_user_id_fk')
			->create();

		$this->table('mcp_sessions')
			->addColumn('id', Type::String, size: 36, primary: true)
			->addColumn('data', Type::LongText)
			->addColumn('expires_at', Type::Int)
			->addIndex(['expires_at'], 'mcp_sessions_expires_at_index', false)
			->create();
	}

	public function down(): void
	{
		$this->table('mcp_sessions')->drop();
		$this->table('oauth_authorizations')->drop();
		$this->table('oauth_clients')->drop();
	}
}
