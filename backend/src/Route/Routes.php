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
	case FurnitureDefinitions = '/api/furniture-definitions';
	case FurnitureDefinitionValidate = '/api/furniture-definitions/validate';
	case FurnitureDefinition = '/api/furniture-definitions/{definitionId:number}';
	case FurnitureDefinitionCopy = '/api/furniture-definitions/{definitionId:number}/copy';
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
	case ProjectFloorUnderlay = '/api/projects/{projectId:number}/floors/{floorId:number}/underlay';
	case ProjectFloorElements = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms|plots|stairs|roofs|roof-windows}';
	case ProjectFloorElement = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms|plots|stairs|roofs|roof-windows|groups|variant-sets}/{elementId:number}';
	case ProjectFloorElementDuplicate = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms|plots|stairs|roofs|roof-windows|groups|variant-sets}/{elementId:number}/duplicate';
	case ProjectFloorWallPolyline = '/api/projects/{projectId:number}/floors/{floorId:number}/walls/polyline';
	case ProjectFloorRoomDetect = '/api/projects/{projectId:number}/floors/{floorId:number}/rooms/detect';
	case ProjectFloorGroups = '/api/projects/{projectId:number}/floors/{floorId:number}/groups';
	case ProjectFloorGroupMove = '/api/projects/{projectId:number}/floors/{floorId:number}/groups/{groupId:number}/move';
	case ProjectFloorGroupUngroup = '/api/projects/{projectId:number}/floors/{floorId:number}/groups/{groupId:number}/ungroup';
	case ProjectFloorVariantSets = '/api/projects/{projectId:number}/floors/{floorId:number}/variant-sets';
	case ProjectFloorVariantSetVariants = '/api/projects/{projectId:number}/floors/{floorId:number}/variant-sets/{setId:number}/variants';
	case ProjectFloorVariantSetSwitch = '/api/projects/{projectId:number}/floors/{floorId:number}/variant-sets/{setId:number}/switch';
	case ProjectCutList = '/api/projects/{projectId:number}/cut-list';
	case ProjectFloorCutList = '/api/projects/{projectId:number}/floors/{floorId:number}/cut-list';
	case ProjectRoomSchedule = '/api/projects/{projectId:number}/room-schedule';
	case ProjectFloorRoomSchedule = '/api/projects/{projectId:number}/floors/{floorId:number}/room-schedule';
	case ProjectElectricalSchedule = '/api/projects/{projectId:number}/electrical-schedule';
	case ProjectFloorElectricalSchedule = '/api/projects/{projectId:number}/floors/{floorId:number}/electrical-schedule';
	case ProjectQuantities = '/api/projects/{projectId:number}/quantities';
	case ProjectFloorQuantities = '/api/projects/{projectId:number}/floors/{floorId:number}/quantities';
	case ProjectMaterialPrices = '/api/projects/{projectId:number}/material-prices';
	case ProjectChanges = '/api/projects/{projectId:number}/changes';
	case ProjectUndo = '/api/projects/{projectId:number}/undo';
	case ProjectRedo = '/api/projects/{projectId:number}/redo';
	case ProjectVersions = '/api/projects/{projectId:number}/versions';
	case ProjectVersion = '/api/projects/{projectId:number}/versions/{versionId:number}';
	case ProjectVersionFloorScene = '/api/projects/{projectId:number}/versions/{versionId:number}/floors/{floorId:number}/scene';
	case ProjectFiles = '/api/projects/{projectId:number}/files';
	case ProjectFile = '/api/projects/{projectId:number}/files/{fileId:number}';
	case ProjectFileContent = '/api/projects/{projectId:number}/files/{fileId:number}/content';
	case ProjectViews = '/api/projects/{projectId:number}/views';
	case ProjectView = '/api/projects/{projectId:number}/views/{viewId:number}';
	case ProjectComments = '/api/projects/{projectId:number}/comments';
	case ProjectComment = '/api/projects/{projectId:number}/comments/{commentId:number}';
	case ProjectShareLinks = '/api/projects/{projectId:number}/share-links';
	case ProjectShareLink = '/api/projects/{projectId:number}/share-links/{shareLinkId:number}';
	case ProjectShareLinkRefresh = '/api/projects/{projectId:number}/share-links/{shareLinkId:number}/refresh';

	/** Public: a guest opening a share link. */
	case Share = '/api/shares/{token:[a-f0-9]+}';
	case ShareModelContent = '/api/shares/{token:[a-f0-9]+}/models/{modelId:number}/content';
	case SharePictureContent = '/api/shares/{token:[a-f0-9]+}/pictures/{fileId:number}/content';
	case ShareComments = '/api/shares/{token:[a-f0-9]+}/comments';
	case Models = '/api/models';
	case Model = '/api/models/{modelId:number}';
	case ModelContent = '/api/models/{modelId:number}/content';
	case Upload = '/api/uploads/{token:[a-f0-9]+}';
	case ProjectVersionRestore = '/api/projects/{projectId:number}/versions/{versionId:number}/restore';
}
