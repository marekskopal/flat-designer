<?php

declare(strict_types=1);

namespace FlatDesigner\Route;

enum Routes: string
{
	case Health = '/api/health';

	case AuthenticationLogin = '/api/authentication/login';
	case AuthenticationSignUp = '/api/authentication/sign-up';
	case AuthenticationRefreshToken = '/api/authentication/refresh-token';

	case CurrentUser = '/api/current-user';
	case CurrentUserPassword = '/api/current-user/password';

	case Materials = '/api/materials';
	case ItemKinds = '/api/item-kinds';
	case RealtimeSubscription = '/api/realtime/subscription';

	case Mcp = '/mcp';
	case OAuthServerMetadata = '/.well-known/oauth-authorization-server/mcp';
	case OAuthServerMetadataRoot = '/.well-known/oauth-authorization-server';
	case OAuthResourceMetadata = '/.well-known/oauth-protected-resource/mcp';
	case OAuthResourceMetadataRoot = '/.well-known/oauth-protected-resource';
	case OAuthToken = '/mcp/oauth/token';
	case OAuthRegister = '/mcp/oauth/register';
	case OAuthClientInfo = '/api/oauth/client-info';
	case OAuthAuthorize = '/api/oauth/authorize';
	case CurrentUserMcpClients = '/api/current-user/mcp-clients';
	case CurrentUserMcpClientRevoke = '/api/current-user/mcp-clients/{clientId:[a-f0-9]+}/revoke';

	case AdminUsers = '/api/admin/users';
	case AdminUser = '/api/admin/users/{userId:number}';

	case Projects = '/api/projects';
	case Project = '/api/projects/{projectId:number}';
	case ProjectFloors = '/api/projects/{projectId:number}/floors';
	case ProjectFloor = '/api/projects/{projectId:number}/floors/{floorId:number}';
	case ProjectFloorScene = '/api/projects/{projectId:number}/floors/{floorId:number}/scene';
	case ProjectFloorElements = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms}';
	case ProjectFloorElement = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms|groups|variant-sets}/{elementId:number}';
	case ProjectFloorElementDuplicate = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms|groups|variant-sets}/{elementId:number}/duplicate';
	case ProjectFloorGroups = '/api/projects/{projectId:number}/floors/{floorId:number}/groups';
	case ProjectFloorGroupMove = '/api/projects/{projectId:number}/floors/{floorId:number}/groups/{groupId:number}/move';
	case ProjectFloorGroupUngroup = '/api/projects/{projectId:number}/floors/{floorId:number}/groups/{groupId:number}/ungroup';
	case ProjectFloorVariantSets = '/api/projects/{projectId:number}/floors/{floorId:number}/variant-sets';
	case ProjectFloorVariantSetVariants = '/api/projects/{projectId:number}/floors/{floorId:number}/variant-sets/{setId:number}/variants';
	case ProjectFloorVariantSetSwitch = '/api/projects/{projectId:number}/floors/{floorId:number}/variant-sets/{setId:number}/switch';
	case ProjectChanges = '/api/projects/{projectId:number}/changes';
	case ProjectUndo = '/api/projects/{projectId:number}/undo';
	case ProjectRedo = '/api/projects/{projectId:number}/redo';
	case ProjectVersions = '/api/projects/{projectId:number}/versions';
	case ProjectVersion = '/api/projects/{projectId:number}/versions/{versionId:number}';
	case ProjectVersionFloorScene = '/api/projects/{projectId:number}/versions/{versionId:number}/floors/{floorId:number}/scene';
	case ProjectFiles = '/api/projects/{projectId:number}/files';
	case ProjectFile = '/api/projects/{projectId:number}/files/{fileId:number}';
	case ProjectFileContent = '/api/projects/{projectId:number}/files/{fileId:number}/content';
	case ProjectVersionRestore = '/api/projects/{projectId:number}/versions/{versionId:number}/restore';
}
