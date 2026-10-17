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

	case AdminUsers = '/api/admin/users';
	case AdminUser = '/api/admin/users/{userId:number}';

	case Projects = '/api/projects';
	case Project = '/api/projects/{projectId:number}';
	case ProjectFloors = '/api/projects/{projectId:number}/floors';
	case ProjectFloor = '/api/projects/{projectId:number}/floors/{floorId:number}';
	case ProjectFloorScene = '/api/projects/{projectId:number}/floors/{floorId:number}/scene';
	case ProjectFloorElements = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms}';
	case ProjectFloorElement = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms}/{elementId:number}';
	case ProjectFloorElementDuplicate = '/api/projects/{projectId:number}/floors/{floorId:number}/{elementType:items|walls|openings|rooms}/{elementId:number}/duplicate';
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
