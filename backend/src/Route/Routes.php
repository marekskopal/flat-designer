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

	case AdminUsers = '/api/admin/users';
	case AdminUser = '/api/admin/users/{userId:number}';

	case Flats = '/api/flats';
	case FlatScene = '/api/flats/{flatId:number}/scene';
}
